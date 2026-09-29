<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WalletTransactionStatus;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Services\Payment\PaymentService;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use App\Telegram\Handlers\AdminHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The two buttons on a forwarded receipt: approve and reject.
 *
 * Port of the `paycheck()` and `walletReqs()` accept/reject branches. An
 * acceptance provisions the account (or credits the wallet), a rejection marks
 * the order and tells the buyer, and a decision is only ever carried out once.
 */
class AdminApprovalTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 555;

    private const ADMIN_ID = 1;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.active' => true,
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.admin_ids' => [(string) self::ADMIN_ID],
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.bank.name' => null,
            'connectix_bot.wallet.minimum_deposit' => 10000,
        ]);

        Http::preventStrayRequests();

        $this->fakePanel();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * The whole fake set, built at once so refake() can override a single
     * response for a failure test.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakePanel(array $overrides = []): void
    {
        Http::fake(array_merge([
            'https://api.connectix.vip/v1/seller/clients/store' => Http::response(['client_id' => 'new-client-uuid'], 200),
            'https://api.connectix.vip/v1/seller/clients/add-plan' => Http::response(['ok' => true], 200),
            'https://api.connectix.vip/v1/seller/clients/show?id=*' => Http::response([
                'client' => [
                    'id' => 'acme-uuid',
                    'username' => 'acme-user',
                    'password' => 'pass1234',
                    'count_of_devices' => 1,
                    'subscription_link' => 'https://sub.example/abc',
                    'plans' => [[
                        'name' => '(1x) Unlimited-1M',
                        'is_active' => true,
                        'is_in_queue' => false,
                    ]],
                ],
            ], 200),
            'https://api.connectix.vip/v1/seller/seller-plans' => Http::response([
                'groups' => [['name' => 'default']],
                'seller_plan_group' => [
                    [
                        'name' => 'default',
                        'seller_plans' => [
                            [
                                'id' => 11,
                                'type' => 'Premium',
                                'is_displayed_in_robot' => true,
                                'group_name_translations' => ['en' => 'default'],
                                'title' => '(1x) Unlimited-1M',
                                'count_of_devices' => 1,
                                'sell_price' => '120,000',
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ], $overrides));
    }

    /**
     * Rebuild the whole fake set with overrides: Http::fake keeps the first
     * stub registered for a URL, so setUp's set would win over a later call.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function refake(array $overrides): void
    {
        Http::swap(new Factory);

        Http::preventStrayRequests();

        $this->fakePanel($overrides);
    }

    private function makeBuyer(): User
    {
        return User::query()->create([
            'chat_id' => (string) self::CHAT,
            'telegram_id' => (string) self::CHAT,
            'name' => 'Ali',
            'test' => false,
        ]);
    }

    private function makePayment(): Payment
    {
        return $this->app->make(PaymentService::class)->create(
            chatId: self::CHAT,
            clientId: 'new',
            planId: '11',
            price: '120,000',
            method: PaymentMethod::Card,
        ) ?? $this->fail('payment could not be created');
    }

    private function makePendingDeposit(int $amount = 50000): array
    {
        $user = $this->makeBuyer();
        $wallet = $this->app->make(WalletService::class)->create((string) $user->chat_id, 0);

        $transaction = $this->app->make(WalletService::class)->createPendingDeposit(
            (string) $user->chat_id,
            $amount,
        ) ?? $this->fail('deposit could not be created');

        $this->app->make(UserStateService::class)->set($user, [
            'action' => UserStateService::ACTION_WALLET_INCREASE,
            'step' => 'pending',
            'amount' => $amount,
            'txID' => $transaction->id,
        ]);

        return [$user, $wallet, $transaction];
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * A callback query pressed from the administrator's own chat.
     */
    private function pressAs(string $data, int $chatId = self::CHAT, ?int $fromId = self::ADMIN_ID): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-admin',
                'from' => ['id' => $fromId, 'is_bot' => false, 'first_name' => 'Admin'],
                'message' => [
                    'message_id' => 42,
                    'chat' => ['id' => $chatId],
                    'text' => 'receipt',
                ],
                'data' => $data,
            ],
        ]);
    }

    private function recorded(): Collection
    {
        return collect(Http::recorded())->map(fn (array $pair) => $pair[0]);
    }

    private function editCaptions(): array
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'editMessageCaption'))
            ->map(fn ($request) => (string) $request['caption'])
            ->values()
            ->all();
    }

    private function lastEditCaption(): string
    {
        $captions = $this->editCaptions();

        $this->assertNotSame([], $captions, 'no editMessageCaption was recorded');

        return $captions[array_key_last($captions)];
    }

    private function answerCallbackTexts(): array
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'answerCallbackQuery'))
            ->map(fn ($request) => (string) $request['text'])
            ->values()
            ->all();
    }

    private function sentMessages(): array
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'sendMessage'))
            ->map(fn ($request) => (string) $request['text'])
            ->values()
            ->all();
    }

    private function storeCalls(): int
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'clients/store'))
            ->count();
    }

    // -----------------------------------------------------------------
    // Purchase decisions
    // -----------------------------------------------------------------

    public function test_an_admin_acceptance_provisions_the_account_and_updates_the_caption(): void
    {
        $buyer = $this->makeBuyer();
        $payment = $this->makePayment();

        $this->app->make(AdminHandler::class)->handle($this->pressAs('payment_accept:'.$payment->id), $buyer);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->is_paid);
        $this->assertSame(1, $this->storeCalls());

        // The buyer gets a confirmation, a fresh account message and a badge.
        $this->assertStringContainsString(
            'اکانت شما با موفقیت ایجاد شد.',
            implode("\n", $this->sentMessages()),
        );

        // The receipt photo caption is replaced by the decision.
        $this->assertStringContainsString('✅ سفارش شماره <code>'.$payment->order_number.'</code> با موفقیت تایید شد', $this->lastEditCaption());
        $this->assertStringContainsString('👤 نام کاربری اکانت: <code>acme-user</code>', $this->lastEditCaption());
    }

    public function test_a_second_acceptance_only_reports_the_current_status(): void
    {
        $buyer = $this->makeBuyer();
        $payment = $this->makePayment();

        $handler = $this->app->make(AdminHandler::class);

        $handler->handle($this->pressAs('payment_accept:'.$payment->id), $buyer);
        $handler->handle($this->pressAs('payment_accept:'.$payment->id), $buyer);

        // One provision, not two.
        $this->assertSame(1, $this->storeCalls());

        $this->assertStringContainsString('⚠️ سفارش شماره <code>'.$payment->order_number.'</code> در وضعیت تایید شده است. ', $this->lastEditCaption());
    }

    public function test_an_admin_rejection_marks_the_order_and_notifies_the_buyer(): void
    {
        $buyer = $this->makeBuyer();
        $payment = $this->makePayment();

        $this->app->make(AdminHandler::class)->handle($this->pressAs('payment_reject:'.$payment->id), $buyer);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Rejected, $payment->is_paid);
        $this->assertSame(0, $this->storeCalls());

        $this->assertStringContainsString(
            "❌پرداخت شما تایید نشد.\n🛍 شماره سفارش: <code>{$payment->order_number}</code>",
            implode("\n", $this->sentMessages()),
        );

        $this->assertStringContainsString('❌ سفارش شماره <code>'.$payment->order_number.'</code> تایید نشد', $this->lastEditCaption());
        $this->assertStringContainsString('💵 مبلغ: 120,000', $this->lastEditCaption());
    }

    public function test_a_non_admin_is_silently_ignored(): void
    {
        $buyer = $this->makeBuyer();
        $payment = $this->makePayment();

        $this->app->make(AdminHandler::class)->handle(
            $this->pressAs('payment_accept:'.$payment->id, self::CHAT, 999),
            $buyer,
        );

        $payment->refresh();

        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
        $this->assertSame(0, $this->storeCalls());
        $this->assertSame([], $this->editCaptions());
    }

    public function test_a_missing_order_is_answered_with_an_alert(): void
    {
        $buyer = $this->makeBuyer();

        $this->app->make(AdminHandler::class)->handle($this->pressAs('payment_accept:999'), $buyer);

        $this->assertSame(['رکورد مورد نظر یافت نشد.'], $this->answerCallbackTexts());
        $this->assertSame([], $this->editCaptions());
    }

    public function test_the_not_button_answers_with_the_legacy_shrug(): void
    {
        $buyer = $this->makeBuyer();

        $this->app->make(AdminHandler::class)->handle($this->pressAs('not'), $buyer);

        $this->assertSame(['🤷🏻 این دکمه کاری انجام نمیده'], $this->answerCallbackTexts());
    }

    // -----------------------------------------------------------------
    // Failures during the decision
    // -----------------------------------------------------------------

    /**
     * The panel refuses to create the client while the administrator
     * approves. The money is taken but the account does not exist, so the
     * order stays open for a retry; only the buyer is told what happened.
     *
     * Legacy stopped at a log line and left the receipt untouched - and left
     * the buyer silent about a payment it had already taken. The caption
     * stays untouched here too; the admin alert is what legacy never had.
     */
    public function test_a_panel_failure_during_acceptance_leaves_the_order_pending(): void
    {
        $this->refake([
            'https://api.connectix.vip/v1/seller/clients/store' => Http::response(
                ['message' => 'server error'],
                500,
            ),
        ]);

        $buyer = $this->makeBuyer();
        $payment = $this->makePayment();

        $this->app->make(AdminHandler::class)->handle($this->pressAs('payment_accept:'.$payment->id), $buyer);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
        $this->assertSame('new', $payment->client_id);

        $this->assertStringContainsString(
            '❌ ساخت اکانت شما با خطا مواجه شد.',
            implode("\n", $this->sentMessages()),
        );

        $this->assertSame([], $this->editCaptions());
        $this->assertSame(['رکورد مورد نظر یافت نشد.'], $this->answerCallbackTexts());
    }

    /**
     * Same for a renewal: the order only ever moves to paid once the panel
     * accepted the plan, so a failed add-plan keeps it pending and the
     * administrator can approve again once the panel recovers.
     */
    public function test_an_add_plan_failure_during_a_renewal_leaves_the_order_pending(): void
    {
        $this->refake([
            'https://api.connectix.vip/v1/seller/clients/add-plan' => Http::response(
                ['message' => 'server error'],
                500,
            ),
        ]);

        $buyer = $this->makeBuyer();

        $payment = $this->app->make(PaymentService::class)->create(
            chatId: self::CHAT,
            clientId: 'acme-uuid',
            planId: '11',
            price: '120,000',
            method: PaymentMethod::Card,
        ) ?? $this->fail('payment could not be created');

        $this->app->make(AdminHandler::class)->handle($this->pressAs('payment_accept:'.$payment->id), $buyer);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
        $this->assertSame('acme-uuid', $payment->client_id);

        $this->assertStringContainsString(
            '❌ ساخت اکانت شما با خطا مواجه شد.',
            implode("\n", $this->sentMessages()),
        );

        $this->assertSame([], $this->editCaptions());
    }

    /**
     * The account exists by the time the buyer is told about it: when
     * Telegram cannot deliver that message, the order must still end up
     * paid, because a lost notification cannot undo a provisioned client.
     */
    public function test_a_failed_credential_delivery_still_marks_the_order_paid(): void
    {
        $this->refake([
            'https://api.telegram.org/bottest-token/sendMessage' => Http::response(
                ['ok' => false, 'description' => 'internal server error'],
                200,
            ),
        ]);

        $buyer = $this->makeBuyer();
        $payment = $this->makePayment();

        $this->app->make(AdminHandler::class)->handle($this->pressAs('payment_accept:'.$payment->id), $buyer);

        $payment->refresh();

        $this->assertSame(PaymentStatus::Paid, $payment->is_paid);
        $this->assertSame('new-client-uuid', $payment->client_id);
        $this->assertSame(1, $this->storeCalls());

        $this->assertStringContainsString(
            '✅ سفارش شماره <code>'.$payment->order_number.'</code> با موفقیت تایید شد',
            $this->lastEditCaption(),
        );
    }

    // -----------------------------------------------------------------
    // Wallet deposit decisions
    // -----------------------------------------------------------------

    public function test_an_admin_wallet_acceptance_credits_the_balance_and_confirms_the_buyer(): void
    {
        [$user, $wallet, $transaction] = $this->makePendingDeposit();

        $this->app->make(AdminHandler::class)->handle($this->pressAs('wallet_accept:'.$transaction->id), $user);

        $transaction->refresh();
        $wallet->refresh();

        $this->assertSame(WalletTransactionStatus::Success, $transaction->status);
        $this->assertSame(50000, $wallet->balanceAmount());

        $this->assertStringContainsString(
            '✅ تراکنش شما جهت افزایش موجودی کیف پول تایید شد.',
            implode("\n", $this->sentMessages()),
        );

        $this->assertStringContainsString('✅ شماره تراکنش '.$transaction->id.' با موفقیت تایید شد.', $this->lastEditCaption());
        $this->assertStringContainsString('👝 شماره کیف پول: '.$wallet->id, $this->lastEditCaption());
        $this->assertStringContainsString('💵 مبلغ: 50,000', $this->lastEditCaption());

        // The buyer's deposit conversation is over; the instance in the test
        // holds the pre-decision attribute, so it must be refreshed first.
        $user->refresh();

        $this->assertNull($this->app->make(UserStateService::class)->get($user));
    }

    public function test_a_second_wallet_acceptance_never_credits_twice(): void
    {
        [$user, $wallet, $transaction] = $this->makePendingDeposit();

        $handler = $this->app->make(AdminHandler::class);

        $handler->handle($this->pressAs('wallet_accept:'.$transaction->id), $user);
        $handler->handle($this->pressAs('wallet_accept:'.$transaction->id), $user);

        $wallet->refresh();

        $this->assertSame(50000, $wallet->balanceAmount());
        $this->assertStringContainsString(
            "⚠️ تراکنش شماره {$transaction->id} در وضعیت تایید شده است.",
            $this->lastEditCaption(),
        );
    }

    public function test_an_admin_wallet_rejection_never_credits_the_balance(): void
    {
        [$user, $wallet, $transaction] = $this->makePendingDeposit();

        $this->app->make(AdminHandler::class)->handle($this->pressAs('wallet_reject:'.$transaction->id), $user);

        $transaction->refresh();
        $wallet->refresh();

        $this->assertSame(WalletTransactionStatus::RejectedByAdmin, $transaction->status);
        $this->assertSame(0, $wallet->balanceAmount());

        $this->assertStringContainsString(
            '❌ تراکنش شما جهت افزایش موجودی کیف پول رد شد.',
            implode("\n", $this->sentMessages()),
        );

        $this->assertStringContainsString('❌ شماره تراکنش '.$transaction->id.'  رد شد.', $this->lastEditCaption());
    }
}
