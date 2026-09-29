<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Client;
use App\Models\User;
use App\Telegram\HandlerRegistry;
use App\Telegram\Handlers\AccountHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The accounts menu and the detail page behind each row, driven through the
 * registry the way a button press reaches them.
 */
class AccountMenuTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 555;

    /** @var array<string, mixed>|null */
    private ?array $panelClient = null;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.active' => true,
            'connectix_bot.admin_ids' => ['1'],
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
        ]);

        Http::preventStrayRequests();

        $this->panelClient = $this->clientPayload();

        Http::fake([
            'https://api.connectix.vip/v1/seller/clients/show?id=*' => fn () => Http::response(['client' => $this->panelClient], 200),
            'https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200),
            'https://t.me/*' => Http::response('<html></html>', 200),
        ]);
    }

    /**
     * A panel client with one active plan, one queued plan and the
     * credentials the detail page prints.
     *
     * @return array<string, mixed>
     */
    private function clientPayload(string $username = 'acme-user'): array
    {
        return [
            'id' => 'panel-uuid',
            'name' => 'Ali Account',
            'username' => $username,
            'password' => 'pass1234',
            'count_of_devices' => 2,
            'subscription_link' => 'https://sub.example/abc',
            'plans' => [
                [
                    'name' => '(1x) Unlimited-1M',
                    'is_active' => true,
                    'is_in_queue' => false,
                    'expire_date' => '1404/07/07',
                    'total_used_traffic' => '1.2 گیگ',
                    'activated_at' => '1404/06/07',
                ],
                [
                    'name' => '(2x) 50GB-1M',
                    'is_active' => false,
                    'is_in_queue' => true,
                    'expire_date' => '1404/08/07',
                    'created_at' => '1404/07/06',
                    'gift_days' => 3,
                ],
            ],
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

    public function test_the_list_shows_every_linked_account_and_the_add_button(): void
    {
        $user = $this->makeUser();
        $this->makeClient($user);

        $this->handle('accounts', $user);

        $labels = $this->labels();

        $this->assertContains('🟢 فعال | acme-user|showClient_local-uuid', $labels);
        $this->assertContains('➕ | افزودن اکانت به لیست|add_account', $labels);
        $this->assertContains('↪️ | بازگشت|main_menu', $labels);
        $this->assertStringContainsString('اکانت های متصل یه حساب تلگرام شما', $this->lastMessage());
    }

    public function test_the_empty_list_keeps_the_legacy_fallbacks(): void
    {
        $user = $this->makeUser();

        $this->handle('accounts', $user);

        $labels = $this->labels();

        $this->assertSame(
            [
                '🤷🏻 | اکانتی به تلگرام شما متصل نیست|not',
                '🛍 | خرید اکانت جدید|group',
                '➕ | افزودن اکانت به لیست|add_account',
                '↪️ | بازگشت|main_menu',
            ],
            $labels,
        );
    }

    public function test_the_detail_page_prints_credentials_subscriptions_and_the_reserve_button(): void
    {
        $user = $this->makeUser();
        $this->makeClient($user);

        $this->handle('showClient_local-uuid', $user);

        $text = $this->lastMessage();

        $this->assertStringContainsString('📝 اطلاعات اکانت شما', $text);
        $this->assertStringContainsString('Ali Account', $text);
        $this->assertStringContainsString('pass1234', $text);
        $this->assertStringContainsString('https://sub.example/abc', $text);
        $this->assertStringContainsString('🎯 <b>اشتراک فعال فعلی</b>', $text);
        $this->assertStringContainsString('1404/07/07', $text);
        $this->assertStringContainsString('⏳ <b>اشتراک‌های رزرو شده (در صف فعال‌سازی)</b>', $text);
        $this->assertStringContainsString('+3 روز هدیه', $text);

        $labels = $this->labels();

        $this->assertSame(
            [
                '📆 | رزرو اشتراک جدید برای این اکانت|renew_acc:acme-user:accounts',
                '🏡 | خانه|main_menu',
                '↪️ | بازگشت|accounts',
            ],
            $labels,
        );
    }

    public function test_the_detail_page_offers_a_purchase_when_no_plan_is_running(): void
    {
        $user = $this->makeUser();
        $this->makeClient($user);

        $this->panelClient = $this->clientPayload();
        $this->panelClient['plans'] = [];

        $this->handle('showClient_local-uuid', $user);

        $this->assertStringContainsString('⚠️ در حال حاضر هیچ اشتراک فعالی وجود ندارد.', $this->lastMessage());

        $this->assertContains(
            '🛒 | خرید اشتراک برای این اکانت|renew_acc:acme-user:accounts',
            $this->labels(),
        );
    }

    public function test_the_detail_page_refuses_a_client_belonging_to_someone_else(): void
    {
        $user = $this->makeUser();
        $other = $this->makeUser(777);

        Client::query()->create([
            'id' => 'foreign-uuid',
            'count_of_devices' => 1,
            'username' => 'foreign-user',
            'password' => 'x',
            'chat_id' => (string) $other->chat_id,
            'user_id' => $other->id,
            'created_at' => now(),
        ]);

        $this->handle('showClient_foreign-uuid', $user);

        $this->assertSame('این اکانت به حساب تلگرام شما متصل نیست.', $this->lastAlert());
        $this->assertSame([], $this->sentMessages());
    }

    // -----------------------------------------------------------------
    // Driving
    // -----------------------------------------------------------------

    private function handle(string $callback, User $user): void
    {
        $registry = $this->app->make(HandlerRegistry::class);
        $update = $this->press($callback);

        $handler = $registry->firstSupporting($update, $user);

        $this->assertInstanceOf(AccountHandler::class, $handler);

        $handler->handle($update, $user);
    }

    private function press(string $data): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => self::CHAT, 'is_bot' => false, 'first_name' => 'Ali'],
                'message' => [
                    'message_id' => 42,
                    'chat' => ['id' => self::CHAT],
                    'text' => 'menu',
                ],
                'data' => $data,
            ],
        ]);
    }

    /**
     * The inline keyboard of the last send or edit, flattened.
     *
     * @return list<string>
     */
    private function labels(): array
    {
        $keyboard = null;

        foreach (Http::recorded() as [$request, $response]) {
            foreach (['sendMessage', 'editMessageText'] as $method) {
                if ($request->method() === 'POST' && str_contains($request->url(), $method)) {
                    $markup = $request['reply_markup'] ?? null;

                    if ($markup !== null) {
                        $keyboard = is_string($markup) ? json_decode($markup, true) : $markup;
                    }
                }
            }
        }

        $this->assertIsArray($keyboard, 'no message with a reply_markup was recorded');

        $found = [];

        foreach ($keyboard['inline_keyboard'] ?? [] as $row) {
            foreach ($row as $button) {
                $found[] = (string) $button['text'].'|'.(string) ($button['callback_data'] ?? $button['url'] ?? '');
            }
        }

        return $found;
    }

    /**
     * The text of the last sendMessage or editMessageText.
     */
    private function lastMessage(): string
    {
        $messages = $this->sentMessages();

        return $messages === [] ? '' : (string) end($messages);
    }

    /**
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
}
