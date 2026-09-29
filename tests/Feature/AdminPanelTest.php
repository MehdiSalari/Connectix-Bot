<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\AdminRole;
use App\Enums\PaymentMethod;
use App\Enums\WalletOperation;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Admin;
use App\Models\Client;
use App\Models\Payment;
use App\Models\SmsPayment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The admin panel sections: dashboard, users, orders, wallet and client
 * details, plus the role gating that keeps every mutating action with the
 * `admin` role.
 */
class AdminPanelTest extends TestCase
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

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function makeAdmin(AdminRole $role = AdminRole::Admin): Admin
    {
        return Admin::query()->create([
            'email' => $role->value.'-'.uniqid().'@acme.test',
            'password' => 'secret-pass',
            'token' => 'panel-token-a',
            'chat_id' => '10',
            'role' => $role,
        ]);
    }

    private function postingAs(AdminRole $role = AdminRole::Admin)
    {
        return $this->actingAs($this->makeAdmin($role), 'admin');
    }

    private function makeUser(string $chatId = '553', ?string $name = 'Ali'): User
    {
        return User::query()->create([
            'chat_id' => $chatId,
            'telegram_id' => 'tg_'.$chatId,
            'name' => $name,
            'created_at' => now(),
        ]);
    }

    private function makePayment(User $user, array $overrides = []): Payment
    {
        return Payment::query()->create(array_merge([
            'chat_id' => $user->chat_id,
            'client_id' => 'new',
            'plan_id' => 'plan-x',
            'price' => '120,000',
            'is_paid' => null,
            'method' => PaymentMethod::Card,
            'created_at' => now(),
        ], $overrides));
    }

    // -----------------------------------------------------------------
    // Dashboard
    // -----------------------------------------------------------------

    public function test_the_dashboard_shows_the_counters(): void
    {
        $this->makeUser('553', 'Ali');
        $this->makeUser('554', 'Reza');
        Wallet::query()->create(['chat_id' => '553', 'balance' => '50000']);
        $this->makePayment($this->makeUser('555'), ['created_at' => now()]);

        $this->postingAs()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('کاربران')
            ->assertSee('سفارش‌های امروز')
            ->assertSee('Ali')
            ->assertSee('Reza');
    }

    // -----------------------------------------------------------------
    // Users
    // -----------------------------------------------------------------

    public function test_the_user_list_is_searchable(): void
    {
        $this->makeUser('553', 'Ali');
        $this->makeUser('554', 'Reza');

        $this->postingAs()
            ->get(route('admin.users.index', ['search' => 'Ali']))
            ->assertOk()
            ->assertSee('Ali')
            ->assertDontSee('Reza');
    }

    public function test_the_user_search_endpoint_returns_json_rows(): void
    {
        $this->makeUser('553', 'Ali');

        $this->postingAs()
            ->getJson(route('admin.users.search', ['term' => 'Ali']))
            ->assertOk()
            ->assertJsonPath('users.0.chat_id', '553')
            ->assertJsonPath('users.0.name', 'Ali');
    }

    public function test_the_profile_page_shows_wallet_payments_and_clients(): void
    {
        $user = $this->makeUser();
        Wallet::query()->create(['chat_id' => $user->chat_id, 'balance' => '0']);
        Client::query()->create([
            'id' => 'uuid-1',
            'username' => 'ali-user',
            'chat_id' => $user->chat_id,
        ]);
        $this->makePayment($user);

        $this->postingAs()
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('uuid-1')
            ->assertSee('ali-user')
            ->assertSee('سفارش‌ها');
    }

    public function test_an_admin_can_create_a_wallet(): void
    {
        $user = $this->makeUser();

        $this->postingAs()
            ->post(route('admin.users.wallet.create'), ['chat_id' => $user->chat_id])
            ->assertRedirect();

        $this->assertDatabaseHas('wallets', ['chat_id' => $user->chat_id, 'balance' => '0']);
    }

    public function test_an_admin_can_increase_a_wallet_balance(): void
    {
        $user = $this->makeUser();
        Wallet::query()->create(['chat_id' => $user->chat_id, 'balance' => '10000']);

        $this->postingAs()
            ->post(route('admin.users.wallet.adjust'), [
                'chat_id' => $user->chat_id,
                'operation' => 'INCREASE',
                'amount' => 5000,
                'announce' => '0',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('wallets', ['chat_id' => $user->chat_id, 'balance' => '15000']);
        $this->assertDatabaseHas('wallet_transactions', [
            'chat_id' => $user->chat_id,
            'amount' => '5000',
            'operation' => WalletOperation::Increase->value,
            'type' => WalletTransactionType::DoneByAdmin->value,
            'status' => WalletTransactionStatus::Success->value,
        ]);
    }

    public function test_a_decrease_needs_an_existing_wallet(): void
    {
        $user = $this->makeUser();

        $this->postingAs()
            ->post(route('admin.users.wallet.adjust'), [
                'chat_id' => $user->chat_id,
                'operation' => 'DECREASE',
                'amount' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('wallet_transactions', ['chat_id' => $user->chat_id]);
    }

    // -----------------------------------------------------------------
    // Orders
    // -----------------------------------------------------------------

    public function test_the_order_list_renders_and_decides(): void
    {
        $user = $this->makeUser();
        $payment = $this->makePayment($user);

        $this->postingAs()
            ->get(route('admin.orders.index'))
            ->assertOk()
            ->assertSee($payment->order_number);

        $this->postingAs()
            ->post(route('admin.orders.decide', $payment), ['action' => 'accept'])
            ->assertRedirect();

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'is_paid' => '1']);
    }

    public function test_a_decided_order_cannot_be_decided_again(): void
    {
        $user = $this->makeUser();
        $payment = $this->makePayment($user, ['is_paid' => '1']);

        $this->postingAs()
            ->post(route('admin.orders.decide', $payment), ['action' => 'reject'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('payments', ['id' => $payment->id, 'is_paid' => '1']);
    }

    // -----------------------------------------------------------------
    // Ledgers
    // -----------------------------------------------------------------

    public function test_the_wallet_ledger_and_sms_lists_render(): void
    {
        $user = $this->makeUser();
        $wallet = Wallet::query()->create(['chat_id' => $user->chat_id, 'balance' => '0']);

        WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'amount' => '30000',
            'operation' => WalletOperation::Increase->value,
            'chat_id' => $user->chat_id,
            'status' => WalletTransactionStatus::Pending->value,
            'type' => WalletTransactionType::CardToCard->value,
        ]);

        SmsPayment::query()->create([
            'message' => '1,200,000 ریال',
            'amount' => 120000,
            'bank' => 'blu',
        ]);

        $this->postingAs()
            ->get(route('admin.wallet-transactions.index'))
            ->assertOk()
            ->assertSee('30,000');

        $this->postingAs()
            ->get(route('admin.sms-payments.index'))
            ->assertOk()
            ->assertSee('1,200,000 ریال');
    }

    // -----------------------------------------------------------------
    // Client details
    // -----------------------------------------------------------------

    public function test_client_details_come_from_the_panel(): void
    {
        Client::query()->create([
            'id' => 'uuid-1',
            'username' => 'ali-user',
            'chat_id' => '553',
        ]);

        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/show*' => Http::response([
                'client' => [
                    'id' => 'uuid-1',
                    'username' => 'ali-user',
                    'plans' => [['plan_id' => 'p1', 'name' => 'ویژه']],
                ],
            ], 200),
        ]);

        $this->postingAs()
            ->getJson(route('admin.clients.show', 'uuid-1'))
            ->assertOk()
            ->assertJsonPath('client.username', 'ali-user')
            ->assertJsonPath('local.username', 'ali-user');
    }

    // -----------------------------------------------------------------
    // Role gating
    // -----------------------------------------------------------------

    public function test_a_guest_is_sent_to_login_for_every_panel_page(): void
    {
        $this->get(route('admin.dashboard'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.users.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.orders.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.settings.show'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.guides.index'))->assertRedirect(route('admin.login'));
        $this->get(route('admin.broadcast.show'))->assertRedirect(route('admin.login'));
    }

    public function test_an_editor_can_read_but_not_mutate(): void
    {
        $user = $this->makeUser();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->get(route('admin.users.index'))
            ->assertOk();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->post(route('admin.users.wallet.adjust'), [
                'chat_id' => $user->chat_id,
                'operation' => 'INCREASE',
                'amount' => 1,
            ])
            ->assertForbidden();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->post(route('admin.settings.update'), ['app_name' => 'Hacked'])
            ->assertForbidden();

        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->post(route('admin.broadcast.start'), ['message' => 'spam'])
            ->assertForbidden();
    }
}
