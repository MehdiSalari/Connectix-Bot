<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\AdminGuard;
use App\Services\Telegram\ChannelMembershipService;
use App\Services\Telegram\TelegramGateway;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\HandlerRegistry;
use App\Telegram\TelegramUpdate;
use Illuminate\Contracts\Container\Container;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The dispatch pipeline, in the order legacy applied it: bot active, channel
 * gate, user sync, handler.
 */
class TelegramGatewayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Without a token TelegramService refuses to issue a request at all, so
        // the HTTP fakes below would never see anything.
        config(['connectix_bot.telegram.token' => 'test-token']);

        // The handler doubles share one counter across the suite.
        HandlingHandler::$calls = 0;

        // A pattern that silently fails to match would otherwise be answered
        // with an empty 200 and hide the real cause of a broken test.
        Http::preventStrayRequests();
    }

    /**
     * Register every HTTP stub the gateway can reach, in one call.
     *
     * Http::fake() merges stubs instead of replacing them and resolves the
     * first match wins, so registering a generic Bot API stub in setUp() and a
     * specific one later would let the generic shadow it. Each test therefore
     * declares the whole set exactly once.
     *
     * @param  array<string, mixed>  $telegramOverrides  Must come first so a
     *                                                   specific pattern wins.
     */
    private function fakeHttp(array $telegramOverrides = [], bool $withProfile = true): void
    {
        $stubs = $telegramOverrides;

        $stubs['https://t.me/*'] = $withProfile
            ? Http::response(
                '<html><head><meta property="og:image" content="https://t.me/i/userpic/320/ali.jpg">'
                .'</head><body><div class="tgme_page_title"><span dir="auto">Ali R</span></div></body></html>',
                200,
            )
            : Http::response('<html></html>', 200);

        // The catch-all must not overwrite a test that speaks for the whole
        // Bot API, e.g. the one that simulates an outage.
        if (! array_key_exists('https://api.telegram.org/*', $stubs)) {
            $stubs['https://api.telegram.org/*'] = Http::response(['ok' => true, 'result' => true], 200);
        }

        Http::fake($stubs);
    }

    /**
     * @param  array<int, class-string<UpdateHandler>>  $handlers
     */
    private function gateway(array $handlers = []): TelegramGateway
    {
        $registry = new HandlerRegistry($this->app->make(Container::class), $handlers);

        return new TelegramGateway(
            $this->app->make(TelegramService::class),
            $this->app->make(UserService::class),
            $this->app->make(UserStateService::class),
            $this->app->make(ChannelMembershipService::class),
            $registry,
            $this->app->make(PanelSettingsService::class),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function startUpdate(int $chatId = 555): array
    {
        return [
            'update_id' => 1,
            'message' => [
                'message_id' => 1,
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'chat' => ['id' => $chatId, 'first_name' => 'Ali', 'username' => 'ali'],
                'text' => '/start',
            ],
        ];
    }

    public function test_it_creates_the_user_and_wallet_on_first_contact(): void
    {
        config(['connectix_bot.active' => true, 'connectix_bot.force_channel_join' => false]);
        $this->fakeHttp();

        $this->gateway([AlwaysHandles::class])->handle(TelegramUpdate::fromArray($this->startUpdate()));

        $user = User::query()->where('chat_id', '555')->first();

        $this->assertNotNull($user);
        $this->assertSame('ali', $user->telegram_id);
        $this->assertSame('Ali R', $user->name, 'the display name must come from the scraped profile');
        $this->assertSame('https://t.me/i/userpic/320/ali.jpg', $user->avatar);
        $this->assertDatabaseHas('wallets', ['chat_id' => '555', 'balance' => '0']);
    }

    public function test_it_dispatches_to_the_first_supporting_handler(): void
    {
        config(['connectix_bot.active' => true, 'connectix_bot.force_channel_join' => false]);
        $this->fakeHttp();

        $this->gateway([NeverHandles::class, AlwaysHandles::class])
            ->handle(TelegramUpdate::fromArray($this->startUpdate()));

        $this->assertSame(1, HandlingHandler::$calls, 'only the first matching handler may run');
    }

    public function test_it_clears_a_stale_conversation_state_before_dispatching(): void
    {
        config(['connectix_bot.active' => true, 'connectix_bot.force_channel_join' => false]);

        $this->fakeHttp();

        $user = User::query()->create(['chat_id' => '555']);
        $user->forceFill(['action' => json_encode(['action' => 'buy', 'plan' => '99'])])->save();

        $this->gateway([AlwaysHandles::class])->handle(TelegramUpdate::fromArray($this->startUpdate()));

        $this->assertDatabaseHas('users', ['chat_id' => '555', 'action' => null]);
    }

    public function test_an_inactive_bot_refuses_the_user(): void
    {
        config(['connectix_bot.active' => false, 'connectix_bot.force_channel_join' => false]);
        $this->fakeHttp();

        $this->gateway([AlwaysHandles::class])->handle(TelegramUpdate::fromArray($this->startUpdate()));

        $this->assertSame(0, HandlingHandler::$calls);
        $this->assertDatabaseMissing('users', ['chat_id' => '555']);
        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'غیرفعال'));
    }

    public function test_the_channel_gate_blocks_a_user_who_has_not_joined(): void
    {
        config(['connectix_bot.active' => true, 'connectix_bot.force_channel_join' => true]);
        config(['connectix_bot.telegram_channel_id' => '-100123']);

        $this->fakeHttp([
            'https://api.telegram.org/*getChatMember*' => Http::response([
                'ok' => true,
                'result' => ['status' => 'left'],
            ], 200),
        ], withProfile: false);

        $this->gateway([AlwaysHandles::class])->handle(TelegramUpdate::fromArray($this->startUpdate()));

        $this->assertSame(0, HandlingHandler::$calls);
        $this->assertDatabaseMissing('users', ['chat_id' => '555']);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'عضو شوید'));
    }

    public function test_the_channel_gate_lets_a_member_through(): void
    {
        config(['connectix_bot.active' => true, 'connectix_bot.force_channel_join' => true]);
        config(['connectix_bot.telegram_channel_id' => '-100123']);

        $this->fakeHttp([
            'https://api.telegram.org/*getChatMember*' => Http::response([
                'ok' => true,
                'result' => ['status' => 'member'],
            ], 200),
        ], withProfile: false);

        $this->gateway([AlwaysHandles::class])->handle(TelegramUpdate::fromArray($this->startUpdate()));

        $this->assertTrue(
            User::query()->where('chat_id', '555')->exists(),
            'the gate must let the user through to the sync step'
        );
        $this->assertSame(1, HandlingHandler::$calls);
    }

    /**
     * A Telegram outage must not lock every user out of the bot.
     */
    public function test_the_channel_gate_fails_open_when_telegram_is_unreachable(): void
    {
        config(['connectix_bot.active' => true, 'connectix_bot.force_channel_join' => true]);
        config(['connectix_bot.telegram_channel_id' => '-100123']);

        $this->fakeHttp([
            'https://api.telegram.org/*' => Http::response(['ok' => false, 'description' => 'boom'], 500),
        ], withProfile: false);

        $this->gateway([AlwaysHandles::class])->handle(TelegramUpdate::fromArray($this->startUpdate()));

        $this->assertSame(1, HandlingHandler::$calls);
    }

    public function test_an_unclaimed_update_answers_the_callback_so_the_button_stops_spinning(): void
    {
        config(['connectix_bot.active' => true, 'connectix_bot.force_channel_join' => false]);
        $this->fakeHttp();

        $update = TelegramUpdate::fromArray([
            'update_id' => 2,
            'callback_query' => [
                'id' => 'cb-1',
                'from' => ['id' => 555, 'is_bot' => false],
                'message' => ['message_id' => 3, 'chat' => ['id' => 555]],
                'data' => 'nothing_handles_this',
            ],
        ]);

        $this->gateway()->handle($update);

        Http::assertSent(fn ($request) => str_contains($request->url(), 'answerCallbackQuery')
            && $request['callback_query_id'] === 'cb-1');
    }

    public function test_a_throwing_handler_is_contained_and_the_user_is_told(): void
    {
        config(['connectix_bot.active' => true, 'connectix_bot.force_channel_join' => false]);
        $this->fakeHttp();

        $this->gateway([ExplodingHandler::class])->handle(TelegramUpdate::fromArray($this->startUpdate()));

        Http::assertSent(fn ($request) => str_contains($request->url(), 'sendMessage')
            && str_contains($request['text'], 'خطایی رخ داد'));
    }

    public function test_an_update_without_a_chat_is_ignored(): void
    {
        config(['connectix_bot.active' => true, 'connectix_bot.force_channel_join' => false]);
        $this->fakeHttp();

        $this->gateway([AlwaysHandles::class])->handle(TelegramUpdate::fromArray(['update_id' => 3]));

        $this->assertSame(0, HandlingHandler::$calls);
        Http::assertNothingSent();
    }

    public function test_the_admin_guard_matches_the_legacy_string_comparison(): void
    {
        config(['connectix_bot.admin_ids' => ['100', '200']]);

        $guard = AdminGuard::fromConfig();

        $this->assertTrue($guard->isAdmin(100));
        $this->assertTrue($guard->isAdmin('200'));
        $this->assertFalse($guard->isAdmin(300));
        $this->assertFalse($guard->isAdmin(null));
    }
}

/**
 * Shared counter for the test doubles below.
 */
abstract class HandlingHandler implements UpdateHandler
{
    public static int $calls = 0;

    public function handle(TelegramUpdate $update, User $user): void
    {
        static::$calls++;
    }
}

class AlwaysHandles extends HandlingHandler
{
    public function supports(TelegramUpdate $update, User $user): bool
    {
        return true;
    }
}

class NeverHandles extends HandlingHandler
{
    public function supports(TelegramUpdate $update, User $user): bool
    {
        return false;
    }
}

class ExplodingHandler extends HandlingHandler
{
    public function supports(TelegramUpdate $update, User $user): bool
    {
        return true;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        throw new \RuntimeException('handler exploded');
    }
}
