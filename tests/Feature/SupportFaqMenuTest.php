<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Telegram\HandlerRegistry;
use App\Telegram\Handlers\FaqHandler;
use App\Telegram\Handlers\SupportHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The support and FAQ pages, port of the matching bot.php branches.
 */
class SupportFaqMenuTest extends TestCase
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
            'connectix_bot.panel.enabled' => false,
            'connectix_bot.support_telegram' => 'acme_support',
            'connectix_bot.messages' => [
                'contact_support' => 'برای ارتباط با پشتیبانی پیام بدهید.',
                'questions_and_answers' => 'پاسخ سوالات متداول اینجاست.',
            ],
        ]);

        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
    }

    public function test_the_support_page_links_to_the_seller_and_goes_home(): void
    {
        $user = $this->makeUser();

        $this->handle('support', $user, SupportHandler::class);

        $this->assertSame('برای ارتباط با پشتیبانی پیام بدهید.', $this->lastMessage());

        $this->assertSame(
            [
                '📩 |  پیام به پشتیبانی|https://t.me/acme_support',
                '↪️ | بازگشت|main_menu',
            ],
            $this->labels(),
        );
    }

    public function test_the_support_page_still_works_without_a_configured_account(): void
    {
        config(['connectix_bot.support_telegram' => '']);

        $user = $this->makeUser();

        $this->handle('support', $user, SupportHandler::class);

        $this->assertSame([['↪️ | بازگشت|main_menu']], [$this->labels()]);
    }

    public function test_the_faq_page_answers_with_the_panel_text_and_one_way_back(): void
    {
        $user = $this->makeUser();

        $this->handle('faq', $user, FaqHandler::class);

        $this->assertSame('پاسخ سوالات متداول اینجاست.', $this->lastMessage());
        $this->assertSame([['↪️ | بازگشت|main_menu']], [$this->labels()]);
    }

    // -----------------------------------------------------------------
    // Driving
    // -----------------------------------------------------------------

    /**
     * @param  class-string  $expected
     */
    private function handle(string $callback, User $user, string $expected): void
    {
        $registry = $this->app->make(HandlerRegistry::class);
        $update = $this->press($callback);

        $handler = $registry->firstSupporting($update, $user);

        $this->assertInstanceOf($expected, $handler);

        $handler->handle($update, $user);
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
}
