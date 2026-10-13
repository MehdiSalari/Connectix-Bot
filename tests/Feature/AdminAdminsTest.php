<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Services\Panel\AdminPermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Administrator management: creating accounts, the permission grants that
 * store (and answer through) the admins.permissions JSON column, the
 * activation toggle's protections and the password reset — the behaviours
 * the fork's admins/index.php + PermissionService exposed.
 */
class AdminAdminsTest extends TestCase
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
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function makeAdmin(AdminRole $role = AdminRole::Admin, array $overrides = []): Admin
    {
        return Admin::query()->create(array_merge([
            'email' => $role->value.'-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => $role,
        ], $overrides));
    }

    private function postingAs(AdminRole $role = AdminRole::Admin)
    {
        return $this->actingAs($this->makeAdmin($role), 'admin');
    }

    // -----------------------------------------------------------------
    // The list
    // -----------------------------------------------------------------

    public function test_the_admins_page_lists_accounts_with_their_role_and_state(): void
    {
        $primary = $this->makeAdmin(AdminRole::Admin, ['is_primary' => true, 'is_active' => true]);
        $plain = $this->makeAdmin(AdminRole::Editor, ['is_active' => false]);

        $this->postingAs()
            ->get(route('admin.admins.index'))
            ->assertOk()
            ->assertSee($primary->email)
            ->assertSee($plain->email)
            ->assertSee('ادمین اصلی')
            ->assertSee('غیرفعال');
    }

    // -----------------------------------------------------------------
    // Creation
    // -----------------------------------------------------------------

    public function test_an_admin_creates_an_editor(): void
    {
        $this->postingAs()
            ->post(route('admin.admins.store'), [
                'email' => 'new-editor@acme.test',
                'password' => 'strong-pass-1',
                'role' => 'editor',
                'chat_id' => '999',
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $created = Admin::query()->where('email', 'new-editor@acme.test')->firstOrFail();
        $this->assertSame('editor', $created->role->value);
        $this->assertSame('999', $created->chat_id);
        $this->assertTrue((bool) $created->is_active);
        $this->assertFalse((bool) $created->is_primary, 'a created account is never primary');
        $this->assertTrue(password_verify('strong-pass-1', $created->getRawOriginal('password')));
    }

    public function test_creation_validates_the_email_password_and_role(): void
    {
        $this->postingAs()
            ->post(route('admin.admins.store'), [
                'email' => 'not-an-email',
                'password' => 'short',
                'role' => 'editor',
            ])
            ->assertSessionHasErrors(['email', 'password']);

        $this->postingAs()
            ->post(route('admin.admins.store'), [
                'email' => 'x@acme.test',
                'password' => 'long-enough-pass',
                'role' => 'super_admin',
            ])
            ->assertSessionHasErrors('role');

        $this->postingAs()
            ->post(route('admin.admins.store'), [
                'email' => 'x@acme.test',
                'password' => 'long-enough-pass',
                'role' => 'editor',
                'chat_id' => 'not-numeric',
            ])
            ->assertSessionHasErrors('chat_id');

        // And a duplicate email is refused.
        $this->makeAdmin(AdminRole::Editor, ['email' => 'taken@acme.test']);
        $this->postingAs()
            ->post(route('admin.admins.store'), [
                'email' => 'taken@acme.test',
                'password' => 'long-enough-pass',
                'role' => 'editor',
            ])
            ->assertSessionHasErrors('email');
    }

    public function test_an_editor_cannot_create_admins(): void
    {
        $this->postingAs(AdminRole::Editor)
            ->post(route('admin.admins.store'), [
                'email' => 'sneaky@acme.test',
                'password' => 'long-enough-pass',
                'role' => 'editor',
            ])
            ->assertForbidden();

        $this->assertDatabaseMissing('admins', ['email' => 'sneaky@acme.test']);
    }

    // -----------------------------------------------------------------
    // Activation
    // -----------------------------------------------------------------

    public function test_the_primary_admin_cannot_be_deactivated(): void
    {
        $primary = $this->makeAdmin(AdminRole::Admin, ['is_primary' => true]);

        $this->postingAs()
            ->post(route('admin.admins.toggle', $primary))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('admins', ['id' => $primary->id, 'is_active' => '1']);
    }

    public function test_another_admin_can_be_toggled(): void
    {
        $editor = $this->makeAdmin(AdminRole::Editor);

        $this->postingAs()
            ->post(route('admin.admins.toggle', $editor))
            ->assertRedirect()
            ->assertSessionHas('success');

        $this->assertDatabaseHas('admins', ['id' => $editor->id, 'is_active' => '0']);

        $this->postingAs()
            ->post(route('admin.admins.toggle', $editor->refresh()))
            ->assertRedirect();

        $this->assertDatabaseHas('admins', ['id' => $editor->id, 'is_active' => '1']);
    }

    // -----------------------------------------------------------------
    // Permissions
    // -----------------------------------------------------------------

    public function test_permissions_are_persisted_and_answered_through_the_service(): void
    {
        $editor = $this->makeAdmin(AdminRole::Editor);

        $this->postingAs()
            ->post(route('admin.admins.permissions', $editor), [
                'permissions' => ['dashboard', 'tickets', 'users', 'gateways', 'admins'],
            ])
            ->assertRedirect()
            ->assertSessionHas('success');

        $editor->refresh();

        // The stored JSON carries exactly the granted keys plus dashboard.
        $stored = json_decode((string) $editor->permissions, true);
        $this->assertIsArray($stored);
        $this->assertContains('tickets', $stored);
        $this->assertContains('gateways', $stored);
        // The reserved section never rides in through an explicit grant.
        $this->assertNotContains('admins', $stored);

        // And the service answers from that list.
        $this->assertTrue(AdminPermissionService::can($editor, 'tickets'));
        $this->assertTrue(AdminPermissionService::can($editor, 'users'));
        $this->assertTrue(AdminPermissionService::can($editor, 'dashboard'));
        $this->assertFalse(AdminPermissionService::can($editor, 'broadcast'));
        $this->assertFalse(AdminPermissionService::can($editor, 'admins'));
        // A role default a grant replaced: orders was never granted here.
        $this->assertFalse(AdminPermissionService::can($editor, 'orders'));
    }

    public function test_role_defaults_answer_when_no_explicit_list_exists(): void
    {
        $admin = $this->makeAdmin(AdminRole::Admin);
        $editor = $this->makeAdmin(AdminRole::Editor);

        // An Admin holds everything except the reserved section.
        $this->assertTrue(AdminPermissionService::can($admin, 'tickets'));
        $this->assertTrue(AdminPermissionService::can($admin, 'broadcast'));
        $this->assertTrue(AdminPermissionService::can($admin, 'db_backup'));
        $this->assertFalse(AdminPermissionService::can($admin, 'admins'));

        // The Editor keeps its read-only set only.
        $this->assertTrue(AdminPermissionService::can($editor, 'tickets'));
        $this->assertTrue(AdminPermissionService::can($editor, 'users'));
        $this->assertTrue(AdminPermissionService::can($editor, 'orders'));
        $this->assertFalse(AdminPermissionService::can($editor, 'broadcast'));
        $this->assertFalse(AdminPermissionService::can($editor, 'db_backup'));
        $this->assertFalse(AdminPermissionService::can($editor, 'admins'));

        // A deactivated account holds nothing at all.
        $editor->forceFill(['is_active' => false])->save();
        $this->assertFalse(AdminPermissionService::can($editor->refresh(), 'tickets'));
    }

    // -----------------------------------------------------------------
    // Password reset
    // -----------------------------------------------------------------

    public function test_an_admin_resets_another_admins_password(): void
    {
        $editor = $this->makeAdmin(AdminRole::Editor);
        $oldHash = $editor->getRawOriginal('password');

        $this->postingAs()
            ->post(route('admin.admins.password', $editor), ['password' => 'brand-new-pass'])
            ->assertRedirect()
            ->assertSessionHas('success');

        $fresh = $editor->refresh();
        $this->assertNotSame($oldHash, $fresh->getRawOriginal('password'));
        $this->assertTrue(password_verify('brand-new-pass', $fresh->getRawOriginal('password')));
    }

    public function test_the_password_reset_validates_length(): void
    {
        $editor = $this->makeAdmin(AdminRole::Editor);

        $this->postingAs()
            ->post(route('admin.admins.password', $editor), ['password' => 'short'])
            ->assertSessionHasErrors('password');
    }

    // -----------------------------------------------------------------
    // The activity trail
    // -----------------------------------------------------------------

    public function test_mutations_are_recorded_in_the_activity_trail(): void
    {
        $editor = $this->makeAdmin(AdminRole::Editor);

        $this->postingAs()
            ->post(route('admin.admins.store'), [
                'email' => 'tracked@acme.test',
                'password' => 'long-enough-pass',
                'role' => 'editor',
            ]);

        $this->postingAs()
            ->post(route('admin.admins.permissions', $editor), ['permissions' => ['tickets']]);

        $this->postingAs()
            ->post(route('admin.admins.password', $editor), ['password' => 'another-pass-1']);

        $this->assertDatabaseHas('admin_activity', ['action' => 'admin_create']);
        $this->assertDatabaseHas('admin_activity', ['action' => 'admin_permissions']);
        $this->assertDatabaseHas('admin_activity', ['action' => 'admin_password']);
    }
}
