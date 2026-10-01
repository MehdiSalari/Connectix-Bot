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
use App\Services\Panel\PanelSettingsService;
use App\Services\Plan\PlanService;
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
            // Pinned so the seller-panel deep link does not depend on .env.
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

    public function test_a_first_increase_opens_the_wallet(): void
    {
        // The create-wallet button is gone: one intent, not two. The first
        // increase is what opens the wallet, so an admin crediting a user who
        // has none no longer has to press a second button to make the first
        // one do anything.
        $user = $this->makeUser();

        $this->postingAs()
            ->post(route('admin.users.wallet.adjust'), [
                'chat_id' => $user->chat_id,
                'operation' => 'INCREASE',
                'amount' => 5000,
                'announce' => '0',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('wallets', ['chat_id' => $user->chat_id, 'balance' => '5000']);
        $this->assertDatabaseHas('wallet_transactions', [
            'chat_id' => $user->chat_id,
            'amount' => '5000',
            'operation' => WalletOperation::Increase->value,
            'type' => WalletTransactionType::DoneByAdmin->value,
        ]);
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
        // A debit is not the intent that opens a wallet: there is no money to
        // open it with, and auto-creating one at zero would write a negative
        // balance and a ledger row for it.
        $user = $this->makeUser();

        $this->postingAs()
            ->post(route('admin.users.wallet.adjust'), [
                'chat_id' => $user->chat_id,
                'operation' => 'DECREASE',
                'amount' => 1,
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseMissing('wallets', ['chat_id' => $user->chat_id]);
        $this->assertDatabaseMissing('wallet_transactions', ['chat_id' => $user->chat_id]);
    }

    public function test_a_decrease_cannot_overdraw_the_wallet(): void
    {
        $user = $this->makeUser();
        Wallet::query()->create(['chat_id' => $user->chat_id, 'balance' => '1000']);

        $this->postingAs()
            ->post(route('admin.users.wallet.adjust'), [
                'chat_id' => $user->chat_id,
                'operation' => 'DECREASE',
                'amount' => 5000,
                'announce' => '0',
            ])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertDatabaseHas('wallets', ['chat_id' => $user->chat_id, 'balance' => '1000']);
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

    public function test_the_order_list_filters_in_the_query(): void
    {
        $user = $this->makeUser();
        $pending = $this->makePayment($user, ['is_paid' => null, 'created_at' => now()]);
        $accepted = $this->makePayment($user, ['is_paid' => '1', 'created_at' => now()->subMinute()]);

        $response = $this->postingAs()
            ->get(route('admin.orders.index', ['status' => '1']))
            ->assertOk();

        // The filter used to run in PHP over one page of twenty rows, so it
        // could return three rows of twenty and still print a pager counting
        // every order - which offered pages that were empty once applied.
        $response->assertSee($accepted->order_number)
            ->assertDontSee($pending->order_number);

        $this->assertSame(1, (int) $response->viewData('total'));
    }

    public function test_the_order_details_sheet_reports_the_order_without_an_account(): void
    {
        $this->fakePlansCatalogue();
        $user = $this->makeUser();
        $payment = $this->makePayment($user, ['client_id' => 'new']);

        $this->postingAs()
            ->getJson(route('admin.orders.details', $payment))
            ->assertOk()
            ->assertJsonPath('order.order_number', $payment->order_number)
            ->assertJsonPath('order.price_text', '120,000')
            ->assertJsonPath('buyer.chat_id', $user->chat_id)
            // A pending order has no account yet, so the sheet says so instead
            // of asking the seller panel about a client that does not exist.
            ->assertJsonPath('account', null);
    }

    public function test_the_order_details_sheet_reads_the_account_from_the_panel(): void
    {
        $this->fakePlansCatalogue();
        $user = $this->makeUser();
        $payment = $this->makePayment($user, ['client_id' => 'uuid-9']);

        Http::fake([
            'api.connectix.vip/v1/seller/clients/show*' => Http::response([
                'client' => [
                    'id' => 'uuid-9',
                    'name' => 'soosan hoseini',
                    'username' => 'knticnax',
                    'password' => 's2g8k',
                    'expire_date' => '1405-8-11 15:36',
                    'count_of_devices' => '2',
                    'plans' => [[
                        // The panel's plan title is the full seller form; the
                        // short "30GB-1M" shorthand the fake used first does not
                        // parse, because the grammar requires the device prefix
                        // and the extra suffix - which is why the quota read
                        // back as null and the sheet showed "1.4" with no
                        // ceiling.
                        'name' => '(2x) 30GB-1M + 3D + Economic',
                        'is_active' => 1,
                        'total_used_traffic' => '1.4',
                    ]],
                ],
            ]),
        ]);

        $this->postingAs()
            ->getJson(route('admin.orders.details', $payment))
            ->assertOk()
            ->assertJsonPath('account.name', 'soosan hoseini')
            ->assertJsonPath('account.username', 'knticnax')
            ->assertJsonPath('account.password', 's2g8k')
            // The panel only reports what has been burned; the ceiling is read
            // off the running plan's title, which is the 1.4/30 pair.
            ->assertJsonPath('account.used_traffic', '1.4')
            ->assertJsonPath('account.total_traffic', '30 GB');
    }

    public function test_the_order_details_sheet_is_read_only_for_an_editor(): void
    {
        $this->fakePlansCatalogue();
        $payment = $this->makePayment($this->makeUser());

        $this->postingAs(AdminRole::Editor)
            ->getJson(route('admin.orders.details', $payment))
            ->assertOk();
    }

    /**
     * The sheet header shows the person who bought, so the buyer's cached
     * Telegram photo travels with the payload - otherwise the header keeps its
     * placeholder letter over a body that has already rendered.
     */
    public function test_the_order_sheet_carries_the_buyer_avatar_and_name(): void
    {
        $this->fakePlansCatalogue();

        $user = $this->makeUser('553', 'Ali Reza');
        $user->forceFill(['avatar' => 'https://cdn.example.test/ali.jpg'])->save();

        $payment = $this->makePayment($user);

        $this->postingAs()
            ->getJson(route('admin.orders.details', $payment))
            ->assertOk()
            ->assertJsonPath('buyer.name', 'Ali Reza')
            ->assertJsonPath('buyer.avatar', 'https://cdn.example.test/ali.jpg');
    }

    /**
     * A catalogue stub with the plan the fixtures order (`plan-x`).
     *
     * The order sheet resolves `plan_id` back to a plan, so it reads the seller
     * catalogue - which is a seller-panel call and therefore faked here rather
     * than allowed to leave the machine.
     */
    private function fakePlansCatalogue(): void
    {
        Http::fake([
            'api.connectix.vip/v1/seller/seller-plans*' => Http::response([
                'seller_plan_group' => [[
                    'name' => 'Premium',
                    'seller_plans' => [[
                        'id' => 'plan-x',
                        'title' => '(2x) 30GB-1M + 3D + Economic',
                        'type' => 'Premium',
                        'is_displayed_in_robot' => true,
                    ]],
                ]],
            ]),
        ]);
    }

    // -----------------------------------------------------------------
    // The user details modal
    // -----------------------------------------------------------------

    public function test_the_user_details_sheet_answers_for_the_modal(): void
    {
        $user = $this->makeUser();
        Wallet::query()->create(['chat_id' => $user->chat_id, 'balance' => '12000']);
        Client::query()->create(['id' => 'uuid-2', 'username' => 'ali-user', 'chat_id' => $user->chat_id]);
        $this->makePayment($user);

        $this->postingAs()
            ->getJson(route('admin.users.details', $user->chat_id))
            ->assertOk()
            ->assertJsonPath('chat_id', $user->chat_id)
            ->assertJsonPath('name', 'Ali')
            ->assertJsonPath('wallet_text', '12,000')
            ->assertJsonCount(1, 'accounts')
            ->assertJsonCount(1, 'orders');
    }

    public function test_the_user_details_sheet_404s_for_an_unknown_chat(): void
    {
        $this->postingAs()
            ->getJson(route('admin.users.details', '999999'))
            ->assertNotFound();
    }

    public function test_the_user_details_sheet_is_read_only_for_an_editor(): void
    {
        $user = $this->makeUser();

        $this->postingAs(AdminRole::Editor)
            ->getJson(route('admin.users.details', $user->chat_id))
            ->assertOk();
    }

    public function test_the_wallet_history_pages_and_labels_the_direction(): void
    {
        $user = $this->makeUser();
        $wallet = Wallet::query()->create(['chat_id' => $user->chat_id, 'balance' => '7000']);

        foreach ([['3000', WalletOperation::Increase], ['5000', WalletOperation::Decrease]] as [$amount, $operation]) {
            WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'amount' => $amount,
                'operation' => $operation->value,
                'chat_id' => $user->chat_id,
                'status' => WalletTransactionStatus::Success->value,
                'type' => WalletTransactionType::DoneByAdmin->value,
                'created_at' => now(),
            ]);
        }

        $this->postingAs()
            ->getJson(route('admin.users.wallet-history', $user->chat_id))
            ->assertOk()
            ->assertJsonPath('balance_text', '7,000')
            ->assertJsonCount(2, 'transactions')
            // The direction is a server-chosen class, so the ledger reads the
            // same in the table, the modal and the global list.
            ->assertJsonPath('transactions.0.direction', 'down')
            ->assertJsonPath('transactions.1.direction', 'up');
    }

    public function test_the_wallet_history_is_scoped_to_one_user(): void
    {
        $mine = $this->makeUser('553');
        $other = $this->makeUser('554');

        foreach ([$mine, $other] as $index => $user) {
            $wallet = Wallet::query()->create(['chat_id' => $user->chat_id, 'balance' => '1000']);

            WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'amount' => (string) (1000 * ($index + 1)),
                'operation' => WalletOperation::Increase->value,
                'chat_id' => $user->chat_id,
                'status' => WalletTransactionStatus::Success->value,
                'type' => WalletTransactionType::DoneByAdmin->value,
                'created_at' => now(),
            ]);
        }

        $this->postingAs()
            ->getJson(route('admin.users.wallet-history', $mine->chat_id))
            ->assertOk()
            ->assertJsonCount(1, 'transactions')
            ->assertJsonPath('transactions.0.amount_text', '1,000');
    }

    public function test_a_photo_is_a_separate_trigger_from_the_details_sheet(): void
    {
        // Three targets, none of them overlapping: tapping the photo zooms it,
        // tapping the row itself opens the summary sheet (data-user-details
        // lives on the <tr>), and the only button in the row — «جزئیات» — goes
        // to the full page, where the account/transaction/order tables live.
        $user = $this->makeUser();
        $user->forceFill(['avatar' => 'https://cdn.test/a.jpg'])->save();

        $this->postingAs()
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertSee('data-photo="https://cdn.test/a.jpg"', false)
            ->assertSee('<tr data-user-details="'.$user->chat_id.'"', false)
            ->assertSee('href="'.route('admin.users.show', $user).'"', false)
            // the wallet history trigger belongs to the profile page now
            ->assertDontSee('data-wallet-history="'.$user->chat_id.'"', false);
    }

    public function test_the_profile_page_offers_the_photo_and_the_wallet_history(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['avatar' => 'https://cdn.test/b.jpg'])->save();

        $this->postingAs()
            ->get(route('admin.users.show', $user))
            ->assertOk()
            ->assertSee('data-photo="https://cdn.test/b.jpg"', false)
            ->assertSee('data-wallet-history="'.$user->chat_id.'"', false);
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
            // مبلغ در سطر هست، متن پیام نه: جای متن، مودال «جزئیات» همان
            // سطر است که همه‌چیز را نشان می‌دهد.
            ->assertSee('پیامک‌های بانکی')
            ->assertSee('120,000')
            ->assertDontSee('1,200,000 ریال')
            ->assertSee('data-sms-details', false)
            ->assertSee('>جزئیات</button>', false);
    }

    public function test_the_sms_search_covers_amount_and_text_in_both_digit_shapes(): void
    {
        SmsPayment::query()->create([
            'message' => 'بلو واریز ۷,۷۱۰,۰۰۰ ریال به حساب شما نشست',
            'amount' => 771000,
            'bank' => 'blu',
        ]);
        SmsPayment::query()->create([
            'message' => 'ملت کارت به کارت 338,000 تومان',
            'amount' => 338000,
            'bank' => 'mellat',
        ]);

        // مبلغِ تایپ‌شده با ارقام لاتین از ستون amount می‌نشیند
        $this->postingAs()
            ->get(route('admin.sms-payments.index', ['search' => '771,000']))
            ->assertOk()
            ->assertSee('771,000')
            ->assertDontSee('338,000');

        // همان مبلغ با ارقام فارسی: سرور شکل ارقام را به هم تبدیل می‌کند
        $this->postingAs()
            ->get(route('admin.sms-payments.index', ['search' => '۷۷۱۰۰۰']))
            ->assertOk()
            ->assertSee('771,000')
            ->assertDontSee('338,000');

        // متن پیام با شکل ارقام خودش
        $this->postingAs()
            ->get(route('admin.sms-payments.index', ['search' => '۷,۷۱۰,۰۰۰']))
            ->assertOk()
            ->assertSee('771,000')
            ->assertDontSee('338,000');

        // و متنی که نه مبلغ است نه بانک
        $this->postingAs()
            ->get(route('admin.sms-payments.index', ['search' => 'کارت به کارت']))
            ->assertOk()
            ->assertSee('338,000')
            ->assertDontSee('771,000');
    }

    public function test_the_sms_list_filters_by_bank_and_date(): void
    {
        SmsPayment::query()->create([
            'message' => 'سفارش قدیمی',
            'amount' => 111111,
            'bank' => 'blu',
            'created_at' => '2026-09-20 10:00:00',
        ]);
        SmsPayment::query()->create([
            'message' => 'سفارش تازه',
            'amount' => 222222,
            'bank' => 'mellat',
            'created_at' => '2026-10-01 10:00:00',
        ]);

        $this->postingAs()
            ->get(route('admin.sms-payments.index', ['bank' => 'blu']))
            ->assertOk()
            ->assertSee('111,111')
            ->assertDontSee('222,222');

        // بازه‌ی تاریخ شامل حال خودش است: فقط سطرِ همان روز می‌ماند
        $this->postingAs()
            ->get(route('admin.sms-payments.index', ['from' => '2026-10-01', 'to' => '2026-10-01']))
            ->assertOk()
            ->assertSee('222,222')
            ->assertDontSee('111,111');

        // تاریخِ نامعتبر نباید فیلتر بسازد و چیزی را پنهان کند
        $this->postingAs()
            ->get(route('admin.sms-payments.index', ['from' => '99/99/99']))
            ->assertOk()
            ->assertSee('111,111')
            ->assertSee('222,222');

        // جستجو باید زیر فیلتر بانک بماند؛ اگر orWhere از گروه بیرون بزند،
        // سطرِ بانک دیگر با همین جستجو سر درمی‌آورد
        $this->postingAs()
            ->get(route('admin.sms-payments.index', ['bank' => 'blu', 'search' => 'سفارش تازه']))
            ->assertOk()
            ->assertDontSee('111,111')
            ->assertDontSee('222,222');
    }

    public function test_the_sms_list_shows_type_and_status_instead_of_the_order_number(): void
    {
        SmsPayment::query()->create([
            'message' => 'پیام تطبیق‌خورده',
            'amount' => 5000,
            'bank' => 'blu',
            'payment_id' => '7',
            'payment_type' => 'buy',
            'created_at' => now(),
        ]);
        SmsPayment::query()->create([
            'message' => 'پیام در انتظار',
            'amount' => 6000,
            'bank' => 'mellat',
            'created_at' => now(),
        ]);

        $this->postingAs()
            ->get(route('admin.sms-payments.index'))
            ->assertOk()
            ->assertSee('<th>نوع پرداخت</th>', false)
            ->assertSee('<th>وضعیت</th>', false)
            ->assertSee('خرید سرویس')
            ->assertSee('تأیید شده')
            ->assertSee('در انتظار تطبیق')
            ->assertSee('class="badge ok"', false)
            ->assertSee('class="badge wait"', false)
            // ستون شمار سفارش جای خود را به همین دو ستون داده است
            ->assertDontSee('<th>سفارش</th>', false);
    }

    public function test_the_deposit_sheet_returns_its_row_buyer_and_order(): void
    {
        $admin = $this->makeAdmin();
        $user = $this->makeUser('1125220828', 'Adel');
        $payment = $this->makePayment($user);

        $deposit = SmsPayment::query()->create([
            'message' => 'بلو واریز ۵,۵۶۰,۰۰۰ ریال',
            'amount' => 556000,
            'bank' => 'blu',
            'payment_id' => (string) $payment->id,
            'payment_type' => 'buy',
            'created_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.sms-payments.details', $deposit))
            ->assertOk()
            ->assertJsonPath('deposit.amount_text', '556,000')
            ->assertJsonPath('deposit.status_label', 'تأیید شده')
            ->assertJsonPath('deposit.status_tone', 'ok')
            ->assertJsonPath('deposit.bank', 'blu')
            ->assertJsonPath('message', 'بلو واریز ۵,۵۶۰,۰۰۰ ریال')
            ->assertJsonPath('user.name', 'Adel')
            ->assertJsonPath('user.chat_id', '1125220828')
            ->assertJsonPath('user.profile_url', route('admin.users.show', $user))
            // کارت خرید از خودِ صفحه‌ی سفارش می‌آید، نه از اینجا
            ->assertJsonPath('order_endpoint', route('admin.orders.details', $payment));

        // بدون تطبیق: نه کاربری دارد نه سفارشی و هنوز در انتظار می‌ماند
        $waiting = SmsPayment::query()->create([
            'message' => 'هنوز تطبیق نخورده',
            'amount' => 1000,
            'bank' => 'mellat',
            'created_at' => now(),
        ]);

        $this->actingAs($admin, 'admin')
            ->getJson(route('admin.sms-payments.details', $waiting))
            ->assertOk()
            ->assertJsonPath('deposit.status_label', 'در انتظار تطبیق')
            ->assertJsonPath('user', null)
            ->assertJsonPath('order_endpoint', null);

        // ادیتور هم می‌تواند ورق بزند: مودال فقط‌خواندنی است
        $this->actingAs($this->makeAdmin(AdminRole::Editor), 'admin')
            ->getJson(route('admin.sms-payments.details', $deposit))
            ->assertOk()
            ->assertJsonPath('deposit.amount_text', '556,000');
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
            ->assertJsonPath('local.username', 'ali-user')
            // The deep link into the seller panel, where the whole record can
            // be acted on. It is built from CONNECTIX_PANEL_URL, not from the
            // API base - the two hosts are different services.
            ->assertJsonPath('panel_url', 'https://seller.connectix.vip/client/uuid-1');
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

    // -----------------------------------------------------------------
    // Settings: bank auto-payment and plan group names
    // -----------------------------------------------------------------

    /**
     * The bank card carries the setup steps the legacy panel documented, and
     * the toggle is described for what it does.
     */
    public function test_the_settings_page_documents_the_bank_setup(): void
    {
        $html = $this->postingAs()->get(route('admin.settings.show'))->assertOk()->getContent();

        // The old label named the gateway; the toggle only decides whether the
        // first administrator is notified when a deposit arrives.
        $this->assertStringNotContainsString('اطلاع به گیت‌وی بانک', $html);
        $this->assertStringContainsString('SMS Forwarder', $html);
        $this->assertStringContainsString('/bank/sms', $html);
        $this->assertStringContainsString('پرداخت خودکار بانکی', $html);

        // The plan group names were missing from the page entirely.
        $this->assertStringContainsString('نام گروه سرویس‌ها', $html);
        $this->assertStringContainsString('name="plan_group_sublink"', $html);
        $this->assertStringContainsString('name="plan_group_business_class"', $html);
    }

    /**
     * Renaming a group has to reach the purchase menu, and an empty field has
     * to give the shipped name back rather than pinning the old rename.
     */
    public function test_a_plan_group_name_can_be_renamed_and_reverted(): void
    {
        config(['connectix_bot.telegram.webhook_url' => '']);

        $this->postingAs()->post(route('admin.settings.update'), [
            'app_name' => 'Acme VPN',
            'plan_group_sublink' => 'زیرلینک ویژه',
        ])->assertRedirect()->assertSessionHas('success');

        $this->assertSame('زیرلینک ویژه', app(PanelSettingsService::class)->planGroupNames()['Sublink']);
        $this->assertSame('زیرلینک ویژه', app(PlanService::class)->parseType('Sublink'));

        $this->postingAs()->post(route('admin.settings.update'), [
            'app_name' => 'Acme VPN',
            'plan_group_sublink' => '',
        ])->assertRedirect();

        $this->assertSame('سابلینک', app(PanelSettingsService::class)->planGroupNames()['Sublink']);
        $this->assertSame('سابلینک', app(PlanService::class)->parseType('Sublink'));
    }

    // -----------------------------------------------------------------
    // Guides
    // -----------------------------------------------------------------

    /**
     * `use` is the general how-to guide, not a platform, so it has its own
     * section; the platform names are Persian; and every row deletes itself
     * instead of going through the old standalone card.
     */
    public function test_the_guides_page_splits_the_general_guide_from_the_platforms(): void
    {
        $html = $this->postingAs()->get(route('admin.guides.index'))->assertOk()->getContent();

        $this->assertStringContainsString('نحوه استفاده کلی از سرویس', $html);
        $this->assertStringContainsString('آموزش‌های پلتفرم‌ها', $html);
        $this->assertStringContainsString('ویندوز', $html);
        $this->assertStringContainsString('اندروید', $html);
        $this->assertStringNotContainsString('حذف آموزش استاندارد', $html);

        // The mode choice the row offers, once per platform.
        $this->assertStringContainsString('name="guide_windows_mode"', $html);
        $this->assertStringContainsString('name="guide_use_mode"', $html);

        $this->assertStringContainsString('form="guide-delete-windows"', $html);

        // The delete form cannot sit inside the save form: nested forms are
        // dropped by the browser, which would break saving the other rows.
        $video = strpos($html, 'name="guide_windows_video"');
        $this->assertNotFalse($video);

        $saveFormEnd = strpos($html, '</form>', $video);
        $deleteForm = strpos($html, 'id="guide-delete-windows"');

        $this->assertNotFalse($saveFormEnd);
        $this->assertNotFalse($deleteForm);
        $this->assertGreaterThan($saveFormEnd, $deleteForm, 'the delete form must sit outside the save form');
    }

    /** One row saves its link, the other rows stay untouched, then the row deletes itself. */
    public function test_a_standard_guide_is_saved_and_deleted_from_its_own_row(): void
    {
        $relative = 'storage/framework/testing/guides-'.uniqid();
        $absolute = base_path($relative);

        config([
            'connectix_bot.guides.path' => $relative,
            'connectix_bot.guides.custom_path' => $relative.'/custom',
        ]);

        try {
            $this->postingAs()
                ->from(route('admin.guides.index'))
                ->post(route('admin.guides.store'), [
                    'guide_windows_link' => 'https://example.test/windows',
                ])
                ->assertRedirect(route('admin.guides.index'))
                ->assertSessionHas('success');

            $this->assertFileExists($absolute.'/windows.txt');
            $this->assertSame('https://example.test/windows', trim((string) file_get_contents($absolute.'/windows.txt')));
            // The other platforms submitted no content and were left alone
            // rather than refused - the table is also the edit form.
            $this->assertFileDoesNotExist($absolute.'/android.txt');

            $this->postingAs()
                ->from(route('admin.guides.index'))
                ->post(route('admin.guides.destroy'), [
                    'type' => 'platform',
                    'name' => 'windows',
                ])
                ->assertRedirect(route('admin.guides.index'))
                ->assertSessionHas('success');

            $this->assertFileDoesNotExist($absolute.'/windows.txt');
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($absolute);
        }
    }
}

