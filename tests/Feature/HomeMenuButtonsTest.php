<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Telegram\HandlerRegistry;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Every callback the bot puts on its own keyboards must be claimed by a
 * handler.
 *
 * The registry used to reference handlers that were never written, and
 * `class_exists` silently skipped them, so the home menu's account, wallet,
 * support and FAQ buttons all answered with the "expired" fallback while the
 * suite stayed green. Pressing each button through the real registry is the
 * regression net for that class of gap.
 */
class HomeMenuButtonsTest extends TestCase
{
    use RefreshDatabase;

    private const CHAT = 555;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.active' => true,
            'connectix_bot.admin_ids' => ['1'],
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
    }

    /**
     * The buttons `KeyboardFactory::mainMenu()` can emit, plus the menus they
     * walk into.
     *
     * @return array<int, string>
     */
    private function homeCallbacks(): array
    {
        return [
            'accounts',
            'action:buy_or_renew_service',
            'apps',
            'guide',
            'support',
            'faq',
            'wallet',
            'get_test',
            'always_select:0',
            'main_menu',
            'new_menu',
            'add_account',
            'renew',
            'group',
            'buy',
            'not',
        ];
    }

    public function test_every_button_the_bot_emits_is_claimed_by_a_handler(): void
    {
        $user = User::query()->create([
            'chat_id' => (string) self::CHAT,
            'telegram_id' => (string) self::CHAT,
            'name' => 'Ali',
            'test' => false,
        ]);

        $registry = $this->app->make(HandlerRegistry::class);

        foreach ($this->homeCallbacks() as $callback) {
            $handler = $registry->firstSupporting($this->press($callback), $user);

            $this->assertNotNull(
                $handler,
                "no handler claims `{$callback}`; the button would answer with the expired fallback",
            );
        }
    }

    public function test_the_detail_and_deposit_entry_buttons_are_claimed_too(): void
    {
        $user = User::query()->create([
            'chat_id' => (string) self::CHAT,
            'telegram_id' => (string) self::CHAT,
            'name' => 'Ali',
            'test' => false,
        ]);

        $registry = $this->app->make(HandlerRegistry::class);

        foreach (['showClient_some-uuid', 'wallet_increase:0', 'always_acc:acme-user'] as $callback) {
            $handler = $registry->firstSupporting($this->press($callback), $user);

            $this->assertNotNull($handler, "no handler claims `{$callback}`");
        }
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
}
