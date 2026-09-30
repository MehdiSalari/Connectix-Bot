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
            ->assertOk()
            ->assertDontSee('حذف اکانت', false);                // no delete controls for an editor

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

    // -----------------------------------------------------------------
    // Pagination markup
    // -----------------------------------------------------------------

    /**
     * The list pages must render the design system's pager.
     *
     * The stock pagination::tailwind view is built from tailwind utility
     * classes and an svg chevron, and neither exists in this panel: the icon
     * came out at its intrinsic (huge) size and the mobile and the desktop
     * copy of the control showed at the same time on every breakpoint. The
     * published resources/views/vendor/pagination/tailwind.blade.php replaces
     * it, and this pins that down for the two pages that reported the bug.
     */
    public function test_the_list_pages_render_the_panel_pager(): void
    {
        for ($i = 1; $i <= 21; $i++) {
            $this->makeUser((string) (600 + $i), 'Pager'.$i);
        }

        $ledgerOwner = $this->makeUser('650');
        $wallet = Wallet::query()->create(['chat_id' => $ledgerOwner->chat_id, 'balance' => '0']);

        for ($i = 0; $i < 31; $i++) {
            WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'amount' => '1000',
                'operation' => WalletOperation::Increase->value,
                'chat_id' => $ledgerOwner->chat_id,
                'status' => WalletTransactionStatus::Pending->value,
                'type' => WalletTransactionType::CardToCard->value,
            ]);

            SmsPayment::query()->create([
                'message' => 'message '.$i,
                'amount' => 1000 + $i,
                'bank' => 'blu',
            ]);
        }

        foreach (['admin.users.index', 'admin.wallet-transactions.index', 'admin.sms-payments.index'] as $name) {
            $html = $this->postingAs()->get(route($name))->assertOk()->getContent();

            $this->assertMatchesRegularExpression(
                '/<div class="pager">.*<nav role="navigation" aria-label="صفحه‌بندی">.*<ul>.*<\/ul>.*<\/nav>/s',
                $html,
                $name.' should render the design system pager'
            );

            preg_match('/<div class="pager">.*?<\/nav>/s', $html, $pager);

            $this->assertNotFalse($pager[0] ?? false, $name.' pager block missing');
            $this->assertStringNotContainsString('<svg', $pager[0], $name.' pager must not carry svg icons');
            // On page 1 «قبلی» is a disabled span, so only «بعدی» is a link -
            // both labels must be there in either case.
            $this->assertStringContainsString('قبلی', $pager[0], $name);
            $this->assertStringContainsString('بعدی', $pager[0], $name);
            $this->assertStringContainsString('rel="next"', $pager[0], $name);
        }
    }

    // -----------------------------------------------------------------
    // Account status and deletion
    // -----------------------------------------------------------------

    /**
     * The profile lists فعال / غیرفعال from the panel expiry that
     * connectix:sync-clients stores, and says نامشخص instead of guessing when
     * a row has never been synced. The dates are Jalali, far enough apart that
     * the test does not rot.
     */
    public function test_the_profile_shows_the_status_of_every_account(): void
    {
        $user = $this->makeUser();

        foreach ([
            // The paid window is the fallback, so these three are decided by
            // expire_date alone: a far future stamp, a far past one, nothing.
            'uuid-window-active' => ['expire_date' => '1450-06-15 12:00', 'plan_status' => null],
            'uuid-window-ended' => ['expire_date' => '1395-06-15 12:00', 'plan_status' => null],
            'uuid-unknown' => ['expire_date' => null, 'plan_status' => null],
            // The panel's plan flags win over the window - the rule the legacy
            // bot used - so both of these are expired but still reported active
            // or queued by the panel.
            'uuid-plan-active' => ['expire_date' => '1395-06-15 12:00', 'plan_status' => 'active'],
            'uuid-plan-queued' => ['expire_date' => '1395-06-15 12:00', 'plan_status' => 'queued'],
        ] as $id => $columns) {
            Client::query()->create(array_merge([
                'id' => $id,
                'username' => 'user-'.$id,
                'chat_id' => $user->chat_id,
            ], $columns));
        }

        $html = $this->postingAs()->get(route('admin.users.show', $user))->assertOk()->getContent();

        $this->assertStringContainsString('>فعال</span>', $html);
        $this->assertStringContainsString('>غیرفعال</span>', $html);
        $this->assertStringContainsString('>نامشخص</span>', $html);
        $this->assertStringContainsString('>در صف</span>', $html);
        $this->assertStringContainsString('class="badge wait"', $html, 'a queued plan must use the wait tone');
    }

    public function test_an_admin_deletes_an_account_on_the_panel_and_locally(): void
    {
        Client::query()->create(['id' => 'uuid-del', 'username' => 'gone', 'chat_id' => '553']);

        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/delete' => Http::response(['ok' => true], 200),
        ]);

        // The delete lives twice on the page the admin actually uses: as a row
        // button and as the action the account details modal wires up.
        $this->postingAs()
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('حذف اکانت', false)                    // the modal's delete action
            ->assertSee('/admin/clients/:id', false);           // ... wired to the client's route

        $this->postingAs()
            ->from(route('admin.users.index'))
            ->delete(route('admin.clients.destroy', 'uuid-del'))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('success');

        $this->assertDatabaseMissing('clients', ['id' => 'uuid-del']);
    }

    /**
     * The panel is the system of record: when it refuses the delete (the live
     * seller token without the delete ability answers 403) the local row must
     * survive, or the bot would stop seeing an account the panel still sells.
     */
    public function test_a_refused_panel_delete_keeps_the_local_row(): void
    {
        Client::query()->create(['id' => 'uuid-keep', 'username' => 'kept', 'chat_id' => '553']);

        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/delete' => Http::response([
                'message' => 'This action is unauthorized.',
            ], 403),
        ]);

        $this->postingAs()
            ->from(route('admin.users.index'))
            ->delete(route('admin.clients.destroy', 'uuid-keep'))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('error');

        $this->assertDatabaseHas('clients', ['id' => 'uuid-keep']);
    }

    // -----------------------------------------------------------------
    // Avatars
    // -----------------------------------------------------------------

    /** The Telegram photo in both places legacy showed it: the list and the profile. */
    public function test_the_list_and_the_profile_show_the_telegram_avatar(): void
    {
        $user = $this->makeUser('553', 'Ali');
        $user->forceFill(['avatar' => 'https://cdn.example.test/photo.jpg'])->save();

        $this->postingAs()
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('https://cdn.example.test/photo.jpg', false);

        $this->postingAs()
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('https://cdn.example.test/photo.jpg', false);
    }

    /** The panel header carries the bot's own photo, scraped from its t.me page. */
    public function test_the_dashboard_header_carries_the_bot_photo_block(): void
    {
        $this->postingAs()
            ->get(route('admin.dashboard'))
            ->assertOk()
            ->assertSee('پنل مدیریت Acme VPN');
    }
}

