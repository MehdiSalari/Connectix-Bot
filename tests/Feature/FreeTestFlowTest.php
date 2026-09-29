<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Telegram\Handlers\FreeTestHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The free trial offer, ported from the `get_test`/`getTest` branches of
 * bot.php and the legacy `getTest()` helper.
 *
 * The menu only exists when the panel has a free plan; choosing one provisions
 * a real client on the panel, marks the trial as used and keeps a local copy
 * of the account so "my accounts" and renewals can find it later.
 */
class FreeTestFlowTest extends TestCase
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
            'connectix_bot.test_enabled' => true,
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.admin_ids' => [],
            'connectix_bot.messages' => ['free_test_account_created' => 'اکانت تست شما با موفقیت ایجاد شد.'],
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.bank.name' => null,
            'connectix_bot.wallet.minimum_deposit' => 10000,
        ]);

        Http::preventStrayRequests();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    /**
     * The whole fake set, built at once so a test can override a single panel
     * response without inheriting the defaults from a previous fake() call.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function fakePanel(array $overrides = []): void
    {
        Http::fake(array_merge([
            'https://api.connectix.vip/v1/seller/clients/store' => Http::response(['client_id' => 'trial-uuid'], 200),
            'https://api.connectix.vip/v1/seller/clients/show?id=*' => Http::response([
                'client' => [
                    'id' => 'trial-uuid',
                    'username' => 'trial-user',
                    'password' => 'pass1234',
                    'count_of_devices' => 1,
                    'subscription_link' => 'https://sub.example/trial',
                    'plans' => [[
                        'name' => '(1x) Free-1M',
                        'is_active' => true,
                        'is_in_queue' => false,
                    ]],
                ],
            ], 200),
            'https://api.connectix.vip/v1/seller/seller-plans' => Http::response([
                'groups' => [['name' => 'default'], ['name' => 'Economic']],
                'seller_plan_group' => [
                    [
                        'name' => 'default',
                        'seller_plans' => [
                            [
                                'id' => 21,
                                'type' => 'Free',
                                'is_displayed_in_robot' => true,
                                'group_name_translations' => ['en' => 'default'],
                                'title' => '(1x) 1G Unlimited-1M',
                                'count_of_devices' => 1,
                            ],
                        ],
                    ],
                    [
                        'name' => 'Economic',
                        'seller_plans' => [
                            [
                                'id' => 22,
                                'type' => 'Free',
                                'is_displayed_in_robot' => true,
                                'group_name_translations' => ['en' => 'Economic'],
                                'title' => '(1x) + Economic-1M',
                                'count_of_devices' => 1,
                            ],
                        ],
                    ],
                ],
            ], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
        ], $overrides));
    }

    private function makeUser(bool $usedTest = false): User
    {
        return User::query()->create([
            'chat_id' => (string) self::CHAT,
            'telegram_id' => (string) self::CHAT,
            'name' => 'Ali',
            'test' => $usedTest,
        ]);
    }

    private function press(string $data, int $chatId = self::CHAT): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-trial',
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'message' => [
                    'message_id' => 42,
                    'chat' => ['id' => $chatId],
                    'text' => 'menu',
                ],
                'data' => $data,
            ],
        ]);
    }

    private function handle(string $data, User $user): void
    {
        $this->app->make(FreeTestHandler::class)->handle($this->press($data), $user);
    }

    private function recorded(): Collection
    {
        return collect(Http::recorded())->map(fn (array $pair) => $pair[0]);
    }

    private function editTexts(): array
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'editMessageText'))
            ->map(fn ($request) => (string) $request['text'])
            ->values()
            ->all();
    }

    private function lastEditText(): string
    {
        $texts = $this->editTexts();

        $this->assertNotSame([], $texts, 'no editMessageText was recorded');

        return $texts[array_key_last($texts)];
    }

    private function lastKeyboard(): array
    {
        $keyboard = null;

        foreach ($this->recorded() as $request) {
            if ($request->method() === 'POST' && str_contains($request->url(), 'editMessageText')) {
                $markup = $request['reply_markup'];

                $keyboard = is_string($markup) ? json_decode($markup, true) : $markup;
            }
        }

        $this->assertIsArray($keyboard, 'no editMessageText with a reply_markup was recorded');

        return $keyboard['inline_keyboard'] ?? [];
    }

    private function answerCallbackTexts(): array
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'answerCallbackQuery'))
            ->map(fn ($request) => (string) $request['text'])
            ->values()
            ->all();
    }

    private function storePlanIds(): array
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), 'clients/store'))
            ->map(fn ($request) => (string) $request['plan_id'])
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
    // The menu
    // -----------------------------------------------------------------

    public function test_the_trial_menu_lists_the_free_plan_groups(): void
    {
        $this->fakePanel();

        $this->handle('get_test', $this->makeUser());

        $text = $this->lastEditText();

        $this->assertStringContainsString('🎁 لطفا نوع اکانت تست را انتخاب کنید:', $text);

        $buttons = [];

        foreach ($this->lastKeyboard() as $row) {
            foreach ($row as $button) {
                $buttons[] = $button['text'].'|'.$button['callback_data'];
            }
        }

        $this->assertContains('📱 | ویژه|getTest_default', $buttons);
        $this->assertContains('💰 | اقتصادی|getTest_Economic', $buttons);
        $this->assertContains('↪️ | بازگشت|main_menu', $buttons);
    }

    public function test_the_trial_menu_alerts_when_there_are_no_free_plans(): void
    {
        $this->fakePanel([
            'https://api.connectix.vip/v1/seller/seller-plans' => Http::response([
                'groups' => [['name' => 'default']],
                'seller_plan_group' => [],
            ], 200),
        ]);

        $this->handle('get_test', $this->makeUser());

        $this->assertSame(['❌ اکانت تست غیر فعال است!'], $this->answerCallbackTexts());
    }

    // -----------------------------------------------------------------
    // Creating the account
    // -----------------------------------------------------------------

    public function test_choosing_a_plan_provisions_the_account(): void
    {
        $this->fakePanel();

        $user = $this->makeUser();

        $this->handle('getTest_default', $user);

        // The panel client is created and read back.
        $this->assertSame(1, $this->storeCalls());

        $user->refresh();

        $this->assertTrue($user->test);

        $client = Client::query()->firstOrFail();

        $this->assertSame('trial-uuid', $client->id);
        $this->assertSame('trial-user', $client->username);
        $this->assertSame('pass1234', $client->password);
        $this->assertSame('1', (string) $client->count_of_devices);
        $this->assertSame((string) self::CHAT, $client->chat_id);
        $this->assertSame($user->id, $client->user_id);

        $text = $this->lastEditText();

        $this->assertStringContainsString('اکانت تست شما با موفقیت ایجاد شد.', $text);
        $this->assertStringContainsString('👤 نام کاربری: <code>trial-user</code>', $text);
        $this->assertStringContainsString('🔑 رمز عبور: <code>pass1234</code>', $text);
        $this->assertStringContainsString('🔗 لینک سابسکریبشن: <code>https://sub.example/trial</code>', $text);

        $buttons = [];

        foreach ($this->lastKeyboard() as $row) {
            foreach ($row as $button) {
                $buttons[] = $button['text'].'|'.$button['callback_data'];
            }
        }

        $this->assertContains('📦 | اکانت های من|accounts', $buttons);
        $this->assertContains('↪️ | بازگشت|main_menu', $buttons);
    }

    public function test_the_economic_type_chooses_the_economic_plan(): void
    {
        $this->fakePanel();

        // The economic branch matches the lower-case token exactly as legacy
        // wrote it (`$type === "economic"`); the button the menu renders
        // carries the panel's capitalised group name on purpose.
        $this->handle('getTest_economic', $this->makeUser());

        $this->assertSame(['22'], $this->storePlanIds());
    }

    public function test_a_user_who_already_took_a_trial_is_turned_away(): void
    {
        $this->fakePanel();

        $user = $this->makeUser(usedTest: true);

        $this->handle('getTest_default', $user);

        $this->assertSame(0, $this->storeCalls());
        $this->assertSame(0, Client::query()->count());

        $this->assertStringContainsString('⚠️ شما قبلا درخواست تست داده اید!', $this->lastEditText());
    }

    public function test_an_unknown_trial_type_reports_no_suitable_plan(): void
    {
        $this->fakePanel();

        $this->handle('getTest_Sublink', $this->makeUser());

        $this->assertSame(0, $this->storeCalls());

        $this->assertSame('پلن مناسب برای نوع درخواستی (Sublink) یافت نشد.', $this->lastEditText());
    }

    public function test_a_failed_panel_create_is_reported(): void
    {
        $this->fakePanel([
            'https://api.connectix.vip/v1/seller/clients/store' => Http::response(['error' => 'boom'], 500),
        ]);

        $this->handle('getTest_default', $this->makeUser());

        $this->assertSame('خطا در ایجاد اکانت روی سرور', $this->lastEditText());
        $this->assertSame(0, Client::query()->count());
    }

    public function test_a_client_without_an_id_is_reported(): void
    {
        $this->fakePanel([
            'https://api.connectix.vip/v1/seller/clients/store' => Http::response(['ok' => true], 200),
        ]);

        $this->handle('getTest_default', $this->makeUser());

        $this->assertSame('خطا در ایجاد اکانت', $this->lastEditText());
        $this->assertSame(0, Client::query()->count());
    }
}
