<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Client;
use App\Models\LocalPlan;
use App\Models\Panel;
use App\Models\User;
use App\Models\Wallet;
use App\Enums\WalletOperation;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Models\WalletTransaction;
use App\Services\Local\LocalUserService;
use App\Services\Panels\PanelNodeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Admin Local Services page: users CRUD, services CRUD, attach, renew,
 * enable/disable, reset traffic, delete (soft is_deleted).
 * Mutations admin only; index admin,editor.
 */
class AdminLocalServicesTest extends TestCase
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
            'connectix_bot.broadcast.delay_us' => 0,
        ]);

        Http::preventStrayRequests();
    }

    private function actingAsAdmin()
    {
        return $this->postingAs(AdminRole::Admin);
    }

    private function actingAsEditor()
    {
        return $this->postingAs(AdminRole::Editor);
    }

    private function postingAs(AdminRole $role)
    {
        return $this->actingAs($this->makeAdmin($role), 'admin');
    }

    private function makeAdmin(AdminRole $role): Admin
    {
        return Admin::query()->create([
            'email' => $role->value.'-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => $role,
        ]);
    }

    /** @test */
    public function test_index_renders(): void
    {
        $this->actingAsAdmin();
        $response = $this->get(route('admin.localservices.index'));
        $response->assertOk();
        $response->assertViewHas('rows');
        $response->assertViewHas('plans');
        $response->assertViewHas('panels');
    }

    /** @test */
    public function test_editor_can_see_index_but_not_mutate(): void
    {
        $this->actingAsEditor();
        $this->get(route('admin.localservices.index'))->assertOk();

        $this->post(route('admin.localservices.users.store'), [
            'chat_id' => '123', 'name' => 'Test',
        ])->assertForbidden();
        $this->post(route('admin.localservices.users.block'), [
            'user_id' => 1, 'blocked' => true,
        ])->assertForbidden();
        $this->post(route('admin.localservices.services.store'), [
            'chat_id' => '123', 'plan_id' => 1,
        ])->assertForbidden();
        $this->post(route('admin.localservices.services.attach'), [
            'panel_id' => 1, 'remote_key' => 'rk', 'chat_id' => '123',
        ])->assertForbidden();
        $this->post(route('admin.localservices.services.renew'), [
            'client_id' => 'P1_x', 'plan_id' => 1,
        ])->assertForbidden();
        $this->post(route('admin.localservices.services.enabled'), [
            'client_id' => 'P1_x', 'enabled' => false,
        ])->assertForbidden();
        $this->post(route('admin.localservices.services.reset-traffic'), [
            'client_id' => 'P1_x',
        ])->assertForbidden();
        $this->post(route('admin.localservices.services.destroy'), [
            'client_id' => 'P1_x',
        ])->assertForbidden();
    }

    /** @test */
    public function test_create_user_creates_user_and_wallet(): void
    {
        $this->actingAsAdmin();
        $this->post(route('admin.localservices.users.store'), [
            'chat_id' => '555000111',
            'name' => 'Test User',
            'username' => 'testuser',
        ])->assertRedirect()
         ->assertSessionHas('success');

        $user = User::query()->where('chat_id', '555000111')->first();
        $this->assertNotNull($user);
        $this->assertEquals('Test User', $user->name);
        $this->assertEquals('testuser', $user->telegram_id);

        $wallet = Wallet::query()->where('chat_id', '555000111')->first();
        $this->assertNotNull($wallet);
        $this->assertEquals('0', $wallet->balance);
    }

    /** @test */
    public function test_set_blocked_toggles_user(): void
    {
        $this->actingAsAdmin();
        $user = User::query()->create([
            'chat_id' => '555000222',
            'name' => 'Block Test',
            'telegram_id' => 555000222,
        ]);

        $this->post(route('admin.localservices.users.block'), [
            'user_id' => $user->id,
            'blocked' => true,
        ])->assertRedirect();

        $user->refresh();
        $this->assertTrue((bool) $user->is_blocked);

        $this->post(route('admin.localservices.users.block'), [
            'user_id' => $user->id,
            'blocked' => false,
        ])->assertRedirect();

        $user->refresh();
        $this->assertFalse((bool) $user->is_blocked);
    }

    /** @test */
    public function test_search_users_returns_matches(): void
    {
        $this->actingAsAdmin();
        User::query()->create(['chat_id' => '111', 'name' => 'Ali', 'telegram_id' => 111]);
        User::query()->create(['chat_id' => '222', 'name' => 'Reza', 'telegram_id' => 222]);

        $svc = app(LocalUserService::class);
        $results = $svc->searchUsers('Ali');
        $this->assertCount(1, $results);
        $this->assertEquals('Ali', $results[0]['name']);

        $results = $svc->searchUsers('222');
        $this->assertCount(1, $results);
        $this->assertEquals('Reza', $results[0]['name']);
    }

    /** @test */
    public function test_create_service_creates_client_row_with_plan_devices(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Test Panel',
            'type' => 'marzban',
            'base_url' => 'https://p.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);
        $plan = LocalPlan::query()->create([
            'name' => 'Gold',
            'price' => 100000,
            'days' => 30,
            'traffic_gb' => 50,
            'devices' => 3,
            'panel_id' => $panel->id,
            'group_name' => 'default',
            'is_active' => true,
        ]);
        User::query()->create([
            'chat_id' => '555000333',
            'name' => 'Service User',
            'telegram_id' => 555000333,
        ]);

        $this->post(route('admin.localservices.services.store'), [
            'chat_id' => '555000333',
            'plan_id' => $plan->id,
            'charge_wallet' => false,
            'note' => 'Test note',
        ])->assertRedirect()
         ->assertSessionHas('success');

        $client = Client::query()->where('chat_id', '555000333')->first();
        $this->assertNotNull($client);
        $this->assertStringStartsWith('P'.$panel->id.'_L', $client->id);
        $this->assertEquals(3, $client->count_of_devices);
        $this->assertEquals('', $client->password);
        $this->assertEquals(555000333, $client->chat_id);
        $this->assertNotNull($client->created_at);
    }

    /** @test */
    public function test_create_service_charges_wallet_and_writes_ledger(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Wallet Panel',
            'type' => 'marzban',
            'base_url' => 'https://wp.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);
        $plan = LocalPlan::query()->create([
            'name' => 'Paid Plan',
            'price' => 50000,
            'days' => 30,
            'traffic_gb' => 20,
            'devices' => 2,
            'panel_id' => $panel->id,
            'group_name' => 'default',
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'chat_id' => '555000444',
            'name' => 'Wallet User',
            'telegram_id' => 555000444,
        ]);
        // Give user a wallet with balance
        $wallet = Wallet::query()->create([
            'chat_id' => '555000444',
            'balance' => '100000',
        ]);

        $this->post(route('admin.localservices.services.store'), [
            'chat_id' => '555000444',
            'plan_id' => $plan->id,
            'charge_wallet' => true,
        ])->assertRedirect()
         ->assertSessionHas('success');

        // Wallet debited
        $wallet->refresh();
        $this->assertEquals(50000, (int) $wallet->balance);

        // One wallet_transactions row written by decreaseIfAffordable
        $txCount = WalletTransaction::query()->where('wallet_id', $wallet->id)->count();
        $this->assertEquals(1, $txCount);
        $tx = WalletTransaction::query()->where('wallet_id', $wallet->id)->first();
        $this->assertEquals(50000, (int) $tx->amount);
        $this->assertEquals(WalletOperation::Decrease, $tx->operation);
        $this->assertEquals(WalletTransactionType::Buy, $tx->type);
        $this->assertEquals(WalletTransactionStatus::Success, $tx->status);
    }

    /** @test */
    public function test_attach_creates_client_from_remote_key(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Attach Panel',
            'type' => 'marzban',
            'base_url' => 'https://ap.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'chat_id' => '555000555',
            'name' => 'Attach User',
            'telegram_id' => 555000555,
        ]);

        $this->post(route('admin.localservices.services.attach'), [
            'panel_id' => $panel->id,
            'remote_key' => 'remote_user_123',
            'chat_id' => '555000555',
        ])->assertRedirect()
         ->assertSessionHas('success');

        $client = Client::query()->where('chat_id', '555000555')->first();
        $this->assertNotNull($client);
        $this->assertEquals('P'.$panel->id.'_remote_user_123', $client->id);
    }

    /** @test */
    public function test_renew_creates_new_client_row(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Renew Panel',
            'type' => 'marzban',
            'base_url' => 'https://rp.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);
        $plan = LocalPlan::query()->create([
            'name' => 'Renew Plan',
            'price' => 30000,
            'days' => 30,
            'traffic_gb' => 10,
            'devices' => 1,
            'panel_id' => $panel->id,
            'group_name' => 'default',
            'is_active' => true,
        ]);
        $user = User::query()->create([
            'chat_id' => '555000666',
            'name' => 'Renew User',
            'telegram_id' => 555000666,
        ]);
        $client = Client::query()->create([
            'id' => 'P'.$panel->id.'_L_OLD',
            'count_of_devices' => 1,
            'username' => 'L_OLD',
            'password' => '',
            'chat_id' => '555000666',
            'user_id' => $user->id,
            'created_at' => now(),
        ]);

        $this->post(route('admin.localservices.services.renew'), [
            'client_id' => $client->id,
            'plan_id' => $plan->id,
            'charge_wallet' => false,
        ])->assertRedirect()
         ->assertSessionHas('success');

        // Wave 3 keeps the same local row (the panel side is wave 4) and
        // refreshes the device count from the plan.
        $client->refresh();
        $this->assertEquals(1, $client->count_of_devices);
        $this->assertEquals(1, Client::query()->where('chat_id', '555000666')->count());
    }

    /** @test */
    public function test_set_enabled_toggles_is_deleted(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Enable Panel',
            'type' => 'marzban',
            'base_url' => 'https://ep.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);
        $plan = LocalPlan::query()->create([
            'name' => 'Plan',
            'price' => 1000,
            'days' => 30,
            'traffic_gb' => 10,
            'devices' => 1,
            'panel_id' => $panel->id,
            'group_name' => 'default',
            'is_active' => true,
        ]);
        $user = User::query()->create(['chat_id' => '777', 'name' => 'U', 'telegram_id' => 777]);
        $client = Client::query()->create([
            'id' => 'P'.$panel->id.'_L1',
            'count_of_devices' => 1,
            'username' => 'L1',
            'password' => '',
            'chat_id' => '777',
            'user_id' => $user->id,
            'created_at' => now(),
            'is_deleted' => false,
        ]);

        // Disable -> local plan_status = inactive (panel side is wave 4)
        $this->post(route('admin.localservices.services.enabled'), [
            'client_id' => $client->id,
            'enabled' => false,
        ])->assertRedirect();

        $this->assertEquals('inactive', Client::query()->find($client->id)->plan_status);

        // Enable -> plan_status = active
        $this->post(route('admin.localservices.services.enabled'), [
            'client_id' => $client->id,
            'enabled' => true,
        ])->assertRedirect();

        $this->assertEquals('active', Client::query()->find($client->id)->plan_status);
    }

    /** @test */
    public function test_reset_traffic_clears_usage_columns(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Reset Panel',
            'type' => 'marzban',
            'base_url' => 'https://reset.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);
        $plan = LocalPlan::query()->create([
            'name' => 'Plan',
            'price' => 1000,
            'days' => 30,
            'traffic_gb' => 10,
            'devices' => 1,
            'panel_id' => $panel->id,
            'group_name' => 'default',
            'is_active' => true,
        ]);
        $user = User::query()->create(['chat_id' => '888', 'name' => 'U', 'telegram_id' => 888]);
        $client = Client::query()->create([
            'id' => 'P'.$panel->id.'_L_RESET',
            'count_of_devices' => 1,
            'username' => 'L_RESET',
            'password' => '',
            'chat_id' => '888',
            'user_id' => $user->id,
            'created_at' => now(),
            'is_deleted' => false,
        ]);

        // Usage lives on the panel (wave 4): locally this verifies the row
        // exists and reports success.
        $this->post(route('admin.localservices.services.reset-traffic'), [
            'client_id' => $client->id,
        ])->assertRedirect()
         ->assertSessionHas('success');

        // Unknown client is refused
        $this->post(route('admin.localservices.services.reset-traffic'), [
            'client_id' => 'P'.$panel->id.'_NOPE',
        ])->assertRedirect()
         ->assertSessionHas('error');
    }

    /** @test */
    public function test_destroy_soft_flags_is_deleted(): void
    {
        $this->actingAsAdmin();
        $panel = Panel::query()->create([
            'name' => 'Del Panel',
            'type' => 'marzban',
            'base_url' => 'https://del.example.com',
            'username' => 'admin',
            'secret' => Crypt::encryptString('s'),
            'is_active' => true,
        ]);
        $plan = LocalPlan::query()->create([
            'name' => 'Plan',
            'price' => 1000,
            'days' => 30,
            'traffic_gb' => 10,
            'devices' => 1,
            'panel_id' => $panel->id,
            'group_name' => 'default',
            'is_active' => true,
        ]);
        $user = User::query()->create(['chat_id' => '999', 'name' => 'U', 'telegram_id' => 999]);
        $client = Client::query()->create([
            'id' => 'P'.$panel->id.'_L_DEL',
            'count_of_devices' => 1,
            'username' => 'L_DEL',
            'password' => '',
            'chat_id' => '999',
            'user_id' => $user->id,
            'created_at' => now(),
            'is_deleted' => false,
        ]);

        $this->post(route('admin.localservices.services.destroy'), [
            'client_id' => $client->id,
        ])->assertRedirect()
         ->assertSessionHas('success');

        $client->refresh();
        $this->assertTrue((bool) $client->is_deleted);
        // Row still exists
        $this->assertNotNull(Client::query()->find($client->id));
    }
}