<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Download\DownloadLinkService;
use App\Services\Guide\GuideService;
use App\Telegram\Handlers\DownloadHandler;
use App\Telegram\Handlers\GuideHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The guide and app download menus, ported from the `guide`/`guide_*` and
 * `apps`/`app_*` branches of bot.php plus the legacy `guide()` and `app()`.
 *
 * The menus, links and videos are driven through the container exactly as the
 * gateway dispatches them, with the guide files and the scraped landing page
 * provided as fixtures.
 */
class GuideAndDownloadMenuTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 555;

    private string $base;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        $this->base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'connectix-guide-menu-test';

        $this->makeDirectory($this->base.DIRECTORY_SEPARATOR.'guide');
        $this->makeDirectory($this->base.DIRECTORY_SEPARATOR.'guide'.DIRECTORY_SEPARATOR.'custom');

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.active' => true,
            'connectix_bot.app_name' => 'Acme VPN',
            'connectix_bot.admin_ids' => [],
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.telegram_app_username' => 'connectixapp',
            'connectix_bot.bank.name' => null,
        ]);

        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);

        $this->app->instance(GuideService::class, new GuideService(
            $this->base.DIRECTORY_SEPARATOR.'guide',
            $this->base.DIRECTORY_SEPARATOR.'guide'.DIRECTORY_SEPARATOR.'custom',
        ));

        $this->app->instance(DownloadLinkService::class, new DownloadLinkService('https://connectix.test/#download', 60, 5));
    }

    protected function tearDown(): void
    {
        $this->deleteTree($this->base);

        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Fixtures
    // -----------------------------------------------------------------

    private function makeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            mkdir($path, 0777, true);
        }
    }

    private function write(string $relative, string $contents): void
    {
        $path = $this->base.DIRECTORY_SEPARATOR.$relative;

        $this->makeDirectory(dirname($path));

        file_put_contents($path, $contents);
    }

    private function deleteTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $full = $path.DIRECTORY_SEPARATOR.$entry;

            is_dir($full) ? $this->deleteTree($full) : @unlink($full);
        }

        @rmdir($path);
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

    private function press(string $data, int $chatId = self::CHAT): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-menu',
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

    private function recorded(): Collection
    {
        return collect(Http::recorded())->map(fn (array $pair) => $pair[0]);
    }

    private function requestBodies(string $needle): array
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), $needle))
            ->map(fn ($request) => $this->requestBody($request))
            ->values()
            ->all();
    }

    private function requestBody($request): array
    {
        $text = (string) $request['text'] ?? '';

        $markup = $request['reply_markup'] ?? null;
        $keyboard = is_string($markup) ? json_decode($markup, true) : $markup;

        return [
            'text' => $text,
            'keyboard' => $keyboard['inline_keyboard'] ?? [],
        ];
    }

    private function lastTextOf(string $needle): string
    {
        $texts = array_column($this->requestBodies($needle), 'text');

        $this->assertNotSame([], $texts, "no $needle was recorded");

        return $texts[array_key_last($texts)];
    }

    private function lastKeyboardOf(string $needle): array
    {
        $keyboards = array_column($this->requestBodies($needle), 'keyboard');

        $this->assertNotSame([], $keyboards, "no $needle was recorded");

        return $keyboards[array_key_last($keyboards)];
    }

    private function answerCallbackTexts(): array
    {
        return array_column($this->requestBodies('answerCallbackQuery'), 'text');
    }

    private function hasRequest(string $needle): bool
    {
        return $this->recorded()
            ->filter(fn ($request) => $request->method() === 'POST' && str_contains($request->url(), $needle))
            ->isNotEmpty();
    }

    // -----------------------------------------------------------------
    // Guide menu
    // -----------------------------------------------------------------

    public function test_the_guide_menu_is_sent_fresh_and_the_source_message_is_deleted(): void
    {
        $this->write('guide/custom/intro.txt', "https://example.com/intro\n");
        $this->write('guide/custom/Setup.mp4', 'binary');

        $user = $this->makeUser();

        $this->app->make(GuideHandler::class)->handle($this->press('guide'), $user);

        $this->assertSame('📖 لطفا نحوه آموزش را انتحاب کنید.', $this->lastTextOf('sendMessage'));
        $this->assertTrue($this->hasRequest('deleteMessage'));
        $this->assertFalse($this->hasRequest('editMessageText'));

        $buttons = [];

        foreach ($this->lastKeyboardOf('sendMessage') as $row) {
            foreach ($row as $button) {
                $buttons[] = $button['text'].'|'.(string) ($button['callback_data'] ?? $button['url'] ?? '');
            }
        }

        $this->assertContains('📲 | آموزش استفاده از نرم افزار|guide_use', $buttons);
        $this->assertContains('⚙ | آموزش نصب نرم افزار|guide_install', $buttons);
        $this->assertContains('🎬 | intro|https://example.com/intro', $buttons);
        $this->assertContains('🎬 | Setup|guide_custom_1', $buttons);
        $this->assertContains('↪️ | بازگشت|main_menu', $buttons);
    }

    public function test_the_install_menu_lists_the_platforms(): void
    {
        $user = $this->makeUser();

        $this->app->make(GuideHandler::class)->handle($this->press('guide_install'), $user);

        $this->assertSame('⚙ سیستم عامل مورد نظر را انتخاب نمایید:', $this->lastTextOf('editMessageText'));

        $buttons = [];

        foreach ($this->lastKeyboardOf('editMessageText') as $row) {
            foreach ($row as $button) {
                $buttons[] = $button['text'].'|'.(string) ($button['callback_data'] ?? $button['url'] ?? '');
            }
        }

        $this->assertContains('📱 | آیفون (iOS)|guide_ios', $buttons);
        $this->assertContains('🤖 | اندروید|guide_android', $buttons);
        $this->assertContains('🖥 | مک|guide_mac', $buttons);
        $this->assertContains('💻 | ویندوز|guide_windows', $buttons);
        $this->assertContains('🐧 | لینوکس (Debian)|guide_linux', $buttons);
        $this->assertContains('↪️ | بازگشت|guide', $buttons);
    }

    public function test_a_use_link_renders_a_linked_button(): void
    {
        $this->write('guide/use.txt', "https://example.com/use\n");

        $user = $this->makeUser();

        $this->app->make(GuideHandler::class)->handle($this->press('guide_use'), $user);

        $this->assertSame('برای مشاهده آموزش از دکمه زیر استفاده کنید.', $this->lastTextOf('editMessageText'));

        $buttons = [];

        foreach ($this->lastKeyboardOf('editMessageText') as $row) {
            foreach ($row as $button) {
                $buttons[] = $button['text'].'|'.(string) ($button['callback_data'] ?? $button['url'] ?? '');
            }
        }

        $this->assertContains('🎬 | مشاهده آموزش|https://example.com/use', $buttons);
        $this->assertContains('↪️ | بازگشت|guide', $buttons);
    }

    public function test_a_use_video_is_uploaded_and_the_source_message_is_deleted(): void
    {
        $this->write('guide/use.mp4', 'binary');

        $user = $this->makeUser();

        $this->app->make(GuideHandler::class)->handle($this->press('guide_use'), $user);

        $this->assertTrue($this->hasRequest('sendVideo'));
        $this->assertTrue($this->hasRequest('deleteMessage'));
        $this->assertFalse($this->hasRequest('editMessageText'));
        $this->assertSame([], $this->answerCallbackTexts());
    }

    public function test_a_platform_video_is_uploaded(): void
    {
        $this->write('guide/ios.mp4', 'binary');

        $user = $this->makeUser();

        $this->app->make(GuideHandler::class)->handle($this->press('guide_ios'), $user);

        $this->assertTrue($this->hasRequest('sendVideo'));
        $this->assertTrue($this->hasRequest('deleteMessage'));
    }

    public function test_an_action_without_link_or_video_alerts(): void
    {
        $user = $this->makeUser();

        $this->app->make(GuideHandler::class)->handle($this->press('guide_use'), $user);

        $this->assertSame(['🙅🏻 فعلا ویدیو آموزشی در دسترس نمی باشد!'], $this->answerCallbackTexts());
        $this->assertFalse($this->hasRequest('sendVideo'));
    }

    public function test_a_custom_video_plays(): void
    {
        $this->write('guide/custom/first.mp4', 'binary');

        $user = $this->makeUser();

        $this->app->make(GuideHandler::class)->handle($this->press('guide_custom_0'), $user);

        $this->assertTrue($this->hasRequest('sendVideo'));
        $this->assertTrue($this->hasRequest('deleteMessage'));
        $this->assertSame([], $this->answerCallbackTexts());
    }

    public function test_a_custom_link_item_is_not_playable(): void
    {
        $this->write('guide/custom/notes.txt', "https://example.com/notes\n");

        $user = $this->makeUser();

        $this->app->make(GuideHandler::class)->handle($this->press('guide_custom_0'), $user);

        $this->assertSame(['🙅🏻 فعلا ویدیو آموزشی در دسترس نمی باشد!'], $this->answerCallbackTexts());
        $this->assertFalse($this->hasRequest('sendVideo'));
    }

    // -----------------------------------------------------------------
    // Download menus
    // -----------------------------------------------------------------

    private function landingPage(): string
    {
        return <<<'HTML'
        <!DOCTYPE html>
        <html><body>
        <section id="download">
          <div class="service-box">
            <h5>Android Application</h5>
            <a href="https://example.com/android.apk">Download APK</a>
            <a href="https://example.com/android2.apk">Mirror</a>
          </div>
          <div class="service-box">
            <h5>iOS Application</h5>
            <a href="https://example.com/ios.ipa">Download IPA</a>
          </div>
        </section>
        </body></html>
        HTML;
    }

    public function test_the_apps_menu_lists_the_platforms(): void
    {
        $user = $this->makeUser();

        $this->app->make(DownloadHandler::class)->handle($this->press('apps'), $user);

        $this->assertSame('⚙ لطفا سیستم عامل مدنظر خود را انتخاب کنید:', $this->lastTextOf('editMessageText'));

        $buttons = [];

        foreach ($this->lastKeyboardOf('editMessageText') as $row) {
            foreach ($row as $button) {
                $buttons[] = $button['text'].'|'.$button['callback_data'];
            }
        }

        $this->assertContains('📱 | آیفون (iOS)|app_ios', $buttons);
        $this->assertContains('🤖 | اندروید|app_android', $buttons);
        $this->assertContains('🖥 | مک|app_mac', $buttons);
        $this->assertContains('💻 | ویندوز|app_windows', $buttons);
        $this->assertContains('🐧 | لینوکس (Debian)|app_linux', $buttons);
        $this->assertContains('↪️ | بازگشت|main_menu', $buttons);
    }

    public function test_the_android_page_lists_its_links_and_the_telegram_channel(): void
    {
        Http::fake(['connectix.test/*' => Http::response($this->landingPage(), 200)]);

        $user = $this->makeUser();

        $this->app->make(DownloadHandler::class)->handle($this->press('app_android'), $user);

        $text = $this->lastTextOf('editMessageText');

        $this->assertStringContainsString('دانلود اپلیکیشن Connectix برای <b>اندروید 🤖</b>', $text);

        $buttons = [];

        foreach ($this->lastKeyboardOf('editMessageText') as $row) {
            foreach ($row as $button) {
                $buttons[] = $button['text'].'|'.(string) ($button['callback_data'] ?? $button['url'] ?? '');
            }
        }

        $this->assertContains('📥 | دانلود مستقیم APK|https://example.com/android.apk', $buttons);
        $this->assertContains('📥 | Mirror|https://example.com/android2.apk', $buttons);
        $this->assertContains('📲 | دانلود از تلگرام|https://t.me/connectixapp/4', $buttons);
        $this->assertContains('🏡 | خانه|main_menu', $buttons);
        $this->assertContains('↪️ | بازگشت|apps', $buttons);
    }

    public function test_the_ios_page_has_no_telegram_channel_button(): void
    {
        Http::fake(['connectix.test/*' => Http::response($this->landingPage(), 200)]);

        $user = $this->makeUser();

        $this->app->make(DownloadHandler::class)->handle($this->press('app_ios'), $user);

        $this->assertStringContainsString('<b>آیفون (iOS) 📱</b>', $this->lastTextOf('editMessageText'));

        $labels = '|'.$this->labelsAndCallbacks($this->lastKeyboardOf('editMessageText'));

        $this->assertStringContainsString('📥 | دانلود مستقیم IPA', $labels);
        $this->assertStringNotContainsString('دانلود از تلگرام', $labels);
    }

    public function test_a_platform_without_links_still_offers_navigation(): void
    {
        Http::fake(['connectix.test/*' => Http::response($this->landingPage(), 200)]);

        $user = $this->makeUser();

        $this->app->make(DownloadHandler::class)->handle($this->press('app_linux'), $user);

        $this->assertStringContainsString('<b>لینوکس 🐧</b>', $this->lastTextOf('editMessageText'));

        $buttons = $this->lastKeyboardOf('editMessageText');

        $this->assertCount(1, $buttons);
        $this->assertSame('main_menu', $buttons[0][0]['callback_data']);
        $this->assertSame('apps', $buttons[0][1]['callback_data']);
    }

    /**
     * @param  array<int, array<int, array<string, mixed>>>  $rows
     */
    private function labelsAndCallbacks(array $rows): string
    {
        $found = [];

        foreach ($rows as $row) {
            foreach ($row as $button) {
                $found[] = (string) $button['text'];
            }
        }

        return implode('|', $found);
    }
}
