<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminLoginController;
use App\Http\Controllers\Admin\AdminSecurityController;
use App\Models\Admin;
use App\Services\Admin\TwoFactorAuth;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The account security page (امنیت حساب من) and the login second step.
 *
 * Port of the fork's security.php and verify_2fa.php / cancel_2fa.php.
 * Enabling 2FA needs a live code from the app, so the tests mint the
 * expected code from the stored secret with the service's own codeAt()
 * helper — one implementation, no clock mock. The routes are registered
 * here exactly as routes/web.php will carry them, so this file does not
 * have to edit the shared route file.
 */
class AdminSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
        ]);

        Http::preventStrayRequests();

        // Stand-ins for the routes/web.php entries the parent adds: same
        // names, same verbs, same middleware, skipped when the real ones
        // are already loaded.
        if (! Route::has('admin.security.show')) {
            Route::match(['get', 'post'], 'admin/security', AdminSecurityController::class)
                ->middleware(['web', 'admin.auth', 'admin.role:admin,editor'])
                ->name('admin.security.show');
        }

        if (! Route::has('admin.login.2fa')) {
            Route::match(['get', 'post'], 'admin/login/2fa', [AdminLoginController::class, 'verify2fa'])
                ->middleware('web')
                ->name('admin.login.2fa');

            Route::get('admin/login/2fa/cancel', [AdminLoginController::class, 'cancelTwoFactor'])
                ->middleware('web')
                ->name('admin.login.2fa.cancel');
        }

        // Ad-hoc ->name() calls land in the route's action but not the
        // collection's name lookup; without this, route('admin.security.show')
        // throws although the route exists.
        Route::getRoutes()->refreshNameLookups();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function makeAdmin(): Admin
    {
        return Admin::query()->create([
            'email' => 'admin@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => 'admin',
        ]);
    }

    /**
     * Flip an admin into the 2FA-enabled state without going through the
     * setup dance — for the tests whose subject is what happens AFTER
     * 2FA is on.
     *
     * @return array{0: Admin, 1: string, 2: array{plain: list<string>, hashed_json: string}}
     */
    private function makeTwoFactorAdmin(): array
    {
        $admin = $this->makeAdmin();
        $secret = TwoFactorAuth::generateSecret();
        $backup = TwoFactorAuth::generateBackupCodes();

        $admin->forceFill([
            'totp_secret' => $secret,
            'totp_enabled' => true,
            'backup_codes' => $backup['hashed_json'],
        ])->save();

        return [$admin->fresh(), $secret, $backup];
    }

    private function currentCode(string $secret): string
    {
        return TwoFactorAuth::codeAt($secret, time());
    }

    // -----------------------------------------------------------------
    // The page
    // -----------------------------------------------------------------

    public function test_the_security_page_renders_for_a_logged_in_admin(): void
    {
        $this->actingAs($this->makeAdmin(), 'admin')
            ->get(route('admin.security.show'))
            ->assertOk()
            ->assertSee('امنیت حساب من')
            ->assertSee('احراز هویت دو مرحلهای برای این حساب فعال نیست')
            ->assertSee('فعالسازی 2FA');
    }

    public function test_a_guest_is_redirected_to_the_login_page(): void
    {
        $this->get(route('admin.security.show'))
            ->assertRedirect(route('admin.login'));
    }

    // -----------------------------------------------------------------
    // Setup: start + confirm
    // -----------------------------------------------------------------

    public function test_start_setup_parks_the_pending_secret_in_the_session(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.security.show'), ['action' => 'start_setup'])
            ->assertRedirect(route('admin.security.show'));

        $secret = session('2fa_setup_secret');

        $this->assertIsString($secret);
        $this->assertSame(32, strlen($secret));

        // The page shows the manual-entry key and the otpauth path, and
        // nothing has been persisted before a valid code proves the app
        // really holds the secret.
        $this->actingAs($admin, 'admin')
            ->get(route('admin.security.show'))
            ->assertOk()
            ->assertSee($secret)
            ->assertSee('otpauth://totp/', false);

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'totp_enabled' => 0,
            'totp_secret' => null,
        ]);
    }

    public function test_confirm_setup_with_a_valid_code_enables_two_factor_and_shows_backup_codes_once(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.security.show'), ['action' => 'start_setup']);

        $secret = (string) session('2fa_setup_secret');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.security.show'), [
                'action' => 'confirm_setup',
                'code' => $this->currentCode($secret),
            ])
            ->assertRedirect(route('admin.security.show'))
            ->assertSessionHas('success', 'احراز هویت دو مرحلهای با موفقیت فعال شد.');

        $fresh = Admin::query()->findOrFail($admin->id);

        $this->assertTrue((bool) $fresh->totp_enabled);
        $this->assertSame($secret, $fresh->totp_secret);
        $this->assertSame(8, TwoFactorAuth::remainingBackupCodesCount($fresh->backup_codes));
        // The pending secret leaves the session once it becomes real.
        $this->assertNull(session('2fa_setup_secret'));

        // The plain codes are flashed for exactly one render.
        $codes = session('new_backup_codes');
        $this->assertIsArray($codes);
        $this->assertCount(8, $codes);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.security.show'))
            ->assertOk()
            ->assertSee($codes[0])
            ->assertSee('احراز هویت دو مرحلهای فعال است')
            ->assertSee('8 کد پشتیبان باقیمانده');
    }

    public function test_confirm_setup_with_a_wrong_code_is_refused_with_the_fork_message(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.security.show'), ['action' => 'start_setup']);

        $secret = (string) session('2fa_setup_secret');
        $wrong = $this->currentCode($secret) === '000000' ? '999999' : '000000';

        $this->actingAs($admin, 'admin')
            ->post(route('admin.security.show'), [
                'action' => 'confirm_setup',
                'code' => $wrong,
            ])
            ->assertRedirect(route('admin.security.show'))
            ->assertSessionHas('error', 'کد وارد شده صحیح نیست. دوباره تلاش کنید.');

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'totp_enabled' => 0,
            'totp_secret' => null,
            'backup_codes' => null,
        ]);
    }

    // -----------------------------------------------------------------
    // Disable
    // -----------------------------------------------------------------

    public function test_disable_requires_the_correct_password(): void
    {
        [$admin, $secret] = $this->makeTwoFactorAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.security.show'), [
                'action' => 'disable',
                'password' => 'not-the-password',
            ])
            ->assertRedirect(route('admin.security.show'))
            ->assertSessionHas('error', 'رمز عبور نادرست است.');

        $fresh = Admin::query()->findOrFail($admin->id);
        $this->assertTrue((bool) $fresh->totp_enabled);
        $this->assertSame($secret, $fresh->totp_secret);
    }

    public function test_disable_with_the_correct_password_wipes_the_two_factor_state(): void
    {
        [$admin] = $this->makeTwoFactorAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.security.show'), [
                'action' => 'disable',
                'password' => 'secret-pass',
            ])
            ->assertRedirect(route('admin.security.show'))
            ->assertSessionHas('success', 'احراز هویت دو مرحلهای غیرفعال شد.');

        $this->assertDatabaseHas('admins', [
            'id' => $admin->id,
            'totp_enabled' => 0,
            'totp_secret' => null,
            'backup_codes' => null,
        ]);
    }

    // -----------------------------------------------------------------
    // Regenerating backup codes
    // -----------------------------------------------------------------

    public function test_regenerating_backup_codes_invalidates_the_old_ones(): void
    {
        [$admin, , $oldBackup] = $this->makeTwoFactorAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.security.show'), ['action' => 'regenerate_backup'])
            ->assertRedirect(route('admin.security.show'))
            ->assertSessionHas('success', 'کدهای پشتیبان جدید صادر شد. کدهای قبلی دیگر معتبر نیستند.');

        $fresh = Admin::query()->findOrFail($admin->id);
        $codes = session('new_backup_codes');

        $this->assertSame(8, TwoFactorAuth::remainingBackupCodesCount($fresh->backup_codes));
        $this->assertIsArray($codes);
        $this->assertNotSame($oldBackup['plain'][0], $codes[0]);

        // An old code no longer opens anything; a fresh one does, and is
        // consumed on the spot.
        $this->assertFalse(TwoFactorAuth::verifyAndConsumeBackupCode($fresh, $oldBackup['plain'][0]));
        $this->assertTrue(TwoFactorAuth::verifyAndConsumeBackupCode($fresh->refresh(), $codes[0]));
        $this->assertSame(7, TwoFactorAuth::remainingBackupCodesCount($fresh->refresh()->backup_codes));
    }

    // -----------------------------------------------------------------
    // Login: the second step
    // -----------------------------------------------------------------

    public function test_totp_login_is_ignored_when_two_factor_is_off(): void
    {
        $admin = $this->makeAdmin();

        $this->post(route('admin.login.attempt'), [
            'email' => 'admin@acme.test',
            'password' => 'secret-pass',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_a_valid_code_completes_the_login_and_issues_the_remember_cookie(): void
    {
        [$admin, $secret] = $this->makeTwoFactorAdmin();

        // The password alone opens no session — only the pending marker.
        $this->post(route('admin.login.attempt'), [
            'email' => 'admin@acme.test',
            'password' => 'secret-pass',
        ])->assertRedirect(route('admin.login.2fa'));

        $this->assertGuest('admin');
        $this->assertSame($admin->id, session('pending_admin_id'));

        // The code form renders instead of the password form.
        $this->get(route('admin.login.2fa'))
            ->assertOk()
            ->assertSee('Two-Factor Verification')
            ->assertSee('Use a backup code instead');

        $this->post(route('admin.login.2fa'), ['code' => $this->currentCode($secret)])
            ->assertRedirect(route('admin.dashboard'))
            ->assertCookie('token', $admin->token);

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertNull(session('pending_admin_id'));
    }

    public function test_a_wrong_code_is_refused_and_keeps_the_pending_state(): void
    {
        [$admin, $secret] = $this->makeTwoFactorAdmin();

        $this->post(route('admin.login.attempt'), [
            'email' => 'admin@acme.test',
            'password' => 'secret-pass',
        ])->assertRedirect(route('admin.login.2fa'));

        $wrong = $this->currentCode($secret) === '000000' ? '999999' : '000000';

        $this->post(route('admin.login.2fa'), ['code' => $wrong])
            ->assertRedirect(route('admin.login.2fa'))
            ->assertSessionHasErrors(['code' => 'کد وارد شده صحیح نیست.']);

        $this->assertGuest('admin');
        // The pending marker survives so the real code still works.
        $this->assertSame($admin->id, session('pending_admin_id'));

        $this->post(route('admin.login.2fa'), ['code' => $this->currentCode($secret)])
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_a_backup_code_completes_the_login_and_is_consumed(): void
    {
        [$admin, , $backup] = $this->makeTwoFactorAdmin();

        $this->post(route('admin.login.attempt'), [
            'email' => 'admin@acme.test',
            'password' => 'secret-pass',
        ])->assertRedirect(route('admin.login.2fa'));

        $this->post(route('admin.login.2fa'), [
            'code' => $backup['plain'][0],
            'use_backup' => '1',
        ])->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
        $this->assertSame(7, TwoFactorAuth::remainingBackupCodesCount(
            Admin::query()->findOrFail($admin->id)->backup_codes,
        ));
    }

    public function test_the_remembered_token_cookie_also_requires_the_second_factor(): void
    {
        [$admin] = $this->makeTwoFactorAdmin();

        Http::fake([
            'https://api.connectix.vip/v1/seller/seller-data' => Http::response([
                'data' => ['seller' => ['id' => 7]],
            ], 200),
        ]);

        // The cookie proves "this browser", not the second factor: the
        // panel accepts the token, yet no session opens without a code.
        $this->withCookie('token', $admin->token)
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.login.2fa'));

        $this->assertGuest('admin');
    }

    public function test_cancel_returns_to_the_login_page_and_drops_the_pending_state(): void
    {
        [$admin, $secret] = $this->makeTwoFactorAdmin();

        $this->post(route('admin.login.attempt'), [
            'email' => 'admin@acme.test',
            'password' => 'secret-pass',
        ])->assertRedirect(route('admin.login.2fa'));

        $this->get(route('admin.login.2fa.cancel'))
            ->assertRedirect(route('admin.login'));

        $this->assertNull(session('pending_admin_id'));
        $this->assertNull(session('pending_admin_remember'));

        // Without a pending marker there is nothing to verify: the code
        // form itself sends strays back to the login page.
        $this->get(route('admin.login.2fa'))
            ->assertRedirect(route('admin.login'));

        $this->post(route('admin.login.2fa'), ['code' => $this->currentCode($secret)])
            ->assertRedirect(route('admin.login'));

        $this->assertGuest('admin');
    }
}
