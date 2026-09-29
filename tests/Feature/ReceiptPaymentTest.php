<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WalletOperation;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use App\Telegram\Handlers\CouponHandler;
use App\Telegram\Handlers\PaymentHandler;
use App\Telegram\Handlers\PurchaseHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The moment a payment receipt photo arrives.
 *
 * Port of the `payment()` call bot.php made from the default branch: an order
 * is written or a wallet deposit is opened, the buyer is told their receipt
 * was received, and the photo is forwarded to every administrator with the
 * approve/reject buttons.
 */
class ReceiptPaymentTest extends TestCase
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
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.card.number' => '6037-9999-0000-0000',
            'connectix_bot.card.name' => 'Acme Corp',
            'connectix_bot.bank.name' => null,
        ]);

        Http::preventStrayRequests();

        $this->fakePanel();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * The whole fake set, built at once so a test can override a single
     * response through refake() without inheriting stale stubs.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakePanel(array $overrides = []): void
    {
        $catalogue = [
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
        ];

        Http::fake(array_merge([
            'https://api.connectix.vip/v1/seller/clients/store' => Http::response(['client_id' => 'new-client-uuid'], 200),
            'https://api.connectix.vip/v1/seller/clients/add-plan' => Http::response(['ok' => true], 200),
            'https://api.connectix.vip/v1/seller/clients/show?id=*' => Http::response([
                'client' => $this->clientPayload(),
            ], 200),
            'https://api.connectix.vip/v1/seller/clients?username=*' => Http::response([
                'clients' => ['data' => [$this->clientPayload()]],
            ], 200),
            'https://api.connectix.vip/v1/seller/seller-plans' => Http::response($catalogue, 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
            'https://t.me/*' => Http::response('<html></html>', 200),
        ], $overrides));
    }

    /**
     * Rebuild the whole fake set with overrides.
     *
     * Http::fake keeps the first stub registered for a URL, so setUp's set
     * would win over a later call; swapping in a fresh factory is what lets a
     * test change one response after the fact.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function refake(array $overrides): void
    {
        Http::swap(new Factory);

        Http::preventStrayRequests();

        $this->fakePanel($overrides);
    }

    private function fakeCoupons(array $coupons): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/seller-plans/coupons' => Http::response(['coupons' => $coupons], 200),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientPayload(string $username = 'acme-user', string $id = 'panel-uuid'): array
    {
        return [
            'id' => $id,
            'username' => $username,
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

    private function makeUser(int $chatId = self::CHAT): User
    {
        return User::query()->create([
            'chat_id' => (string) $chatId,
            'telegram_id' => (string) $chatId,
            'name' => 'Ali',
            'test' => false,
        ]);
    }

    /**
     * Drive the purchase menus all the way to the card screen.
     */
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

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function press(string $data, int $chatId = self::CHAT, ?int $messageId = 42): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'message' => [
                    'message_id' => $messageId,
                    'chat' => ['id' => $chatId],
                    'text' => 'menu',
                ],
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

    private function sentMessages(): array
    {
        $messages = [];

        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() !== 'POST') {
                continue;
            }

            foreach (['sendMessage', 'editMessageText'] as $method) {
                if (str_contains($request->url(), $method)) {
                    $messages[] = (string) $request['text'];
                }
            }
        }

        return $messages;
    }

    /**
     * The caption of the last sendPhoto with a caption.
     */
    private function lastPhotoCaption(): string
    {
        $caption = null;

        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'sendPhoto') && $request['caption'] !== null) {
                $caption = (string) $request['caption'];
            }
        }

        $this->assertIsString($caption, 'no sendPhoto with a caption was recorded');

        return $caption;
    }

    /**
     * The keyboard of the last sendPhoto, decoded.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function lastPhotoKeyboard(): array
    {
        $keyboard = null;

        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'sendPhoto')) {
                $markup = $request['reply_markup'] ?? null;

                if ($markup === null) {
                    continue;
                }

                $keyboard = is_string($markup) ? json_decode($markup, true) : $markup;
            }
        }

        $this->assertIsArray($keyboard, 'no sendPhoto with a reply_markup was recorded');

        return $keyboard['inline_keyboard'] ?? [];
    }

    /**
     * Flatten a keyboard to "text|callback" strings.
     *
     * @return list<string>
     */
    private function labels(array $rows): array
    {
        $found = [];

        foreach ($rows as $row) {
            foreach ($row as $button) {
                $found[] = (string) $button['text'].'|'.(string) ($button['callback_data'] ?? $button['url'] ?? '');
            }
        }

        return $found;
    }

    private function state(User $user): array
    {
        return $this->app->make(UserStateService::class)->get($user) ?? [];
    }

    // -----------------------------------------------------------------
    // Card purchase receipt
    // -----------------------------------------------------------------

    public function test_a_card_receipt_writes_the_pending_order_and_forwards_the_photo(): void
    {
        $user = $this->makeUser();

        $this->startCardPurchase($user);

        $this->app->make(PaymentHandler::class)->handle($this->photo(), $user);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame('new', $payment->client_id);
        $this->assertSame('11', $payment->plan_id);
        $this->assertSame('120,000', $payment->price);
        $this->assertSame(PaymentMethod::Card, $payment->method);
        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
        $this->assertNull($payment->coupon);
        $this->assertStringStartsWith('CX', (string) $payment->order_number);

        // The buyer is told the receipt was received.
        $this->assertStringContainsString(
            '✅ سند پرداخت شما با موفقیت دریافت شد.',
            implode("\n", $this->sentMessages()),
        );

        // The last photo size is what the administrators get, with buttons.
        $photo = null;
        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'sendPhoto')) {
                $photo = $request['photo'];
            }
        }
        $this->assertSame('AgAC-receipt', $photo);

        $this->assertStringContainsString('📃 سند واریزی مورد تایید میباشد?', $this->lastPhotoCaption());

        $this->assertSame(
            ["❌ |  رد|payment_reject:{$payment->id}", "✅ |  تایید|payment_accept:{$payment->id}"],
            $this->labels($this->lastPhotoKeyboard()),
        );

        $this->assertSame([], $this->state($user));
    }

    public function test_a_non_photo_message_at_the_receipt_step_is_refused(): void
    {
        $user = $this->makeUser();

        $this->startCardPurchase($user);

        $this->app->make(PaymentHandler::class)->handle($this->text('not a photo'), $user);

        $this->assertStringContainsString(
            '🖼️ لطفا سند واریزی را به صورت تصویر ارسال کنید!',
            implode("\n", $this->sentMessages()),
        );

        $this->assertSame(0, Payment::query()->count());
    }

    public function test_the_admin_receipt_carries_the_coupon_details(): void
    {
        $this->fakeCoupons([[
            'coupon_code' => 'SAVE10',
            'is_active' => true,
            'is_applied_to_all_plans' => true,
            'per_cent' => 10,
            'start_date_text' => null,
            'end_date_text' => null,
        ]]);

        $user = $this->makeUser();

        $this->startCardPurchase($user);

        $this->app->make(PurchaseHandler::class)->handle($this->press('discount_set:120,000'), $user);
        $this->app->make(CouponHandler::class)->handle($this->text('SAVE10'), $user);

        $this->app->make(PaymentHandler::class)->handle($this->photo(), $user);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame('108,000', $payment->price);
        $this->assertSame('SAVE10', $payment->coupon);

        $caption = $this->lastPhotoCaption();
        $this->assertStringContainsString('💸 مبلغ واریزی: 108,000', $caption);
        $this->assertStringContainsString('💵 مبلغ اصلی: 120,000', $caption);
        $this->assertStringContainsString('🎟 کد تخفیف استفاده شده: SAVE10', $caption);
    }

    public function test_a_renewal_receipt_is_charged_to_the_existing_client(): void
    {
        $user = $this->makeUser();

        $this->app->make(WalletService::class)->create((string) $user->chat_id, 0);

        $this->app->make(UserStateService::class)->set($user, [
            'action' => UserStateService::ACTION_PAY,
            'step' => 'pay',
            'pay' => 'card',
            'acc' => 'acme-user',
            'group' => 'default',
            'plan' => '11',
            'price' => '120,000',
        ]);

        $this->app->make(PaymentHandler::class)->handle($this->photo(), $user);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame('panel-uuid', $payment->client_id);
        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
    }

    /**
     * Telegram goes down between the card screen and the receipt. The photo
     * is the buyer's proof of payment: the order must be written and the
     * conversation closed even though neither the confirmation nor the
     * forward to the administrators could be delivered.
     */
    public function test_a_telegram_outage_still_writes_the_order_and_clears_the_state(): void
    {
        $user = $this->makeUser();

        $this->startCardPurchase($user);

        $this->refake([
            'https://api.telegram.org/*' => Http::response(
                ['ok' => false, 'description' => 'internal server error'],
                200,
            ),
        ]);

        $this->app->make(PaymentHandler::class)->handle($this->photo(), $user);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame(PaymentStatus::Pending, $payment->is_paid);
        $this->assertStringStartsWith('CX', (string) $payment->order_number);
        $this->assertSame([], $this->state($user));
    }

    /**
     * A duplicate photo after the receipt step is over (Telegram redelivers,
     * or the buyer sends another picture) must not open a second order.
     */
    public function test_a_second_card_receipt_after_the_state_is_cleared_creates_no_second_order(): void
    {
        $user = $this->makeUser();

        $this->startCardPurchase($user);

        $handler = $this->app->make(PaymentHandler::class);

        $handler->handle($this->photo(), $user);
        $handler->handle($this->photo('AgAC-second'), $user);

        $this->assertSame(1, Payment::query()->count());
        $this->assertSame([], $this->state($user));
    }

    // -----------------------------------------------------------------
    // Wallet top-up receipt
    // -----------------------------------------------------------------

    public function test_a_wallet_deposit_opens_a_pending_transaction_and_forwards_the_photo(): void
    {
        $user = $this->makeUser();

        $wallet = $this->app->make(WalletService::class)->create((string) $user->chat_id, 0);
        $user->forceFill(['action' => json_encode([
            'action' => UserStateService::ACTION_WALLET_INCREASE,
            'step' => 'get_receipt',
            'amount' => 50000,
        ], JSON_UNESCAPED_UNICODE)])->save();

        $this->app->make(PaymentHandler::class)->handle($this->photo(), $user);

        $transaction = WalletTransaction::query()->firstOrFail();

        $this->assertSame($wallet->id, $transaction->wallet_id);
        $this->assertSame(50000, (int) $transaction->amount);
        $this->assertSame(WalletOperation::Increase, $transaction->operation);
        $this->assertSame(WalletTransactionStatus::Pending, $transaction->status);
        $this->assertSame(WalletTransactionType::CardToCard, $transaction->type);

        $this->assertStringContainsString(
            '✅ سند پرداخت شما با موفقیت دریافت شد.',
            implode("\n", $this->sentMessages()),
        );

        $caption = $this->lastPhotoCaption();
        $this->assertStringContainsString('📃 سند واریزی مورد تایید میباشد?', $caption);
        $this->assertStringContainsString('🔢 آیدی: <code>555</code>', $caption);
        $this->assertStringContainsString('💵 مبلغ : 50,000', $caption);

        $this->assertSame(
            ["❌ |  رد|wallet_reject:{$transaction->id}", "✅ |  تایید|wallet_accept:{$transaction->id}"],
            $this->labels($this->lastPhotoKeyboard()),
        );

        $state = $this->state($user);
        $this->assertSame('pending', $state['step']);
        $this->assertSame($transaction->id, $state['txID']);
    }

    public function test_a_pending_wallet_deposit_ignores_a_second_photo(): void
    {
        $user = $this->makeUser();

        $this->app->make(WalletService::class)->create((string) $user->chat_id, 0);
        $user->forceFill(['action' => json_encode([
            'action' => UserStateService::ACTION_WALLET_INCREASE,
            'step' => 'get_receipt',
            'amount' => 50000,
        ], JSON_UNESCAPED_UNICODE)])->save();

        $this->app->make(PaymentHandler::class)->handle($this->photo(), $user);
        $this->app->make(PaymentHandler::class)->handle($this->photo('AgAC-second'), $user);

        $this->assertSame(1, WalletTransaction::query()->count());
    }

    public function test_a_non_numeric_and_a_too_small_wallet_amount_are_refused(): void
    {
        $user = $this->makeUser();

        $this->app->make(UserStateService::class)->startWalletIncrease($user);

        $this->app->make(PaymentHandler::class)->handle($this->text('fifty'), $user);

        $this->assertStringContainsString(
            '🔢 لطفا مبلغ را به صورت اعداد انگلیسی وارد کنید!',
            implode("\n", $this->sentMessages()),
        );

        $this->app->make(PaymentHandler::class)->handle($this->text('500'), $user);

        $this->assertStringContainsString(
            'حداقل مبلغ واریزی 10,000 تومان است!',
            implode("\n", $this->sentMessages()),
        );

        $state = $this->state($user);
        $this->assertSame('get_amount', $state['step']);
        $this->assertNull($state['amount'] ?? null);
    }

    public function test_a_valid_wallet_amount_moves_to_the_receipt_step(): void
    {
        $user = $this->makeUser();

        $this->app->make(UserStateService::class)->startWalletIncrease($user);

        $this->app->make(PaymentHandler::class)->handle($this->text('50000'), $user);

        $state = $this->state($user);
        $this->assertSame(UserStateService::ACTION_WALLET_INCREASE, $state['action']);
        $this->assertSame('get_receipt', $state['step']);
        $this->assertSame(50000, $state['amount']);

        $this->assertStringContainsString(
            '💳 شماره کارت: 6037-9999-0000-0000',
            implode("\n", $this->sentMessages()),
        );
    }
}
