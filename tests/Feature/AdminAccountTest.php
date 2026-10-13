<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AdminActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The account page (ایمیل و رمز عبور) and the activity trail.
 *
 * The account page has no id parameter anywhere: the acting admin comes
 * from the session, so a posted id is dead weight and another admin's row
 * can never be reached. The activity page carries the fork's scope rule —
 * «هر ادمین فقط فعالیتهای خودش را میبیند» — verified by seeding two
 * admins' rows and checking each view shows exactly one side.
 */
class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.connectix.base_url' => 'https://api.connectix.vip',
            'connectix_bot.connectix.panel_url' => 'https://seller.connectix.vip',
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.broadcast.delay_us' => 0,
        ]);

        Http::preventStrayRequests();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function makeAdmin(AdminRole $role = AdminRole::Admin, string $password = 'secret-pass'): Admin
    {
        return Admin::query()->create([
            'email' => $role->value.'-'.uniqid().'@acme.test',
            'password' => $password,
            'token' => 'panel-token-'.uniqid(),
            'chat_id' => '10',
            'role' => $role,
        ]);
    }

    // -----------------------------------------------------------------
    // Account: render + email change
    // -----------------------------------------------------------------

    /** The page renders for both roles, titled the way the fork names it. */
    public function test_the_account_page_renders_for_admins_and_editors(): void
    {
        $this->actingAs($this->makeAdmin(AdminRole::Admin), 'admin')
            ->get(route('admin.account.show'))
            ->assertOk()
            ->assertSee('ایمیل و رمز عبور')
            ->assertSee('تغییر ایمیل')
            ->assertSee('تغییر رمز عبور');

        // An editor manages their own account through the same page.
        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->get(route('admin.account.show'))
            ->assertOk()
            ->assertSee('تغییر ایمیل');
    }

    /** A wrong current password fails the change and touches nothing. */
    public function test_an_email_change_with_the_wrong_current_password_fails(): void
    {
        $admin = $this->makeAdmin();
        $original = $admin->email;

        $this->actingAs($admin, 'admin')
            ->post(route('admin.account.show'), [
                'action' => 'email',
                'new_email' => 'new-address@acme.test',
                'current_password' => 'not-the-password',
            ])
            ->assertSessionHasErrors('current_password');

        $this->assertDatabaseHas('admins', ['id' => $admin->id, 'email' => $original]);
        $this->assertDatabaseMissing('admins', ['email' => 'new-address@acme.test']);
    }

    /** The right current password persists the new email, with the flash. */
    public function test_an_email_change_with_the_current_password_persists(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.account.show'), [
                'action' => 'email',
                'new_email' => 'new-address@acme.test',
                'current_password' => 'secret-pass',
            ])
            ->assertRedirect(route('admin.account.show'))
            ->assertSessionHas('success', 'اطلاعات حساب بهروزرسانی شد.');

        $this->assertDatabaseHas('admins', ['id' => $admin->id, 'email' => 'new-address@acme.test']);
    }

    /** A duplicate email is refused even with the right password. */
    public function test_an_email_taken_by_another_admin_is_refused(): void
    {
        $taken = $this->makeAdmin();
        $me = $this->makeAdmin();

        $this->actingAs($me, 'admin')
            ->post(route('admin.account.show'), [
                'action' => 'email',
                'new_email' => $taken->email,
                'current_password' => 'secret-pass',
            ])
            ->assertSessionHasErrors('new_email');

        $this->assertDatabaseHas('admins', ['id' => $me->id, 'email' => $me->email]);
    }

    /** A posted id is inert — another admin's row never moves. */
    public function test_a_posted_id_cannot_reach_another_admin(): void
    {
        $victim = $this->makeAdmin();
        $me = $this->makeAdmin();

        $this->actingAs($me, 'admin')
            ->post(route('admin.account.show'), [
                'action' => 'email',
                'id' => $victim->id, // ignored: the route takes no id
                'new_email' => 'moved@acme.test',
                'current_password' => 'secret-pass',
            ])
            ->assertRedirect(route('admin.account.show'));

        $this->assertDatabaseHas('admins', ['id' => $me->id, 'email' => 'moved@acme.test']);
        $this->assertDatabaseHas('admins', ['id' => $victim->id, 'email' => $victim->email]);
    }

    // -----------------------------------------------------------------
    // Account: password change
    // -----------------------------------------------------------------

    /** A password change re-hashes and swaps the verifiable secret. */
    public function test_a_password_change_rehashes_the_password(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.account.show'), [
                'action' => 'password',
                'current_password' => 'secret-pass',
                'new_password' => 'brand-new-pass-9',
                'new_password_confirmation' => 'brand-new-pass-9',
            ])
            ->assertRedirect(route('admin.account.show'))
            ->assertSessionHas('success', 'اطلاعات حساب بهروزرسانی شد.');

        $fresh = Admin::query()->findOrFail($admin->id);

        $this->assertTrue(Hash::check('brand-new-pass-9', (string) $fresh->password));
        $this->assertFalse(Hash::check('secret-pass', (string) $fresh->password));
        // The stored value is a hash, not the plaintext.
        $this->assertNotSame('brand-new-pass-9', (string) $fresh->password);
    }

    /** Short or unconfirmed passwords never reach the database. */
    public function test_a_short_or_unconfirmed_password_is_refused(): void
    {
        $admin = $this->makeAdmin();

        $this->actingAs($admin, 'admin')
            ->post(route('admin.account.show'), [
                'action' => 'password',
                'current_password' => 'secret-pass',
                'new_password' => 'short7c', // seven characters
                'new_password_confirmation' => 'short7c',
            ])
            ->assertSessionHasErrors('new_password');

        $this->actingAs($admin, 'admin')
            ->post(route('admin.account.show'), [
                'action' => 'password',
                'current_password' => 'secret-pass',
                'new_password' => 'long-enough-8',
                'new_password_confirmation' => 'different-value',
            ])
            ->assertSessionHasErrors('new_password');

        $fresh = Admin::query()->findOrFail($admin->id);
        $this->assertTrue(Hash::check('secret-pass', (string) $fresh->password));
    }

    /** An editor changes their own password through the same page. */
    public function test_an_editor_can_change_their_own_password(): void
    {
        $editor = $this->makeAdmin(AdminRole::Editor);

        $this->actingAs($editor, 'admin')
            ->post(route('admin.account.show'), [
                'action' => 'password',
                'current_password' => 'secret-pass',
                'new_password' => 'editor-new-pass-1',
                'new_password_confirmation' => 'editor-new-pass-1',
            ])
            ->assertRedirect(route('admin.account.show'))
            ->assertSessionHas('success', 'اطلاعات حساب بهروزرسانی شد.');

        $fresh = Admin::query()->findOrFail($editor->id);
        $this->assertTrue(Hash::check('editor-new-pass-1', (string) $fresh->password));
    }

    // -----------------------------------------------------------------
    // Activity: scope
    // -----------------------------------------------------------------

    /** Two admins, two trails: each page shows only its own owner's rows. */
    public function test_each_admin_sees_only_their_own_activity(): void
    {
        $alpha = $this->makeAdmin();
        $beta = $this->makeAdmin();

        AdminActivity::query()->create([
            'admin_email' => $alpha->email,
            'admin_id' => $alpha->id,
            'action' => 'admin_create',
            'subject_type' => 'admin',
            'subject_id' => '5',
            'details' => 'ALPHA-ONLY-ROW',
            'ip' => '10.0.0.1',
            'created_at' => now(),
        ]);
        AdminActivity::query()->create([
            'admin_email' => $beta->email,
            'admin_id' => $beta->id,
            'action' => 'admin_password',
            'subject_type' => 'admin',
            'subject_id' => '6',
            'details' => 'BETA-ONLY-ROW',
            'ip' => '10.0.0.2',
            'created_at' => now(),
        ]);

        $this->actingAs($alpha, 'admin')
            ->get(route('admin.activity.index'))
            ->assertOk()
            ->assertSee('ALPHA-ONLY-ROW')
            ->assertDontSee('BETA-ONLY-ROW')
            ->assertSee('ساخت ادمین'); // the fork's Persian caption for admin_create

        $this->actingAs($beta, 'admin')
            ->get(route('admin.activity.index'))
            ->assertOk()
            ->assertSee('BETA-ONLY-ROW')
            ->assertDontSee('ALPHA-ONLY-ROW');
    }

    /** An editor gets the same one-scope treatment on their own rows. */
    public function test_an_editor_sees_only_their_own_activity(): void
    {
        $editor = $this->makeAdmin(AdminRole::Editor);
        $other = $this->makeAdmin();

        AdminActivity::query()->create([
            'admin_email' => $editor->email,
            'action' => 'account_email',
            'details' => 'EDITOR-OWN-ROW',
            'ip' => '10.0.0.3',
            'created_at' => now(),
        ]);
        AdminActivity::query()->create([
            'admin_email' => $other->email,
            'action' => 'admin_create',
            'details' => 'OTHER-OWN-ROW',
            'ip' => '10.0.0.4',
            'created_at' => now(),
        ]);

        $this->actingAs($editor, 'admin')
            ->get(route('admin.activity.index'))
            ->assertOk()
            ->assertSee('EDITOR-OWN-ROW')
            ->assertDontSee('OTHER-OWN-ROW');
    }

    /** Searching narrows on the action key without widening the scope. */
    public function test_the_activity_search_filters_on_action(): void
    {
        $admin = $this->makeAdmin();

        AdminActivity::query()->create([
            'admin_email' => $admin->email,
            'action' => 'admin_create',
            'details' => 'CREATE-ROW',
            'created_at' => now(),
        ]);
        AdminActivity::query()->create([
            'admin_email' => $admin->email,
            'action' => 'account_password',
            'details' => 'PASSWORD-ROW',
            'created_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->get(route('admin.activity.index', ['search' => 'account_']))
            ->assertOk()
            ->assertSee('PASSWORD-ROW')
            ->assertDontSee('CREATE-ROW');

        // An empty result still renders the page, not an error.
        $this->actingAs($admin, 'admin')
            ->get(route('admin.activity.index', ['search' => 'no_such_action']))
            ->assertOk()
            ->assertSee('هنوز فعالیتی ثبت نشده است.');
    }
}
