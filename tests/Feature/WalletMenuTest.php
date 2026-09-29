<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use App\Telegram\HandlerRegistry;
use App\Telegram\Handlers\WalletHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The wallet page and the button that arms a top-up, pressed through the
 * registry.
 */
class WalletMenuTest extends TestCase
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

    private function makeUser(int $balance = 0): User
    {
        $user = User::query()->create([
            'chat_id' => (string) self::CHAT,
            'telegram_id' => (string) self::CHAT,
            'name' => 'Ali',
            'test' => false,
        ]);

        $this->app->make(WalletService::class)->create((string) self::CHAT, $balance);

        return $user;
    }

    public function test_the_wallet_page_shows_the_balance_and_clears_any_half_finished_flow(): void
    {
        $user = $this->makeUser(25_000);

        $this->app->make(UserStateService::class)->set($user, ['action' => 'buy', 'step' => 'group']);

        $this->handle('wallet', $user);

        $text = $this->lastMessage();

        $this->assertStringContainsString('🤑 موجودی کیف پول شما:', $text);
        $this->assertStringContainsString('25,000 تومان', $text);
        $this->assertStringContainsString('👤 نام: Ali', $text);
        $this->assertStringContainsString('🔢 آیدی عددی: '.self::CHAT, $text);

        $this->assertSame(
            [
                '💰 | افزایش موجودی|wallet_increase:0',
                '↪️ | بازگشت|main_menu',
            ],
            $this->labels(),
        );

        $this->assertSame([], $this->state($user), 'legacy cleared the state on the wallet page');
    }

    public function test_the_wallet_page_creates_the_wallet_on_first_visit(): void
    {
        $user = User::query()->create([
            'chat_id' => (string) self::CHAT,
            'telegram_id' => (string) self::CHAT,
            'name' => 'Ali',
            'test' => false,
        ]);

        $this->handle('wallet', $user);

        $this->assertNotNull($this->app->make(WalletService::class)->find((string) self::CHAT));
        $this->assertStringContainsString('0 تومان', $this->lastMessage());
    }

    public function test_the_increase_button_arms_the_amount_step_and_offers_cancel(): void
    {
        $user = $this->makeUser();

        $this->handle('wallet_increase:0', $user);

        $state = $this->state($user);

        $this->assertSame(UserStateService::ACTION_WALLET_INCREASE, $state['action'] ?? null);
        $this->assertSame('get_amount', $state['step'] ?? null);

        $this->assertStringContainsString('💰 لطفا مبلغ مدنظر جهت افزایش موجودی کیف پول خود', $this->lastMessage());

        $this->assertSame(
            [['❌ | انصراف|wallet']],
            [$this->labels()],
            'the prompt carries only the cancel button, like keyboard("wallet_increase")',
        );
    }

    // -----------------------------------------------------------------
    // Driving
    // -----------------------------------------------------------------

    private function handle(string $callback, User $user): void
    {
        $registry = $this->app->make(HandlerRegistry::class);
        $update = $this->press($callback);

        $handler = $registry->firstSupporting($update, $user);

        $this->assertInstanceOf(WalletHandler::class, $handler);

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

    private function lastMessage(): string
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

        return $messages === [] ? '' : (string) end($messages);
    }

    private function state(User $user): array
    {
        return $this->app->make(UserStateService::class)->get($user) ?? [];
    }
}
