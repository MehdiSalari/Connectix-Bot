<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Telegram\MessageFactory;
use App\Services\Wallet\WalletService;
use App\Telegram\Handlers\MainMenuHandler;
use App\Telegram\Handlers\StartHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The first real handlers, driven through the container exactly as the gateway
 * does, so the wiring is exercised rather than mocked away.
 */
class StartAndMainMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // The plan catalogue is cached, and a stale entry would decide whether
        // the trial row shows up.
        Cache::flush();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.active' => true,
            'connectix_bot.force_channel_join' => false,
            'connectix_bot.test_enabled' => false,
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.admin_ids' => ['555'],
            'connectix_bot.channel_telegram' => null,
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
        ]);

        Http::preventStrayRequests();
        $this->fakeHttp();
    }

    private function fakeHttp(): void
    {
        Http::fake([
            'https://t.me/*' => Http::response(
                '<html><head><meta property="og:image" content="https://t.me/i/userpic/320/ali.jpg">'
                .'</head><body><div class="tgme_page_title"><span dir="auto">Ali R</span></div></body></html>',
                200,
            ),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);
    }

    private function makeUser(int $chatId = 555, bool $usedTest = false): User
    {
        $user = User::query()->create([
            'chat_id' => (string) $chatId,
            'telegram_id' => (string) $chatId,
            'name' => 'Ali',
            'test' => $usedTest,
        ]);

        $this->app->make(WalletService::class)->create((string) $chatId, 0);

        return $user;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function sentMessages(): array
    {
        $sent = [];

        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'sendMessage')) {
                $sent[] = (string) $request['text'];
            }
        }

        return $sent;
    }

    /**
     * The inline keyboard of the last sendMessage, decoded.
     *
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function lastKeyboard(): array
    {
        $keyboard = null;

        foreach (Http::recorded() as [$request, $response]) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'sendMessage')) {
                $markup = $request['reply_markup'];

                $keyboard = is_string($markup) ? json_decode($markup, true) : $markup;
            }
        }

        $this->assertIsArray($keyboard, 'no sendMessage with a reply_markup was recorded');

        return $keyboard['inline_keyboard'] ?? [];
    }

    /**
     * Flatten the keyboard to the callback data and labels it carries.
     *
     * @return list<string>
     */
    private function labelsAndCallbacks(array $rows): array
    {
        $found = [];

        foreach ($rows as $row) {
            foreach ($row as $button) {
                $found[] = (string) $button['text'].'|'.(string) ($button['callback_data'] ?? $button['url'] ?? '');
            }
        }

        return $found;
    }

    public function test_start_sends_the_welcome_message_with_the_home_keyboard(): void
    {
        $user = $this->makeUser();

        $this->app->make(StartHandler::class)->handle(
            $this->update('/start'),
            $user,
        );

        // With the panel disabled and no local override the welcome text is
        // empty, which is what an unbranded install renders.
        $this->assertSame([''], $this->sentMessages());

        $labels = $this->labelsAndCallbacks($this->lastKeyboard());

        $this->assertContains('📦 | اکانت های من|accounts', $labels);
        $this->assertContains('🛍️ | خرید / تمدید اکانت |action:buy_or_renew_service', $labels);
        $this->assertContains('📲 | دانلود نرم افزار|apps', $labels);
        $this->assertContains('💡 | آموزش ها|guide', $labels);
        $this->assertContains('💁🏻‍♂️ | پشتیبانی|support', $labels);
        $this->assertContains('❓ | سوالات متداول|faq', $labels);
        $this->assertContains('👝 |  کیف پول|wallet', $labels);
    }

    public function test_an_admin_gets_the_management_panel_button(): void
    {
        $user = $this->makeUser(555);

        $this->app->make(StartHandler::class)->handle($this->update('/start'), $user);

        $labels = $this->labelsAndCallbacks($this->lastKeyboard());

        $this->assertNotEmpty(array_filter(
            $labels,
            static fn (string $label): bool => str_starts_with($label, '👨🏻‍💻 | پنل مدیریت')
        ), 'an admin id should render the management panel button');
    }

    public function test_a_normal_user_gets_the_profile_button(): void
    {
        $user = $this->makeUser(777);

        $this->app->make(StartHandler::class)->handle($this->update('/start', 777), $user);

        $labels = $this->labelsAndCallbacks($this->lastKeyboard());

        $this->assertNotEmpty(array_filter(
            $labels,
            static fn (string $label): bool => str_starts_with($label, '👤 | پروفایل')
        ));
    }

    public function test_the_trial_row_appears_when_the_panel_offers_a_free_plan(): void
    {
        config(['connectix_bot.test_enabled' => true]);

        $user = $this->makeUser();

        Http::fake([
            'https://api.connectix.vip/v1/seller/seller-plans' => Http::response([
                'seller_plan_group' => [
                    [
                        'name' => 'default',
                        'seller_plans' => [[
                            'id' => 1,
                            'type' => 'Free',
                            'is_displayed_in_robot' => true,
                            'title' => '(1x) Free-1D',
                        ]],
                    ],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $this->app->make(StartHandler::class)->handle($this->update('/start'), $user);

        $labels = $this->labelsAndCallbacks($this->lastKeyboard());

        $this->assertContains('🎁 | دریافت اکانت تست|get_test', $labels);
    }

    public function test_the_keep_this_one_row_appears_after_the_trial_was_used(): void
    {
        config(['connectix_bot.test_enabled' => true]);

        $user = $this->makeUser(555, usedTest: true);

        $this->app->make(StartHandler::class)->handle($this->update('/start'), $user);

        $labels = $this->labelsAndCallbacks($this->lastKeyboard());

        $this->assertContains('🙋🏻 | همون همیشگی|always_select:0', $labels);
    }

    public function test_the_channel_news_row_is_omitted_without_a_channel(): void
    {
        $user = $this->makeUser();

        $this->app->make(StartHandler::class)->handle($this->update('/start'), $user);

        $labels = $this->labelsAndCallbacks($this->lastKeyboard());

        foreach ($labels as $label) {
            $this->assertStringNotContainsString('اخبار و اطلاعیه', $label);
        }
    }

    public function test_the_channel_news_row_uses_the_configured_channel(): void
    {
        config(['connectix_bot.channel_telegram' => '@acme_news']);

        $user = $this->makeUser();

        $this->app->make(StartHandler::class)->handle($this->update('/start'), $user);

        $labels = $this->labelsAndCallbacks($this->lastKeyboard());

        $this->assertContains('📣 | اخبار و اطلاعیه ها|https://t.me/acme_news', $labels);
    }

    public function test_main_menu_edits_the_existing_message(): void
    {
        $user = $this->makeUser();

        $update = $this->update('main_menu', 555, isCallback: true);

        $this->app->make(MainMenuHandler::class)->handle($update, $user);

        Http::assertSent(static function (Request $request): bool {
            return str_contains($request->url(), 'editMessageText')
                && $request['message_id'] === 42;
        });

        $this->assertSame([], $this->sentMessages(), 'editing must not also post a new message');
    }

    public function test_main_menu_falls_back_to_sending_when_there_is_no_message_to_edit(): void
    {
        $user = $this->makeUser();

        $update = $this->update('main_menu', 555, isCallback: true, messageId: null);

        $this->app->make(MainMenuHandler::class)->handle($update, $user);

        $this->assertNotEmpty($this->sentMessages());
    }

    /**
     * Keyboards sent before the rewrite still sit in old chats carrying the
     * `new_menu` callback (functions.php:2391/2425/2508/2983). Legacy claimed
     * it (bot.php:400): the state was cleared through `userInfo()` and the
     * welcome menu was posted as a fresh message, not as an edit.
     */
    public function test_new_menu_is_claimed_and_posts_a_fresh_message_like_legacy(): void
    {
        $user = $this->makeUser();
        $user->forceFill(['action' => json_encode(['action' => 'coupon'])])->save();

        $update = $this->update('new_menu', 555, isCallback: true);
        $handler = $this->app->make(MainMenuHandler::class);

        $this->assertTrue($handler->supports($update, $user), 'old keyboards must keep working');

        $handler->handle($update, $user);

        $this->assertNotEmpty($this->sentMessages(), 'legacy answered new_menu with a fresh message');

        foreach (Http::recorded() as [$request]) {
            $this->assertStringNotContainsString('editMessageText', $request->url());
        }

        $this->assertDatabaseHas('users', ['chat_id' => '555', 'action' => null]);
    }

    public function test_the_welcome_text_comes_from_the_seller_panel(): void
    {
        config(['connectix_bot.panel.enabled' => true, 'connectix_bot.connectix.token' => 'panel-token']);

        Http::fake([
            'https://api.connectix.vip/v1/seller/telegram-bot' => Http::response([
                'bot' => ['app_name' => 'Acme VPN'],
                'telegramMessages' => ['welcome_text' => 'به Acme VPN خوش آمدید'],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ]);

        $user = $this->makeUser();

        $this->app->make(StartHandler::class)->handle($this->update('/start'), $user);

        $this->assertSame(['به Acme VPN خوش آمدید'], $this->sentMessages());
    }

    public function test_the_buy_message_carries_the_branded_app_name(): void
    {
        $this->assertStringContainsString(
            'Acme VPN',
            $this->app->make(MessageFactory::class)->make('buy'),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function update(string $payload, int $chatId = 555, bool $isCallback = false, ?int $messageId = 42): TelegramUpdate
    {
        if ($isCallback) {
            return TelegramUpdate::fromArray([
                'update_id' => 1,
                'callback_query' => [
                    'id' => 'cb-1',
                    'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                    'message' => ['message_id' => $messageId, 'chat' => ['id' => $chatId], 'text' => 'menu'],
                    'data' => $payload,
                ],
            ]);
        }

        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'chat' => ['id' => $chatId, 'first_name' => 'Ali', 'username' => 'ali'],
                'text' => $payload,
            ],
        ]);
    }
}
