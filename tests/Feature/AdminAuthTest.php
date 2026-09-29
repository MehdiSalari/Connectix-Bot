<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * The web administrator sign in, port of login.php / logout.php and the
 * session check that guarded every legacy panel page.
 *
 * Credentials are verified with bcrypt against the admins table, the session
 * opens on the `admin` guard, and the Connectix seller token is remembered in
 * a thirty day cookie that login.php re-validated against the panel.
 */
class AdminAuthTest extends TestCase
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
            'connectix_bot.active' => true,
            'connectix_bot.test_enabled' => true,
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
        ]);

        Http::preventStrayRequests();
    }

    /**
     * The panel reply to a remembered-token check. Only the two auto-login
     * tests need it, and they build the whole set so an override never sees a
     * stale stub from setUp.
     *
     * @param  array<string, mixed>  $seller
     */
    private function fakeSellerData(array $seller = ['id' => 7]): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/seller-data' => Http::response([
                'data' => ['seller' => $seller],
            ], 200),
        ]);
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function makeAdmin(AdminRole $role = AdminRole::Admin): Admin
    {
        return Admin::query()->create([
            'email' => 'admin@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => $role,
        ]);
    }

    private function credentials(): array
    {
        return [
            'email' => 'admin@acme.test',
            'password' => 'secret-pass',
        ];
    }

    // -----------------------------------------------------------------
    // The login form
    // -----------------------------------------------------------------

    public function test_the_login_page_renders_the_form(): void
    {
        $this->get(route('admin.login'))
            ->assertOk()
            ->assertSee('Acme VPN Login')
            ->assertSee('Email')
            ->assertSee('Login');
    }

    public function test_a_logged_in_admin_is_sent_away_from_the_login_page(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.dashboard'));
    }

    // -----------------------------------------------------------------
    // The dashboard gate
    // -----------------------------------------------------------------

    public function test_a_guest_is_sent_to_the_login_page(): void
    {
        $this->get(route('admin.dashboard'))
            ->assertRedirect(route('admin.login'));
    }

    public function test_the_editor_can_open_the_dashboard(): void
    {
        $admin = $this->makeAdmin(AdminRole::Editor);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('admin@acme.test')
            ->assertSee('ویرایشگر');
    }

    // -----------------------------------------------------------------
    // Signing in
    // -----------------------------------------------------------------

    public function test_valid_credentials_open_a_session_and_remember_the_token(): void
    {
        $admin = $this->makeAdmin();

        $this->post(route('admin.login.attempt'), $this->credentials())
            ->assertRedirect(route('admin.dashboard'))
            ->assertCookie('token', $admin->token);

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_signing_in_returns_to_the_page_that_was_requested(): void
    {
        $this->makeAdmin();

        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));

        $this->post(route('admin.login.attempt'), $this->credentials())
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_wrong_password_is_refused_with_the_legacy_message(): void
    {
        $this->makeAdmin();

        $this->followingRedirects()
            ->post(route('admin.login.attempt'), [
                'email' => 'admin@acme.test',
                'password' => 'nope',
            ])->assertOk()
            ->assertSee('Invalid email or password')
            ->assertSee('admin@acme.test');

        $this->assertGuest('admin');
    }

    public function test_an_unknown_email_is_refused_with_the_same_message(): void
    {
        $this->post(route('admin.login.attempt'), [
            'email' => 'ghost@acme.test',
            'password' => 'secret-pass',
        ])->assertRedirect(route('admin.login'))
            ->assertSessionHasErrors('credentials');

        $this->assertGuest('admin');
    }

    public function test_a_blank_email_or_password_is_refused(): void
    {
        $this->post(route('admin.login.attempt'), ['email' => '', 'password' => ''])
            ->assertSessionHasErrors(['email', 'password']);

        $this->assertGuest('admin');
    }

    // -----------------------------------------------------------------
    // The remembered token cookie
    // -----------------------------------------------------------------

    public function test_a_valid_token_cookie_logs_the_admin_in_after_panel_check(): void
    {
        $admin = $this->makeAdmin();

        $this->fakeSellerData();

        $this->withCookie('token', $admin->token)
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.dashboard'));

        $this->assertAuthenticatedAs($admin, 'admin');
    }

    public function test_a_token_the_panel_no_longer_accepts_is_dropped(): void
    {
        $admin = $this->makeAdmin();

        $this->fakeSellerData([]);

        $this->withCookie('token', $admin->token)
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.login'))
            ->assertCookieExpired('token');

        $this->assertGuest('admin');
    }

    public function test_a_token_of_a_removed_admin_is_dropped(): void
    {
        $this->withCookie('token', 'no-such-token')
            ->get(route('admin.login'))
            ->assertRedirect(route('admin.login'))
            ->assertCookieExpired('token', false);

        $this->assertGuest('admin');
    }

    /**
     * The cookie holds the seller token itself, so it must never be readable
     * from JavaScript and must carry the thirty day lifetime login.php set.
     */
    public function test_the_remembered_token_cookie_is_httponly_and_lasts_thirty_days(): void
    {
        $this->makeAdmin();

        $response = $this->post(route('admin.login.attempt'), $this->credentials());

        $cookie = $response->getCookie('token');

        $this->assertNotNull($cookie, 'the token cookie was not set');
        $this->assertTrue($cookie->isHttpOnly());
        // Written as 'Lax'; Symfony reports the normalized lower case.
        $this->assertSame('lax', strtolower((string) $cookie->getSameSite()));
        $this->assertSame('/', $cookie->getPath());

        $ttl = $cookie->getExpiresTime() - time();

        $this->assertGreaterThan(30 * 24 * 3600 - 60, $ttl);
        $this->assertLessThanOrEqual(30 * 24 * 3600, $ttl);
    }

    public function test_the_token_cookie_is_marked_secure_only_when_served_over_https(): void
    {
        $this->makeAdmin();

        $plain = $this->post(route('admin.login.attempt'), $this->credentials());

        $this->assertFalse($plain->getCookie('token')->isSecure());

        // The https URL is what makes the framework see a TLS request: a bare
        // HTTPS server variable is stripped again for an http URI.
        $secure = $this->post(
            str_replace('http://', 'https://', route('admin.login.attempt')),
            $this->credentials(),
        );

        $this->assertTrue($secure->getCookie('token')->isSecure());
    }

    public function test_the_session_cookie_is_httponly(): void
    {
        $this->makeAdmin();

        $response = $this->post(route('admin.login.attempt'), $this->credentials());

        $cookie = $response->getCookie((string) config('session.cookie'), false);

        $this->assertNotNull($cookie, 'the session cookie was not set');
        $this->assertTrue($cookie->isHttpOnly());
    }

    // -----------------------------------------------------------------
    // Signing out and roles
    // -----------------------------------------------------------------

    public function test_logout_clears_the_session_and_the_token_cookie(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.logout'))
            ->assertRedirect(route('admin.login'))
            ->assertCookieExpired('token');

        $this->assertGuest('admin');
    }

    public function test_the_role_gate_refuses_the_wrong_role(): void
    {
        Route::get('/admin/only-admin', fn (): string => 'ok')
            ->middleware('admin.role:admin')
            ->name('admin.only_admin');

        $editor = $this->makeAdmin(AdminRole::Editor);

        $this->actingAs($editor, 'admin')
            ->get('/admin/only-admin')
            ->assertForbidden();
    }

    public function test_the_role_gate_lets_the_admin_through(): void
    {
        Route::get('/admin/only-admin', fn (): string => 'ok')
            ->middleware('admin.role:admin')
            ->name('admin.only_admin');

        $admin = $this->makeAdmin(AdminRole::Admin);

        $this->actingAs($admin, 'admin')
            ->get('/admin/only-admin')
            ->assertOk();
    }
}
