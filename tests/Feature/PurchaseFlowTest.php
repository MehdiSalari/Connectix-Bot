<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\WalletOperation;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Models\Client;
use App\Models\Payment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Plan\PlanService;
use App\Services\Telegram\MessageFactory;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use App\Telegram\HandlerRegistry;
use App\Telegram\Handlers\AddAccountHandler;
use App\Telegram\Handlers\CouponHandler;
use App\Telegram\Handlers\MainMenuHandler;
use App\Telegram\Handlers\PurchaseHandler;
use App\Telegram\Handlers\RenewHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The purchase and renewal flows, driven through the container the way the
 * gateway drives them.
 *
 * The panel catalogue is faked rather than stubbed on the service, because the
 * whole point of these flows is that what the panel says decides the keyboard.
 */
class PurchaseFlowTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 555;

    /**
     * Whether the panel refuses to create a client, so the failure path can be
     * driven without re-faking, which would be shadowed by the setUp stubs.
     */
    private bool $storeFails = false;

    /**
     * What `show?id` answers with. Re-faking that URL would be shadowed by the
     * setUp stub (the first registered pattern wins), so the stub reads this
     * property per request and a test may swap it in mid-flight.
     *
     * @var array<string, mixed>|null
     */
    private ?array $panelClient = null;

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
        ]);

        Http::preventStrayRequests();

        $this->fakePanel();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * A catalogue with two sellable plans in one group and one hidden plan that
     * must never reach a keyboard.
     *
     * `Http::fake()` matches in registration order and a pattern without a `*`
     * is matched as a suffix, so the specific endpoints are registered first:
     * a stub for `/seller-plans` would otherwise also swallow
     * `/seller-plans/coupons`.
     */
    private function fakePanel(array $clients = []): void
    {
        $this->panelClient = $clients === [] ? $this->clientPayload() : $clients;

        $catalogue = [
            // `groups` is what the group menu is built from, alongside the
            // flattened plan list.
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
                        [
                            'id' => 12,
                            'type' => 'Premium',
                            'is_displayed_in_robot' => true,
                            'group_name_translations' => ['en' => 'default'],
                            'title' => '(2x) 50GB-1M',
                            'count_of_devices' => 2,
                            'sell_price' => '200,000',
                        ],
                        [
                            'id' => 99,
                            'type' => 'Premium',
                            'is_displayed_in_robot' => false,
                            'group_name_translations' => ['en' => 'default'],
                            'title' => '(1x) 10GB-1M',
                            'count_of_devices' => 1,
                            'sell_price' => '9,000',
                        ],
                    ],
                ],
            ],
        ];

        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/store' => function () {
                // Evaluated per request, so a test can make the panel refuse
                // after the fixtures are already in place.
                return $this->storeFails
                    ? Http::response(['error' => 'panel is down'], 500)
                    : Http::response(['client_id' => 'new-client-uuid'], 200);
            },
            'https://api.connectix.vip/v1/seller/clients/add-plan' => Http::response(['ok' => true], 200),
            'https://api.connectix.vip/v1/seller/clients/update' => Http::response(['ok' => true], 200),
            // Both read endpoints carry a query, and a fake pattern is matched
            // against the full URL including it. The username lookup is left
            // unfaked on purpose: a stub registered here would shadow the one
            // the linking test needs.
            'https://api.connectix.vip/v1/seller/clients/show?id=*' => function () {
                return Http::response(['client' => $this->panelClient], 200);
            },
            'https://api.connectix.vip/v1/seller/seller-plans' => Http::response($catalogue, 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
            'https://t.me/*' => Http::response('<html></html>', 200),
        ]);
    }

    /**
     * The shape the panel returns for a client, with one active plan.
     *
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

    private function makeUser(int $balance = 0, int $chatId = self::CHAT): User
    {
        $user = User::query()->create([
            'chat_id' => (string) $chatId,
            'telegram_id' => (string) $chatId,
            'name' => 'Ali',
            'test' => false,
        ]);

        $this->app->make(WalletService::class)->create((string) $chatId, $balance);

        return $user;
    }

    private function makeClient(User $user, string $id = 'local-uuid', string $username = 'acme-user'): Client
    {
        return Client::query()->create([
            'id' => $id,
            'count_of_devices' => 1,
            'username' => $username,
            'password' => 'pass1234',
            'chat_id' => (string) $user->chat_id,
            'user_id' => $user->id,
            'created_at' => now(),
        ]);
    }

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

    /**
     * The inline keyboard of the last send or edit, decoded.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function lastKeyboard(): array
    {
        $keyboard = null;

        foreach (Http::recorded() as [$request, $response]) {
            foreach (['sendMessage', 'editMessageText'] as $method) {
                if ($request->method() === 'POST' && str_contains($request->url(), $method)) {
                    $markup = $request['reply_markup'] ?? null;

                    if ($markup === null) {
                        continue;
                    }

                    $keyboard = is_string($markup) ? json_decode($markup, true) : $markup;
                }
            }
        }

        $this->assertIsArray($keyboard, 'no message with a reply_markup was recorded');

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

    /**
     * The text of the last sendMessage.
     */
    private function lastMessage(): string
    {
        $messages = $this->sentMessages();

        return $messages === [] ? '' : (string) end($messages);
    }

    /**
     * Every message text, in the order Telegram was asked to show it.
     *
     * The step menus are edited in place when the callback carried a message
     * id, so both methods count as showing something to the user.
     *
     * @return list<string>
     */
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
     * The last answerCallbackQuery text, or an empty string.
     */
    private function lastAlert(): string
    {
        $text = null;

        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'answerCallbackQuery')) {
                $text = (string) $request['text'];
            }
        }

        return (string) $text;
    }

    private function state(User $user): array
    {
        return $this->app->make(UserStateService::class)->get($user) ?? [];
    }

    // -----------------------------------------------------------------
    // Buy menu
    // -----------------------------------------------------------------

    public function test_the_buy_menu_only_offers_a_new_account_without_accounts(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy'), $user);

        $labels = $this->labels($this->lastKeyboard());

        $this->assertSame(['🛍 | خرید اکانت جدید|group', '↪️ | بازگشت|main_menu'], $labels);
    }

    public function test_the_buy_menu_offers_renewal_once_an_account_exists(): void
    {
        $user = $this->makeUser();

        $this->makeClient($user);

        $this->app->make(PurchaseHandler::class)->handle($this->press('action:buy_or_renew_service'), $user);

        $this->assertSame([
            '🔄️ | تمدید اکانت فعلی|renew',
            '🛍 | خرید اکانت جدید|group',
            '↪️ | بازگشت|main_menu',
        ], $this->labels($this->lastKeyboard()));
    }

    // -----------------------------------------------------------------
    // Group, device and plan selection
    // -----------------------------------------------------------------

    public function test_the_group_menu_lists_the_panel_groups(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('group'), $user);

        $this->assertSame(
            ['📱 | ویژه|buy_group:default', '🏡 | خانه|main_menu', '↪️ | بازگشت|buy'],
            $this->labels($this->lastKeyboard()),
        );
    }

    public function test_choosing_a_group_remembers_it_and_lists_device_counts(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);

        $this->assertSame([
            'action' => UserStateService::ACTION_BUY,
            'step' => 'group',
            'acc' => UserStateService::ACC_NEW,
            'group' => 'default',
            'plan' => null,
            'pay' => null,
        ], $this->state($user));

        $this->assertSame(
            [
                '2️⃣ | 2 کاربر|buy_count:2',
                '1️⃣ | 1 کاربر|buy_count:1',
                '🏡 | خانه|main_menu',
                '↪️ | بازگشت|group',
            ],
            $this->labels($this->lastKeyboard()),
            'legacy laid the pair out right to left',
        );
    }

    public function test_a_group_without_usable_plans_is_refused_and_the_state_is_cleared(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:unknown'), $user);

        $this->assertSame('هیچ پلن معتبری در این گروه وجود ندارد.', $this->lastAlert());
        $this->assertSame([], $this->state($user));
    }

    public function test_the_plan_menu_formats_the_label_the_way_legacy_did(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);

        $this->assertSame(
            ['نامحدود • 1 ماه | 120,000 تومان|buy_plan:11', '🏡 | خانه|main_menu', '↪️ | بازگشت|buy_group:default'],
            $this->labels($this->lastKeyboard()),
        );
    }

    public function test_a_device_count_without_plans_is_refused(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:7'), $user);

        $this->assertSame('برای این تعداد کاربر، پلنی وجود ندارد.', $this->lastAlert());
    }

    public function test_choosing_a_plan_keeps_the_panel_price_in_state(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_plan:11'), $user);

        $state = $this->state($user);

        $this->assertSame('11', $state['plan']);
        $this->assertSame('120,000', $state['price']);
        $this->assertSame('plan', $state['step']);
    }

    public function test_a_plan_that_is_no_longer_sold_is_refused(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_plan:99'), $user);

        $this->assertStringContainsString('پلن مورد نظر یافت نشد', $this->lastAlert());
    }

    public function test_the_payment_methods_carry_the_wallet_balance(): void
    {
        $user = $this->makeUser(250_000);

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_plan:11'), $user);

        $this->assertSame([
            '💳 | کارت به کارت|pay_card:120,000',
            '👝 | کیف پول ( موجودی 250,000 تومان)|pay_wallet:120,000',
            '🏡 | خانه|main_menu',
            '↪️ | بازگشت|buy_count:1',
        ], $this->labels($this->lastKeyboard()));
    }

    public function test_a_tampered_price_in_the_callback_is_ignored(): void
    {
        $user = $this->makeUser(250_000);

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_plan:11'), $user);

        // The button claims one Toman.
        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_wallet:1'), $user);

        $payment = Payment::query()->first();

        $this->assertNotNull($payment);
        $this->assertSame('120,000', $payment->price);
    }

    // -----------------------------------------------------------------
    // Card checkout
    // -----------------------------------------------------------------

    public function test_the_card_step_shows_the_card_details(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_plan:11'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_card:120,000'), $user);

        $this->assertSame([
            'action' => UserStateService::ACTION_PAY,
            'step' => 'pay',
            'pay' => PaymentMethod::Card->value,
            'acc' => UserStateService::ACC_NEW,
            'group' => 'default',
            'plan' => '11',
            'price' => '120,000',
        ], $this->state($user));

        $this->assertSame(
            ['🎟 | وارد کردن کد تخفیف|discount_set:120,000', '❌ | انصراف|main_menu'],
            $this->labels($this->lastKeyboard()),
        );
    }

    public function test_the_card_step_creates_no_order(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_plan:11'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_card:120,000'), $user);

        $this->assertSame(0, Payment::query()->count(), 'the order is written when the receipt arrives');
    }

    public function test_asking_for_a_coupon_keeps_the_price_for_the_cancel_button(): void
    {
        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_plan:11'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_card:120,000'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('discount_set:120,000'), $user);

        $state = $this->state($user);

        $this->assertSame(UserStateService::ACTION_DISCOUNT, $state['action']);
        $this->assertSame('set', $state['step']);
        $this->assertSame('120,000', $state['price']);
        $this->assertSame('11', $state['plan']);

        $this->assertSame(['❌ | انصراف|pay_card:120,000'], $this->labels($this->lastKeyboard()));
    }

    // -----------------------------------------------------------------
    // Coupon
    // -----------------------------------------------------------------

    private function fakeCoupons(array $coupons): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/seller-plans/coupons' => Http::response(['coupons' => $coupons], 200),
            'https://api.connectix.vip/v1/seller/seller-plans' => Http::response([
                'groups' => [['name' => 'default']],
                'seller_plan_group' => [[
                    'name' => 'default',
                    'seller_plans' => [[
                        'id' => 11,
                        'type' => 'Premium',
                        'is_displayed_in_robot' => true,
                        'group_name_translations' => ['en' => 'default'],
                        'title' => '(1x) Unlimited-1M',
                        'count_of_devices' => 1,
                        'sell_price' => '120,000',
                    ]],
                ]],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);
    }

    public function test_a_valid_coupon_lowers_the_price_in_state(): void
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

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_plan:11'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_card:120,000'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('discount_set:120,000'), $user);

        $this->app->make(CouponHandler::class)->handle($this->text('SAVE10'), $user);

        $state = $this->state($user);

        $this->assertSame(UserStateService::ACTION_PAY, $state['action']);
        $this->assertSame(108_000, $state['final_price']);
        $this->assertSame(120_000, $state['original_price']);
        $this->assertSame('SAVE10', $state['coupon_code']);

        // The confirmation is its own message; the card screen follows it.
        $this->assertStringContainsString(
            'کد تخفیف با موفقیت اعمال شد',
            implode("\n", $this->sentMessages()),
        );
    }

    public function test_an_unknown_coupon_keeps_the_user_in_the_coupon_step(): void
    {
        $this->fakeCoupons([]);

        $user = $this->makeUser();

        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_group:default'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_count:1'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('buy_plan:11'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_card:120,000'), $user);
        $this->app->make(PurchaseHandler::class)->handle($this->press('discount_set:120,000'), $user);

        $this->app->make(CouponHandler::class)->handle($this->text('NOPE'), $user);

        $this->assertStringContainsString('صحیح نمی باشد', $this->lastMessage());
        $this->assertSame(UserStateService::ACTION_DISCOUNT, $this->state($user)['action']);
        $this->assertSame(['❌ | انصراف|pay_card:120,000'], $this->labels($this->lastKeyboard()));
    }

    // -----------------------------------------------------------------
    // Wallet checkout
    // -----------------------------------------------------------------

    /**
     * Walk a fresh user to the payment step for plan 11.
     */
    private function walkToPayment(User $user): void
    {
        $handler = $this->app->make(PurchaseHandler::class);

        $handler->handle($this->press('buy_group:default'), $user);
        $handler->handle($this->press('buy_count:1'), $user);
        $handler->handle($this->press('buy_plan:11'), $user);
    }

    public function test_a_wallet_payment_debits_the_wallet_and_provisions_the_account(): void
    {
        $user = $this->makeUser(500_000);

        $this->walkToPayment($user);

        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_wallet:120,000'), $user);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame('120,000', $payment->price);
        $this->assertSame('11', $payment->plan_id);
        $this->assertTrue($payment->is_paid->isDecided(), 'provisioning decides the order');
        $this->assertSame('new-client-uuid', $payment->client_id, 'the placeholder is replaced');

        $this->assertSame(380_000, $this->app->make(WalletService::class)->balance($user->chat_id));

        $this->assertSame(1, WalletTransaction::query()->count());

        // Legacy stored the positive amount and let the operation carry the
        // direction, so the admin search on `amount` keeps working.
        $transaction = WalletTransaction::query()->firstOrFail();

        $this->assertSame('120000', (string) $transaction->amount);
        $this->assertSame(WalletOperation::Decrease, $transaction->operation);
        $this->assertSame(WalletTransactionType::Buy, $transaction->type);
        $this->assertSame(WalletTransactionStatus::Success, $transaction->status);

        $this->assertSame('acme-user', Client::query()->firstOrFail()->username);
        $this->assertStringContainsString('اکانت شما با موفقیت ایجاد شد.', $this->lastMessage());
        $this->assertSame([], $this->state($user), 'the flow is finished, so the state is cleared');
    }

    public function test_a_wallet_payment_deletes_the_menu_message(): void
    {
        $user = $this->makeUser(500_000);

        $this->walkToPayment($user);

        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_wallet:120,000'), $user);

        Http::assertSent(static fn (Request $request): bool => str_contains($request->url(), 'deleteMessage')
            && $request['message_id'] === 42);
    }

    public function test_a_wallet_payment_without_enough_balance_is_refused(): void
    {
        $user = $this->makeUser(1_000);

        $this->walkToPayment($user);

        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_wallet:120,000'), $user);

        $this->assertStringContainsString('موجودی کیف پول شما کافی نیست', $this->lastAlert());
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(1_000, $this->app->make(WalletService::class)->balance($user->chat_id));
        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertNull(Client::query()->first());
    }

    public function test_a_second_tap_while_an_order_is_still_open_is_refused(): void
    {
        $user = $this->makeUser(500_000);

        $this->walkToPayment($user);

        $handler = $this->app->make(PurchaseHandler::class);

        // The panel refuses, so the first tap leaves an undecided order behind.
        $this->storeFails = true;

        $handler->handle($this->press('pay_wallet:120,000'), $user);

        $this->assertTrue(Payment::query()->firstOrFail()->isPending(), 'the refused tap left the first order open');

        // The flow cleared its state, so the same plan is chosen again.
        $this->walkToPayment($user);

        $handler->handle($this->press('pay_wallet:120,000'), $user);

        $this->assertSame(1, Payment::query()->count(), 'an open order must not be charged twice');
        $this->assertSame(380_000, $this->app->make(WalletService::class)->balance($user->chat_id));
        $this->assertSame(1, WalletTransaction::query()->count());
    }

    public function test_a_renewal_whose_account_is_gone_is_not_charged(): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/clients?username=*' => Http::response([
                'clients' => ['data' => []],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $user = $this->makeUser(500_000);

        // The local row was deleted after the plan was chosen.
        $this->app->make(UserStateService::class)->set($user, [
            'action' => UserStateService::ACTION_RENEW,
            'step' => 'pay',
            'acc' => 'ghost-user',
            'group' => 'default',
            'plan' => '11',
            'price' => 120_000,
            'pay' => null,
        ]);

        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_wallet:120,000'), $user);

        // Answering with the 'new' placeholder would sell a second account
        // for a renewal the buyer did not ask for.
        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(500_000, $this->app->make(WalletService::class)->balance($user->chat_id));
        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertStringContainsString('اکانت مورد نظر پیدا نشد', $this->lastAlert());
    }

    public function test_going_home_mid_flow_abandons_the_step(): void
    {
        $user = $this->makeUser(500_000);

        $this->walkToPayment($user);

        $this->assertNotSame([], $this->state($user));

        $this->app->make(MainMenuHandler::class)->handle($this->press('main_menu'), $user);

        $this->assertSame([], $this->state($user), 'legacy cleared the state on the way home');

        // No handler claims a free text message any more, so it is not read as
        // a coupon code.
        $registry = $this->app->make(HandlerRegistry::class);

        $this->assertNull($registry->firstSupporting($this->text('SAVE10'), $user));

        // The payment button still reaches the handler, which now has no plan
        // in state to charge: legacy read a cleared state and wrote an order
        // with no plan on it.
        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_wallet:120,000'), $user);

        $this->assertSame(0, Payment::query()->count());
        $this->assertSame(0, WalletTransaction::query()->count());
        $this->assertSame(500_000, $this->app->make(WalletService::class)->balance($user->chat_id));
        $this->assertStringContainsString('پلن مورد نظر یافت نشد', $this->lastAlert());
    }

    public function test_the_payment_menu_sends_one_step_back_to_the_plan_list(): void
    {
        $user = $this->makeUser(500_000);

        $handler = $this->app->make(PurchaseHandler::class);

        $handler->handle($this->press('buy_group:default'), $user);
        $handler->handle($this->press('buy_count:1'), $user);
        $handler->handle($this->press('buy_plan:11'), $user);

        // Legacy kept the step name `plan` on the payment screen.
        $this->assertSame('plan', $this->state($user)['step']);

        $handler->handle($this->press('buy_count:1'), $user);

        $this->assertSame('plan', $this->state($user)['step']);
        $this->assertStringContainsString('فهرست و قیمت سرویس‌های', $this->lastMessage());
        $this->assertContains(
            '↪️ | بازگشت|buy_group:default',
            $this->labels($this->lastKeyboard()),
            'and one more back reaches the group list',
        );
    }

    public function test_a_second_buy_after_a_delivered_account_creates_another_order(): void
    {
        $user = $this->makeUser(500_000);

        $this->walkToPayment($user);

        $handler = $this->app->make(PurchaseHandler::class);

        $handler->handle($this->press('pay_wallet:120,000'), $user);

        $this->walkToPayment($user);

        $handler->handle($this->press('pay_wallet:120,000'), $user);

        $this->assertSame(2, Payment::query()->count(), 'a decided order does not block a later purchase');
        $this->assertSame(260_000, $this->app->make(WalletService::class)->balance($user->chat_id));
        $this->assertSame(2, WalletTransaction::query()->count());
    }

    public function test_a_wallet_renewal_targets_the_linked_account(): void
    {
        $user = $this->makeUser(500_000);

        $this->makeClient($user, 'local-uuid', 'acme-user');

        $this->app->make(RenewHandler::class)->handle($this->press('renew_acc:acme-user'), $user);
        $this->app->make(RenewHandler::class)->handle($this->press('renew_plan:(1x) Unlimited-1M'), $user);

        $this->assertSame('acme-user', $this->state($user)['acc']);

        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_wallet:120,000'), $user);

        $payment = Payment::query()->firstOrFail();

        $this->assertSame('local-uuid', $payment->client_id, 'a renewal must not create a new account');
        $this->assertSame(PaymentStatus::Paid, $payment->is_paid);
        $this->assertStringContainsString('اکانت شما با موفقیت تمدید شد.', $this->lastMessage());
    }

    /**
     * Legacy read `plans[0]` off the client for the plan line. A panel answer
     * without plans would render a blank line, so the ordered plan's title is
     * used as the fallback (ClientProvisioner::currentPlanName).
     */
    public function test_a_client_without_plans_falls_back_to_the_ordered_plan_title(): void
    {
        $this->panelClient = [
            'id' => 'panel-uuid',
            'username' => 'acme-user',
            'password' => 'pass1234',
            'count_of_devices' => 1,
        ];

        $user = $this->makeUser(500_000);

        $this->makeClient($user, 'local-uuid', 'acme-user');

        $this->app->make(RenewHandler::class)->handle($this->press('renew_acc:acme-user'), $user);
        $this->app->make(RenewHandler::class)->handle($this->press('renew_plan:(1x) Unlimited-1M'), $user);

        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_wallet:120,000'), $user);

        $expected = $this->app->make(PlanService::class)
            ->parsePlanTitle('(1x) Unlimited-1M')['text'];

        $this->assertNotSame('', $expected);
        $this->assertStringContainsString($expected, $this->lastMessage());
        $this->assertStringContainsString('اکانت شما با موفقیت تمدید شد.', $this->lastMessage());
    }

    public function test_a_failed_provisioning_leaves_the_order_open_and_explains_itself(): void
    {
        $user = $this->makeUser(500_000);

        $this->walkToPayment($user);

        $this->storeFails = true;

        $this->app->make(PurchaseHandler::class)->handle($this->press('pay_wallet:120,000'), $user);

        $payment = Payment::query()->firstOrFail();

        $this->assertTrue($payment->isPending(), 'an order that produced no account stays open for a retry');
        $this->assertStringContainsString('ساخت اکانت شما با خطا مواجه شد', $this->lastMessage());
        $this->assertNull(Client::query()->first());
    }

    // -----------------------------------------------------------------
    // Renewal
    // -----------------------------------------------------------------

    public function test_the_renew_menu_lists_accounts_newest_first(): void
    {
        $user = $this->makeUser();

        $this->makeClient($user, 'older', 'first-user');
        $this->makeClient($user, 'newer', 'second-user');

        $this->app->make(RenewHandler::class)->handle($this->press('renew'), $user);

        $labels = $this->labels($this->lastKeyboard());

        $this->assertStringContainsString('renew_acc:second-user', implode(' ', $labels));
        $this->assertStringContainsString('renew_acc:first-user', implode(' ', $labels));

        $position = array_search('🟢 فعال | second-user|renew_acc:second-user', $labels, true);

        $this->assertNotFalse($position, 'the status column reads as legacy wrote it');
        $this->assertLessThan(
            array_search('🟢 فعال | first-user|renew_acc:first-user', $labels, true),
            $position,
            'the newest account comes first',
        );
    }

    public function test_the_renew_menu_without_accounts_says_so(): void
    {
        $user = $this->makeUser();

        $this->app->make(RenewHandler::class)->handle($this->press('renew'), $user);

        $this->assertContains('🤷🏻 | اکانتی به تلگرام شما متصل نیست|not', $this->labels($this->lastKeyboard()));
    }

    public function test_choosing_an_account_shows_its_last_plan(): void
    {
        $user = $this->makeUser();

        $this->makeClient($user);

        $this->app->make(RenewHandler::class)->handle($this->press('renew_acc:acme-user'), $user);

        $this->assertSame([
            'action' => UserStateService::ACTION_RENEW,
            'step' => 'acc',
            'acc' => 'acme-user',
            'group' => null,
            'plan' => null,
            'pay' => null,
        ], $this->state($user));

        $this->assertSame([
            '🔃 | تمدید با همین پلن|renew_plan:(1x) Unlimited-1M',
            '➕ | انتخاب پلن دیگر|group',
            '🏡 | خانه|main_menu',
            '↪️ | بازگشت|renew',
        ], $this->labels($this->lastKeyboard()));
    }

    public function test_an_account_of_another_chat_cannot_be_renewed(): void
    {
        $user = $this->makeUser();

        $this->makeClient($user, 'local-uuid', 'acme-user');

        $other = $this->makeUser(0, 999);

        $this->app->make(RenewHandler::class)->handle($this->press('renew_acc:acme-user', 999), $other);

        $this->assertStringContainsString('متصل نیست', $this->lastAlert());
    }

    public function test_renewing_with_the_same_plan_reaches_the_payment_methods(): void
    {
        $user = $this->makeUser(500_000);

        $this->makeClient($user);

        $handler = $this->app->make(RenewHandler::class);

        $handler->handle($this->press('renew_acc:acme-user'), $user);
        $handler->handle($this->press('renew_plan:(1x) Unlimited-1M'), $user);

        $state = $this->state($user);

        $this->assertSame('11', $state['plan']);
        $this->assertSame('120,000', $state['price']);
        $this->assertSame([
            '💳 | کارت به کارت|pay_card:120,000',
            '👝 | کیف پول ( موجودی 500,000 تومان)|pay_wallet:120,000',
            '🏡 | خانه|main_menu',
            '↪️ | بازگشت|renew_acc:acme-user',
        ], $this->labels($this->lastKeyboard()));
    }

    public function test_a_renewal_plan_that_left_the_catalogue_is_refused(): void
    {
        $user = $this->makeUser();

        $this->makeClient($user);

        $this->app->make(RenewHandler::class)->handle($this->press('renew_acc:acme-user'), $user);
        $this->app->make(RenewHandler::class)->handle($this->press('renew_plan:1M-99G (1x)'), $user);

        $this->assertStringContainsString('پلن مورد نظر یافت نشد', $this->lastAlert());
    }

    public function test_the_payment_step_of_a_renewal_needs_an_account(): void
    {
        $user = $this->makeUser();

        $this->app->make(RenewHandler::class)->handle($this->press('renew_plan:(1x) Unlimited-1M'), $user);

        $this->assertStringContainsString('ابتدا اکانت مورد نظر', $this->lastAlert());
        $this->assertSame(0, Payment::query()->count());
    }

    // -----------------------------------------------------------------
    // Linking an existing account
    // -----------------------------------------------------------------

    public function test_an_existing_account_is_linked_after_the_password_is_confirmed(): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/update' => Http::response(['ok' => true], 200),
            'https://api.connectix.vip/v1/seller/clients?username=*' => Http::response([
                'clients' => ['data' => [[
                    'id' => 'panel-uuid',
                    'username' => 'acme-user',
                    'password' => 'pass1234',
                    'plan_name' => 'Premium (2x)',
                ]]],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $user = $this->makeUser();

        $handler = $this->app->make(AddAccountHandler::class);

        $handler->handle($this->press('add_account'), $user);
        $handler->handle($this->text('acme-user'), $user);

        $this->assertSame('get_password', $this->state($user)['step']);

        $handler->handle($this->text('pass1234'), $user);

        $client = Client::query()->firstOrFail();

        $this->assertSame('panel-uuid', $client->id);
        $this->assertSame((string) self::CHAT, $client->chat_id);
        $this->assertSame(2, $client->count_of_devices, 'the device count comes from the plan name');
        $this->assertSame('acme-user', $client->username, 'the panel owns the credentials');
        $this->assertSame('pass1234', $client->password);
        $this->assertSame([], $this->state($user));
        $this->assertStringContainsString('با موفقیت حساب تلگرام شما متصل شد', $this->lastMessage());
    }

    public function test_linking_an_existing_local_account_keeps_its_own_fields(): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/update' => Http::response(['ok' => true], 200),
            'https://api.connectix.vip/v1/seller/clients?username=*' => Http::response([
                'clients' => ['data' => [[
                    'id' => 'local-uuid',
                    'username' => 'acme-user',
                    'password' => 'pass1234',
                    'plan_name' => 'Premium (2x)',
                ]]],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $user = $this->makeUser();

        $client = $this->makeClient($user, 'local-uuid', 'acme-user');
        $client->forceFill([
            'password' => 'older-password',
            'count_of_devices' => 5,
            'created_at' => '2020-01-02 03:04:05',
        ])->save();

        $handler = $this->app->make(AddAccountHandler::class);

        $handler->handle($this->press('add_account'), $user);
        $handler->handle($this->text('acme-user'), $user);
        $handler->handle($this->text('pass1234'), $user);

        $client->refresh();

        $this->assertSame((string) self::CHAT, $client->chat_id, 'the ownership moves');
        $this->assertSame($user->id, (int) $client->user_id);

        // Legacy only ran `UPDATE clients SET chat_id, user_id`.
        $this->assertSame('older-password', $client->password, 'the local row keeps its own password');
        $this->assertSame(5, $client->count_of_devices);
        $this->assertSame('2020-01-02 03:04:05', (string) $client->created_at);
    }

    public function test_a_wrong_password_is_reported_and_links_nothing(): void
    {
        Http::fake([
            'https://api.connectix.vip/v1/seller/clients?username=*' => Http::response([
                'clients' => ['data' => [[
                    'id' => 'panel-uuid',
                    'username' => 'acme-user',
                    'password' => 'pass1234',
                    'plan_name' => 'Premium (2x)',
                ]]],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $user = $this->makeUser();

        $handler = $this->app->make(AddAccountHandler::class);

        $handler->handle($this->press('add_account'), $user);
        $handler->handle($this->text('acme-user'), $user);
        $handler->handle($this->text('wrong'), $user);

        $this->assertSame('نام کاربری و یا رمز عبور اشتباه است.', $this->lastMessage());
        $this->assertNull(Client::query()->first());
        $this->assertSame([], $this->state($user));
    }

    // -----------------------------------------------------------------
    // Registry
    // -----------------------------------------------------------------

    public function test_the_registry_dispatches_the_purchase_callbacks(): void
    {
        $user = $this->makeUser();

        $registry = $this->app->make(HandlerRegistry::class);

        $handler = $registry->firstSupporting($this->press('buy_group:default'), $user);

        $this->assertInstanceOf(PurchaseHandler::class, $handler);
    }

    public function test_the_registry_dispatches_the_renewal_callbacks(): void
    {
        $user = $this->makeUser();

        $registry = $this->app->make(HandlerRegistry::class);

        $handler = $registry->firstSupporting($this->press('renew'), $user);

        $this->assertInstanceOf(RenewHandler::class, $handler);
    }

    public function test_the_welcome_buy_button_reaches_the_buy_menu(): void
    {
        $user = $this->makeUser();

        $registry = $this->app->make(HandlerRegistry::class);

        $handler = $registry->firstSupporting($this->press('action:buy_or_renew_service'), $user);

        $this->assertInstanceOf(PurchaseHandler::class, $handler);

        $handler->handle($this->press('action:buy_or_renew_service'), $user);

        $this->assertStringContainsString(
            'خرید اکانت جدید',
            $this->app->make(MessageFactory::class)->make('buy'),
        );
    }
}
