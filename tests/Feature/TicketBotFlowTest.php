<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use App\Services\User\UserStateService;
use App\Telegram\Handlers\TicketHandler;
use App\Telegram\TelegramUpdate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The bot side of ticketing, ported from the feature fork's bot.php branches
 * (`tickets`, `tickets_new`, the ticketslist/myticket/user/uclose family and
 * the three text steps).
 *
 * Driven through TicketHandler directly — the registry gains it in a later
 * wave — with the same TelegramUpdate fakes and Http::fake('api.telegram.org')
 * the other bot tests use.
 */
class TicketBotFlowTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = 1;

    private const ME = 555;

    private const STRANGER = 777;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'connectix_bot.telegram.token' => 'test-token',
            'connectix_bot.connectix.token' => 'panel-token',
            'connectix_bot.active' => true,
            'connectix_bot.admin_ids' => [(string) self::ADMIN],
            'connectix_bot.messages' => [],
            'connectix_bot.panel.enabled' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake(['https://api.telegram.org/*' => Http::response(['ok' => true, 'result' => true], 200)]);
    }

    // ------------------------------------------------------------------
    // Fixtures
    // ------------------------------------------------------------------

    private function makeUser(int $chatId, string $name = 'Ali'): User
    {
        return User::query()->create([
            'chat_id' => (string) $chatId,
            'telegram_id' => (string) $chatId,
            'name' => $name,
            'test' => false,
        ]);
    }

    private function makeTicket(string $chatId, string $subject = 'پیام از ربات تلگرام'): Ticket
    {
        return Ticket::query()->create([
            'ticket_number' => 'TK-'.str_pad((string) (Ticket::query()->count() + 1), 6, '0', STR_PAD_LEFT),
            'chat_id' => $chatId,
            'subject' => $subject,
            'department' => 'پشتیبانی عمومی',
            'status' => 'OPEN',
            'priority' => 'MEDIUM',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ------------------------------------------------------------------
    // Driving
    // ------------------------------------------------------------------

    /** Press a callback button as $chatId. */
    private function press(string $data, int $chatId = self::ME): TicketHandler
    {
        $user = User::query()->where('chat_id', (string) $chatId)->firstOrFail();
        $handler = $this->app->make(TicketHandler::class);
        $update = $this->callbackUpdate($data, $chatId);

        $this->assertTrue($handler->supports($update, $user), "handler must claim {$data}");

        $handler->handle($update, $user);

        return $handler;
    }

    /** Send a plain text message as $chatId. */
    private function send(string $text, int $chatId = self::ME): TicketHandler
    {
        $user = User::query()->where('chat_id', (string) $chatId)->firstOrFail();
        $handler = $this->app->make(TicketHandler::class);
        $update = TelegramUpdate::fromArray([
            'update_id' => 2,
            'message' => [
                'message_id' => 7,
                'from' => ['id' => $chatId, 'is_bot' => false, 'first_name' => 'Ali'],
                'chat' => ['id' => $chatId],
                'text' => $text,
            ],
        ]);

        $this->assertTrue($handler->supports($update, $user));

        $handler->handle($update, $user);

        return $handler;
    }

    private function callbackUpdate(string $data, int $chatId): TelegramUpdate
    {
        return TelegramUpdate::fromArray([
            'update_id' => 1,
            'callback_query' => [
                'id' => 'cb-1',
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

    /** @return list<string> "text|callback_data" of every recorded keyboard. */
    private function labels(): array
    {
        $found = [];

        foreach (Http::recorded() as [$request]) {
            if ($request->method() !== 'POST') {
                continue;
            }

            $markup = $request['reply_markup'] ?? null;

            if ($markup === null) {
                continue;
            }

            $keyboard = is_string($markup) ? json_decode($markup, true) : $markup;

            foreach ($keyboard['inline_keyboard'] ?? [] as $row) {
                foreach ($row as $button) {
                    $found[] = (string) $button['text'].'|'.(string) ($button['callback_data'] ?? '');
                }
            }
        }

        return $found;
    }

    /** The last sendMessage/editMessageText text the bot produced. */
    private function lastText(): string
    {
        $texts = [];

        foreach (Http::recorded() as [$request]) {
            if ($request->method() !== 'POST') {
                continue;
            }

            foreach (['sendMessage', 'editMessageText'] as $method) {
                if (str_contains($request->url(), $method)) {
                    $texts[] = (string) $request['text'];
                }
            }
        }

        return $texts === [] ? '' : (string) end($texts);
    }

    /** The recorded request whose text contains $needle (optionally by chat id). */
    private function requestFor(string $needle, ?string $chatId = null)
    {
        foreach (Http::recorded() as [$request]) {
            if ($request->method() !== 'POST') {
                continue;
            }

            $text = (string) ($request['text'] ?? '');

            if (! str_contains($text, $needle)) {
                continue;
            }

            if ($chatId !== null && (string) $request['chat_id'] !== $chatId) {
                continue;
            }

            return $request;
        }

        return null;
    }

    /** The last recorded Telegram request, for per-target assertions. */
    private function lastRequest()
    {
        $last = null;

        foreach (Http::recorded() as [$request]) {
            if ($request->method() === 'POST') {
                $last = $request;
            }
        }

        return $last;
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_the_support_menu_offers_a_new_ticket_the_list_and_the_main_menu(): void
    {
        $this->makeUser(self::ME);
        $user = User::query()->where('chat_id', (string) self::ME)->firstOrFail();
        $this->app->make(UserStateService::class)->set($user, ['action' => 'buy', 'step' => 'group']);

        $this->press('tickets');

        $text = $this->lastText();

        $this->assertStringContainsString('سیستم پشتیبانی تیکتینگ', $text);
        $this->assertSame(
            [
                '✍️ ارسال تیکت جدید|tickets_new',
                '📋 تیکتهای من|ticketslist_1',
                '🔙 بازگشت به منوی اصلی|main_menu',
            ],
            array_slice($this->labels(), -3),
            'the menu shows exactly the fork three buttons',
        );

        $user->refresh();
        $this->assertSame([], $this->app->make(UserStateService::class)->get($user) ?? [],
            'legacy cleared the half-finished flow on the tickets page');
    }

    public function test_tap_on_new_ticket_arms_the_text_step(): void
    {
        $this->makeUser(self::ME);

        $this->press('tickets_new');

        $this->assertStringContainsString('ارسال پیام به پشتیبانی', $this->lastText());

        $user = User::query()->where('chat_id', (string) self::ME)->firstOrFail();

        $this->assertSame(
            'ticket_new',
            ($this->app->make(UserStateService::class)->get($user) ?? [])['action'] ?? null,
        );
    }

    public function test_the_armed_text_becomes_a_ticket_and_the_admin_is_notified(): void
    {
        $this->makeUser(self::ME);
        $this->press('tickets_new');
        $this->send('سلام، اینترنت قطع است');

        $ticket = Ticket::query()->firstOrFail();

        $this->assertSame('پیام از ربات تلگرام', $ticket->subject);
        $this->assertSame('OPEN', $ticket->status);
        $this->assertSame('پشتیبانی عمومی', $ticket->department);
        $this->assertSame((string) self::ME, (string) $ticket->chat_id);
        $this->assertStringStartsWith('TK-', $ticket->ticket_number);

        $first = $ticket->messages()->first();
        $this->assertNotNull($first, 'the fork stored the opening message as a USER row');
        $this->assertSame('USER', $first->sender_type);
        $this->assertSame('سلام، اینترنت قطع است', $first->message);

        // Confirmation to the customer.
        $this->assertStringContainsString('با شماره پیگیری #'.$ticket->id, $this->lastText());

        // The notification went to the admin chat id with the two buttons.
        $request = $this->requestFor('تیکت جدید دریافت شد');
        $this->assertNotNull($request, 'the administrator was notified of the new ticket');
        $this->assertSame((string) self::ADMIN, (string) $request['chat_id']);
        $this->assertStringContainsString('reply_'.$ticket->id, $this->keyboardOf($request));
        $this->assertStringContainsString('close_'.$ticket->id, $this->keyboardOf($request));

        // And the state was dropped, as legacy did.
        $user = User::query()->where('chat_id', (string) self::ME)->firstOrFail();
        $this->assertNull($this->app->make(UserStateService::class)->get($user));
    }

    public function test_the_list_paginates_five_a_page_and_walks_page_two(): void
    {
        $this->makeUser(self::ME);

        for ($i = 1; $i <= 11; $i++) {
            $this->makeTicket((string) self::ME, 'تیکت '.$i);
        }

        $this->press('ticketslist_1');

        $text = $this->lastText();
        $labels = $this->labels();

        $this->assertStringContainsString('صفحه 1 از 3', $text);
        $this->assertSame(
            5,
            count(array_filter($labels, fn (string $l): bool => str_contains($l, '|myticket_'))),
            'five ticket rows on page one',
        );
        $this->assertContains('بعدی ▶️|ticketslist_2', $labels);
        $this->assertNotContains('◀️ قبلی|ticketslist_1', $labels, 'no previous button on the first page');
        $this->assertContains('🔙 بازگشت|tickets', $labels);

        // Page 2: the third page exists, so «next» is offered, and «previous» returns.
        $this->press('ticketslist_2');

        $this->assertStringContainsString('صفحه 2 از 3', $this->lastText());
        $labels = $this->labels();
        $this->assertContains('◀️ قبلی|ticketslist_1', $labels);
        $this->assertContains('بعدی ▶️|ticketslist_3', $labels);
    }

    public function test_an_empty_list_still_offers_a_new_ticket(): void
    {
        $this->makeUser(self::ME);

        $this->press('ticketslist_1');

        $this->assertStringContainsString('هیچ تیکتی ثبت نکردهاید', $this->lastText());
        $this->assertContains('✍️ ارسال تیکت جدید|tickets_new', $this->labels());
    }

    public function test_the_ticket_view_shows_the_thread_and_the_owner_buttons(): void
    {
        $this->makeUser(self::ME);
        $ticket = $this->makeTicket((string) self::ME);
        $ticket->messages()->create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'USER',
            'sender_id' => (string) self::ME,
            'sender_name' => 'Ali',
            'message' => 'سلام',
            'is_read' => false,
            'created_at' => now(),
        ]);
        $ticket->messages()->create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'ADMIN',
            'sender_id' => (string) self::ADMIN,
            'sender_name' => 'پشتیبانی',
            'message' => 'در جریان است',
            'is_read' => false,
            'created_at' => now(),
        ]);

        $this->press('myticket_'.$ticket->id);

        $text = $this->lastText();

        $this->assertStringContainsString('تیکت '.$ticket->ticket_number, $text);
        $this->assertStringContainsString('وضعیت: 🟢 باز', $text);
        $this->assertStringContainsString('سلام', $text);
        $this->assertStringContainsString('در جریان است', $text);

        $labels = $this->labels();
        $this->assertContains('✍️ ارسال پاسخ|user_'.$ticket->id, $labels);
        $this->assertContains('🔒 بستن تیکت|uclose_'.$ticket->id, $labels);
        $this->assertContains('🔙 بازگشت به لیست|ticketslist_1', $labels);
    }

    public function test_a_ticket_of_another_user_is_not_openable(): void
    {
        $this->makeUser(self::ME);
        $this->makeUser(self::STRANGER, 'Mina');

        $foreign = $this->makeTicket((string) self::STRANGER, 'موضوع محرمانه');

        // Listing only ever shows one's own tickets.
        $this->press('ticketslist_1');
        $this->assertStringNotContainsString('موضوع محرمانه', $this->lastText());

        // Opening it by a crafted id gets the fork's refusal, no thread.
        $this->press('myticket_'.$foreign->id);

        $text = $this->lastText();
        $this->assertStringContainsString('❌ این تیکت یافت نشد.', $text);
        $this->assertStringNotContainsString('موضوع محرمانه', $text);

        // Closing somebody else's ticket is refused the same way.
        $this->press('uclose_'.$foreign->id);

        $this->assertSame('OPEN', $foreign->fresh()->status, 'the foreign ticket stayed open');
        $this->assertStringContainsString('❌ این تیکت یافت نشد.', $this->lastText());
    }

    public function test_the_user_reply_flow_arms_a_state_then_stores_the_message(): void
    {
        $this->makeUser(self::ME);
        $ticket = $this->makeTicket((string) self::ME);

        $this->press('user_'.$ticket->id);

        $user = User::query()->where('chat_id', (string) self::ME)->firstOrFail();
        $state = $this->app->make(UserStateService::class)->get($user) ?? [];

        $this->assertSame('user_reply', $state['action'] ?? null);
        $this->assertSame($ticket->id, $state['ticket_id'] ?? null);
        $this->assertStringContainsString('پاسخ به تیکت '.$ticket->ticket_number, $this->lastText());

        $this->send('پیگیری می‌کنم');

        $this->assertNull(
            $this->app->make(UserStateService::class)->get($user->refresh()),
            'state cleared after the step',
        );

        $message = $ticket->messages()->orderByDesc('id')->first();
        $this->assertSame('USER', $message->sender_type);
        $this->assertSame('پیگیری می‌کنم', $message->message);
        $this->assertSame('OPEN', $ticket->fresh()->status);
        $this->assertStringContainsString('✅ پیام شما برای پشتیبانی ارسال شد.', $this->lastText());

        // The administrators were told about the customer's reply.
        $request = $this->requestFor('پاسخ جدید کاربر در تیکت', (string) self::ADMIN);
        $this->assertNotNull($request, 'the administrator saw the customer reply');
        $this->assertStringContainsString('tkhist_'.$ticket->id, $this->keyboardOf($request));
        $this->assertStringContainsString('reply_'.$ticket->id, $this->keyboardOf($request));
    }

    public function test_the_admin_reply_branch_is_refused_for_a_regular_user(): void
    {
        $this->makeUser(self::ME);
        $ticket = $this->makeTicket((string) self::ME);

        $this->press('reply_'.$ticket->id);

        $user = User::query()->where('chat_id', (string) self::ME)->firstOrFail();

        $this->assertStringContainsString('⛔ دسترسی غیرمجاز.', $this->lastText());
        $this->assertNull(
            $this->app->make(UserStateService::class)->get($user),
            'a non-admin never enters the admin reply step',
        );

        // tkhist and close are refused too.
        $this->press('tkhist_'.$ticket->id);
        $this->assertStringContainsString('⛔ دسترسی غیرمجاز.', $this->lastText());

        $this->press('close_'.$ticket->id);
        $this->assertSame('OPEN', $ticket->fresh()->status, 'a non-admin cannot close');
    }

    public function test_an_admin_reply_lands_on_the_thread_and_reaches_the_owner(): void
    {
        $this->makeUser(self::ADMIN, 'Admin');
        $this->makeUser(self::ME);
        $ticket = $this->makeTicket((string) self::ME);

        // An admin arms the step from the notification.
        $this->press('reply_'.$ticket->id, self::ADMIN);

        $admin = User::query()->where('chat_id', (string) self::ADMIN)->firstOrFail();
        $state = $this->app->make(UserStateService::class)->get($admin) ?? [];

        $this->assertSame('admin_reply', $state['action'] ?? null);
        $this->assertStringContainsString('سابقه گفتگو', $this->lastText());

        $this->send('پاسخ پشتیبانی', self::ADMIN);

        $message = $ticket->messages()->orderByDesc('id')->first();
        $this->assertSame('ADMIN', $message->sender_type);
        $this->assertSame('پاسخ پشتیبانی', $message->message);
        $this->assertSame('ANSWERED', $ticket->fresh()->status);

        // The owner got the notification with reply/close buttons.
        $request = $this->requestFor('پاسخ جدید به تیکت', (string) self::ME);
        $this->assertNotNull($request, 'the ticket owner saw the staff reply');
        $this->assertStringContainsString('user_'.$ticket->id, $this->keyboardOf($request));
        $this->assertStringContainsString('uclose_'.$ticket->id, $this->keyboardOf($request));
    }

    public function test_a_non_admin_forced_into_the_admin_step_is_still_refused(): void
    {
        $this->makeUser(self::ME);
        $ticket = $this->makeTicket((string) self::ME);

        // Crafted state: a customer who wrote the action blob themselves.
        $user = User::query()->where('chat_id', (string) self::ME)->firstOrFail();
        $this->app->make(UserStateService::class)->set($user, [
            'action' => 'admin_reply',
            'ticket_id' => $ticket->id,
        ]);

        $this->send('تلاش برای پاسخ دادن');

        $this->assertStringContainsString('⛔ دسترسی غیرمجاز.', $this->lastText());
        $this->assertSame('OPEN', $ticket->fresh()->status, 'no message was written');

        // The step was dropped either way, as legacy cleared before acting.
        $this->assertNull($this->app->make(UserStateService::class)->get($user->fresh()));
    }

    public function test_the_customer_closes_the_ticket_and_the_admin_is_told(): void
    {
        $this->makeUser(self::ME);
        $ticket = $this->makeTicket((string) self::ME);

        $this->press('uclose_'.$ticket->id);

        $this->assertSame('CLOSED', $ticket->fresh()->status);
        $this->assertStringContainsString('🔒 تیکت شما بسته شد.', $this->lastText());
        $this->assertStringContainsString(
            'ticketslist_1',
            $this->keyboardOf($this->requestFor('بسته شد', (string) self::ME)),
            'the confirmation carries the way back to the list',
        );

        // The closure notification reached the administrator.
        $sent = null;
        foreach (Http::recorded() as [$request]) {
            if (str_contains($request->url(), 'sendMessage') && str_contains((string) $request['text'], 'توسط کاربر بسته شد')) {
                $sent = $request;
            }
        }
        $this->assertNotNull($sent, 'the admin was told about the user closing the ticket');
        $this->assertSame((string) self::ADMIN, (string) $sent['chat_id']);

        // A second tap says it was already closed.
        $this->press('uclose_'.$ticket->id);
        $this->assertStringContainsString('قبلاً بسته شده است', $this->lastText());
    }

    public function test_the_star_buttons_record_the_rating_on_the_thread(): void
    {
        $this->makeUser(self::ME);
        $ticket = $this->makeTicket((string) self::ME);
        $ticket->messages()->create([
            'ticket_id' => $ticket->id,
            'sender_type' => 'USER',
            'sender_id' => (string) self::ME,
            'sender_name' => 'Ali',
            'message' => 'سلام',
            'is_read' => false,
            'created_at' => now(),
        ]);

        $this->press('ticketrate_'.$ticket->id.'_2');

        $message = $ticket->messages()->firstOrFail();

        $this->assertStringStartsWith('⭐⭐ (امتیاز کاربر)', (string) $message->message);
        $this->assertStringContainsString('سلام', (string) $message->message);
        $this->assertTrue($message->is_read, 'the rating note flags the thread as read');
        $this->assertStringContainsString('ممنون از بازخورد شما! ⭐⭐', $this->lastText());

        // Rating twice is refused, as the fork did.
        $this->press('ticketrate_'.$ticket->id.'_1');
        $this->assertStringContainsString('قبلاً به این تیکت امتیاز دادهاید', $this->lastText());
    }

    /** The inline keyboard of one recorded request, as a lookup string. */
    private function keyboardOf($request): string
    {
        if ($request === null) {
            return '';
        }

        $markup = $request['reply_markup'] ?? '';

        return is_string($markup) ? $markup : json_encode($markup, JSON_UNESCAPED_UNICODE);
    }
}
