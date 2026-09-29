<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentStatus;
use App\Enums\SmsPaymentType;
use App\Enums\WalletTransactionStatus;
use App\Models\Payment;
use App\Models\SmsPayment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\Payment\SmsPaymentService;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use App\Telegram\Handlers\PaymentHandler;
use App\Telegram\Handlers\PurchaseHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Bank SMS deposits: the webhook that stores them, and the auto-payment that
 * settles an order the moment a receipt for the exact amount arrives.
 *
 * Port of `bank/sms.php` and the `smsPayment()` auto-payment branches of
 * `payment()` in functions.php.
 */
class BankSmsTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 555;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.active' => true,
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.admin_ids' => ['1'],
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.card.number' => '6037-9999-0000-0000',
            'connectix_bot.card.name' => 'Acme Corp',
            'connectix_bot.bank.bot_notice' => true,
            'connectix_bot.wallet.minimum_deposit' => 10000,
        ]);

        Http::preventStrayRequests();
    }

    // -----------------------------------------------------------------
    // Webhook
    // -----------------------------------------------------------------

    public function test_the_webhook_stores_the_deposit_and_answers_202_with_the_amount(): void
    {
        $this->setBank('blu');

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $response = $this->postJson('/bank/sms', [
            'msg' => 'بلو بانک | واریز 100,000 ریال | از سوی علی',
        ]);

        $response->assertStatus(202);
        $response->assertJson([
            'status' => 'success',
            'data' => ['amount' => '10,000', 'bank' => 'blu'],
        ]);

        $sms = SmsPayment::query()->firstOrFail();

        $this->assertSame(10000, (int) $sms->amount);
        $this->assertSame('blu', $sms->bank);
        $this->assertSame('بلو بانک | واریز 100,000 ریال | از سوی علی', $sms->message);
        $this->assertNotNull($sms->expired_at);
        $this->assertTrue($sms->expired_at->greaterThan(now()->addMinutes(4)));
    }

    public function test_the_webhook_rejects_everything_but_a_json_post(): void
    {
        $this->setBank('blu');

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->getJson('/bank/sms')->assertStatus(405);

        $this->post('/bank/sms', ['msg' => '100,000 ریال'], ['Content-Type' => 'text/plain'])
            ->assertStatus(406);

        $this->postJson('/bank/sms', [])->assertStatus(400)->assertJson([
            'message' => 'Missing required field [msg]',
        ]);

        $this->assertSame(0, SmsPayment::query()->count());
    }

    public function test_the_webhook_rejects_a_message_without_a_parseable_amount(): void
    {
        $this->setBank('blu');

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->postJson('/bank/sms', ['msg' => 'یک پیام بدون مبلغ'])->assertStatus(500)->assertJson([
            'message' => 'Amount not found in message',
        ]);

        $this->assertSame(0, SmsPayment::query()->count());
    }

    public function test_the_webhook_rejects_a_non_positive_amount(): void
    {
        $this->setBank('blu');

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->postJson('/bank/sms', ['msg' => '5 ریال'])->assertStatus(400)->assertJson([
            'message' => 'Amount must be greater than 0',
        ]);

        $this->assertSame(0, SmsPayment::query()->count());
    }

    public function test_the_webhook_rejects_a_body_when_no_bank_is_configured(): void
    {
        config(['connectix_bot.bank.name' => null]);

        $this->postJson('/bank/sms', ['msg' => '100,000 ریال'])->assertStatus(400)->assertJson([
            'message' => 'Bank method not configured properly, please check admin panel settings.',
        ]);
    }

    public function test_the_webhook_notifies_the_first_administrator_when_bot_notice_is_on(): void
    {
        $this->setBank('blu');
        config(['connectix_bot.bank.bot_notice' => true]);

        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true], 200)]);

        $this->postJson('/bank/sms', ['msg' => '100,000 ریال'])->assertStatus(202);

        $message = null;
        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'sendMessage')) {
                $message = $request['text'];
            }
        }

        $this->assertIsString($message);
        $this->assertStringContainsString('New SMS Payment Received', $message);
        $this->assertStringContainsString('10,000 Toman', $message);
    }

    // -----------------------------------------------------------------
    // Deposit parsing and matching
    // -----------------------------------------------------------------

    public function test_parse_amount_extracts_toman_out_of_the_bank_pattern(): void
    {
        $this->setBank('blu');

        $service = $this->app->make(SmsPaymentService::class);

        $this->assertSame(120000, $service->parseAmount('مبلغ 1,200,000 ریال'));
        $this->assertSame(50000, $service->parseAmount('500,000 ریال'));
        $this->assertSame(90, $service->parseAmount('900 ریال'));
        $this->assertSame(1, $service->parseAmount('10 ریال'));
        $this->assertNull($service->parseAmount('بدون مبلغ'));
    }

    public function test_claim_returns_the_only_available_deposit_for_the_amount(): void
    {
        $this->setBank('blu');

        $service = $this->app->make(SmsPaymentService::class);
        $service->record('100,000 ریال', 10000, 'blu');

        $claim = $service->claim(10000);

        $this->assertNotNull($claim);
        $this->assertSame(10000, (int) $claim->amount);

        // Anything else finds nothing.
        $this->assertNull($service->claim(20000));
    }

    public function test_claim_refuses_an_ambiguous_amount(): void
    {
        $this->setBank('blu');

        $service = $this->app->make(SmsPaymentService::class);
        $service->record('اولی 100,000 ریال', 10000, 'blu');
        $service->record('دومی 100,000 ریال', 10000, 'blu');

        $this->assertNull($service->claim(10000));
    }

    public function test_claim_ignores_matched_and_expired_deposits(): void
    {
        $this->setBank('blu');

        $service = $this->app->make(SmsPaymentService::class);

        $matched = $service->record('اولی 100,000 ریال', 10000, 'blu');
        $service->link($matched, 7, SmsPaymentType::Buy);

        $expired = $service->record('دومی 100,000 ریال', 10000, 'blu');
        $expired->forceFill(['expired_at' => now()->subMinute()])->save();

        $this->assertNull($service->claim(10000));
    }

    public function test_link_is_conditional_so_a_deposit_is_never_used_twice(): void
    {
        $this->setBank('blu');

        $service = $this->app->make(SmsPaymentService::class);
        $sms = $service->record('100,000 ریال', 10000, 'blu');

        $this->assertTrue($service->link($sms, 7, SmsPaymentType::Buy));

        $sms->refresh();

        $this->assertSame('7', $sms->payment_id);
        $this->assertSame(SmsPaymentType::Buy, $sms->payment_type);

        $this->assertFalse($service->link($sms, 8, SmsPaymentType::Buy));
    }

    public function test_prune_deletes_only_unmatched_expired_deposits(): void
    {
        $this->setBank('blu');

        $service = $this->app->make(SmsPaymentService::class);

        // Bring both rows out of their claim window before the prune runs;
        // creating them after it would give the prune nothing left to delete.
        $matched = $service->record('قدیمی 100,000 ریال', 10000, 'blu');
        $service->link($matched, 7, SmsPaymentType::Buy);
        $matched->forceFill(['expired_at' => now()->subMinute()])->save();

        $unmatched = $service->record('بدون خریدار 100,000 ریال', 10000, 'blu');
        $unmatched->forceFill(['expired_at' => now()->subMinute()])->save();

        $this->assertSame(1, $service->pruneExpired());

        $this->assertDatabaseMissing('sms_payments', ['id' => $unmatched->id]);
        $this->assertDatabaseHas('sms_payments', ['id' => $matched->id]);

        // A fresh deposit created after the prune is untouched.
        $fresh = $service->record('تازه 100,000 ریال', 10000, 'blu');
        $this->assertDatabaseHas('sms_payments', ['id' => $fresh->id]);
    }

    // -----------------------------------------------------------------
    // Auto-payment
    // -----------------------------------------------------------------

    public function test_a_unique_deposit_settles_a_card_order_without_the_admins(): void
    {
        $this->setBank('blu');

        $user = $this->makeUser();

        $this->fakePanel();

        $this->startCardPurchase($user);

        $this->app->make(SmsPaymentService::class)->record('1,200,000 ریال', 120000, 'blu');

        $this->app->make(PaymentHandler::class)->handle($this->photo(), $user);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(PaymentStatus::Paid, $payment->is_paid);
        $this->assertSame(0, $this->sendPhotoCount(), 'no receipt should be forwarded to the admins');

        // The account was provisioned and the buyer was told about it.
        $this->assertStringContainsString('اکانت شما با موفقیت ایجاد شد.', implode("\n", $this->sentMessages()));

        // The deposit is now the audit trail of the order.
        $sms = SmsPayment::query()->firstOrFail();
        $this->assertSame((string) $payment->id, $sms->payment_id);
        $this->assertSame(SmsPaymentType::Buy, $sms->payment_type);

        $this->assertNull($this->app->make(UserStateService::class)->get($user));
    }

    public function test_an_ambiguous_deposit_falls_back_to_admin_approval(): void
    {
        $this->setBank('blu');

        $user = $this->makeUser();

        $this->fakePanel();

        $this->startCardPurchase($user);

        $service = $this->app->make(SmsPaymentService::class);
        $service->record('اولی 1,200,000 ریال', 120000, 'blu');
        $service->record('دومی 1,200,000 ریال', 120000, 'blu');

        $this->app->make(PaymentHandler::class)->handle($this->photo(), $user);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
        $this->assertSame(1, $this->sendPhotoCount());
    }

    public function test_a_unique_deposit_credits_a_wallet_top_up_automatically(): void
    {
        $this->setBank('blu');

        $user = $this->makeUser();

        $wallet = $this->app->make(WalletService::class)->create((string) $user->chat_id, 0);

        $this->app->make(UserStateService::class)->startWalletIncrease($user);

        $this->fakePanel();

        $this->app->make(PaymentHandler::class)->handle($this->text('50000'), $user);

        $this->app->make(SmsPaymentService::class)->record('500,000 ریال', 50000, 'blu');

        $this->app->make(PaymentHandler::class)->handle($this->photo(), $user);

        $transaction = WalletTransaction::query()->firstOrFail();

        $this->assertSame(WalletTransactionStatus::Success, $transaction->status);

        $wallet->refresh();
        $this->assertSame(50000, $wallet->balanceAmount());

        $this->assertSame(0, $this->sendPhotoCount());

        $sms = SmsPayment::query()->firstOrFail();
        $this->assertSame((string) $transaction->id, $sms->payment_id);
        $this->assertSame(SmsPaymentType::Wallet, $sms->payment_type);

        $this->assertStringContainsString(
            '✅ تراکنش شما جهت افزایش موجودی کیف پول تایید شد.',
            implode("\n", $this->sentMessages()),
        );

        $this->assertNull($this->app->make(UserStateService::class)->get($user));
    }

    // -----------------------------------------------------------------
    // Fixtures and helpers
    // -----------------------------------------------------------------

    private function setBank(string $name): void
    {
        config(['connectix_bot.bank.name' => $name]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientPayload(): array
    {
        return [
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
        ];
    }

    private function fakePanel(): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/store' => Http::response(['client_id' => 'new-client-uuid'], 200),
            'https://api.connectix.vip/v1/seller/clients/add-plan' => Http::response(['ok' => true], 200),
            'https://api.connectix.vip/v1/seller/clients/show?id=*' => Http::response(['client' => $this->clientPayload()], 200),
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
        ]);
    }

    private function makeUser(): User
    {
        return User::query()->create([
            'chat_id' => (string) self::CHAT,
            'telegram_id' => (string) self::CHAT,
            'name' => 'Ali',
            'test' => false,
        ]);
    }

    private function startCardPurchase(User $user): void
    {
        $purchase = $this->app->make(PurchaseHandler::class);

        $purchase->handle($this->press('buy'), $user);
        $purchase->handle($this->press('group'), $user);
        $purchase->handle($this->press('buy_group:default'), $user);
        $purchase->handle($this->press('buy_count:1'), $user);
        $purchase->handle($this->press('buy_plan:11'), $user);
        $purchase->handle($this->press('pay_card:120,000'), $user);
    }

    private function press(string $data, int $chatId = self::CHAT): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'message' => ['message_id' => 42, 'chat' => ['id' => $chatId], 'text' => 'menu'],
                'data' => $data,
            ],
        ]);
    }

    private function text(string $body, int $chatId = self::CHAT): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'message' => [
                'message_id' => 7,
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'chat' => ['id' => $chatId, 'username' => 'ali'],
                'text' => $body,
            ],
        ]);
    }

    private function photo(string $fileId = 'AgAC-receipt', int $chatId = self::CHAT): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'message' => [
                'message_id' => 7,
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'chat' => ['id' => $chatId, 'username' => 'ali'],
                'photo' => [
                    ['file_id' => 'small', 'file_unique_id' => 'sm', 'width' => 90, 'height' => 90],
                    ['file_id' => $fileId, 'file_unique_id' => 'lg', 'width' => 1080, 'height' => 1080],
                ],
            ],
        ]);
    }

    private function sendPhotoCount(): int
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), 'sendPhoto'))
            ->count();
    }

    private function sentMessages(): array
    {
        return collect(Http::recorded())
            ->filter(fn (array $pair) => $pair[0]->method() === 'POST' && str_contains($pair[0]->url(), 'sendMessage'))
            ->map(fn (array $pair) => (string) $pair[0]['text'])
            ->values()
            ->all();
    }
}
