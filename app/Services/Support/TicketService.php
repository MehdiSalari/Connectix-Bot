<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketMessage;
use App\Models\User;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\AdminGuard;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Support\JalaliDate;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Support tickets: creation, replies, status and counts.
 *
 * Port of the feature fork's services/TicketService.php. The fork's
 * behaviour is kept where the schema allows it:
 *
 *  - a ticket opens with a human-facing number and the customer's first
 *    message stored as the opening USER row of the thread;
 *  - an admin reply marks the ticket ANSWERED, a user reply re-opens it —
 *    the fork flipped between OPEN and PENDING for the same handshake
 *    (its panel label «در انتظار پاسخ کاربر» renamed the user's turn);
 *  - a closed ticket refuses USER messages (an admin may still answer,
 *    which re-opens it), the same guard the fork's addMessage had;
 *  - every message write also touches `tickets.updated_at`, the column the
 *    fork's lists ordered by.
 *
 * What the rewrite deliberately drops: the Telegram side of the service —
 * the sendMessage notifications, the inline keyboards and the star-rating
 * request (the fork's `rating` column does not exist in this schema). The
 * panel thread is the only reader here; the webhook is the bot's voice.
 */
class TicketService
{
    /** The statuses the fork cycled through. */
    public const STATUSES = ['OPEN', 'ANSWERED', 'CLOSED'];

    /** Conversation states the bot-side ticket flows arm (bot.php action keys). */
    public const ACTION_TICKET_NEW = 'ticket_new';

    public const ACTION_USER_REPLY = 'user_reply';

    public const ACTION_ADMIN_REPLY = 'admin_reply';

    /** Tickets shown per page in «تیکتهای من» (fork's $perPage). */
    public const PER_PAGE = 5;

    /** Stars offered on the rating keyboard (fork's MAX_RATING). */
    public const MAX_RATING = 3;

    /**
     * Memoised open-ticket count for the current instance/request.
     */
    private ?int $openCountMemo = null;

    public function __construct(
        private ?TelegramService $telegram = null,
        private ?UserStateService $state = null,
    ) {}

    /** Lazily resolved so `new TicketService` (the panel path) stays valid. */
    private function states(): UserStateService
    {
        return $this->state ??= app(UserStateService::class);
    }

    /** @see self::states() */
    private function tg(): TelegramService
    {
        return $this->telegram ??= app(TelegramService::class);
    }

    /**
     * Open a ticket for a Telegram chat and store its first message.
     *
     * `ticket_number` is the human-facing id shown in the conversation:
     * `TK-` plus a zero-padded six digit sequence, unique per table — the
     * fork generated `TCK-` + uniqid and the rewrite pins the format.
     */
    public function create(
        string $chatId,
        string $subject,
        string $department = 'پشتیبانی عمومی',
        string $priority = 'MEDIUM',
        ?int $userId = null,
        ?string $firstMessage = null,
        ?string $senderName = null,
    ): Ticket {
        return DB::transaction(function () use ($chatId, $subject, $department, $priority, $userId, $firstMessage, $senderName): Ticket {
            $ticket = Ticket::query()->create([
                'ticket_number' => $this->nextTicketNumber(),
                'user_id' => $userId,
                'chat_id' => $chatId,
                'subject' => $subject,
                'department' => $department,
                'status' => 'OPEN',
                'priority' => $priority,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if ($firstMessage !== null && $firstMessage !== '') {
                $this->storeMessage($ticket, 'USER', $chatId, $senderName, $firstMessage, false);
            }

            return $ticket;
        });
    }

    /**
     * Append one message to a ticket and move its status forward.
     *
     * An ADMIN reply re-opens the ticket as ANSWERED and touches the parent
     * row's `updated_at`; a USER reply flips it back to OPEN, but only while
     * the ticket is not CLOSED — a closed thread refuses customer messages,
     * the guard the fork's addMessage enforced.
     *
     * @param  'ADMIN'|'USER'  $senderType
     */
    public function reply(
        Ticket $ticket,
        string $senderType,
        string $senderName,
        string $message,
        ?string $attachment = null,
    ): TicketMessage {
        if ($senderType === 'USER' && ! $ticket->isOpen() && $ticket->status !== 'ANSWERED') {
            throw new RuntimeException('تیکت بسته شده و امکان ارسال پیام وجود ندارد.');
        }

        return DB::transaction(function () use ($ticket, $senderType, $senderName, $message, $attachment): TicketMessage {
            $stored = $this->storeMessage($ticket, $senderType, null, $senderName, $message, true, $attachment);

            $ticket->forceFill([
                'status' => $senderType === 'ADMIN' ? 'ANSWERED' : 'OPEN',
                'updated_at' => now(),
            ])->save();

            return $stored;
        });
    }

    /**
     * Move a ticket to another status and stamp `updated_at` with it.
     *
     * @param  'OPEN'|'ANSWERED'|'CLOSED'  $status
     */
    public function setStatus(Ticket $ticket, string $status): Ticket
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new \InvalidArgumentException("وضعیت تیکت نامعتبر است: {$status}");
        }

        $ticket->forceFill([
            'status' => $status,
            'updated_at' => now(),
        ])->save();

        return $ticket;
    }

    /**
     * Close a ticket. Closing an already-closed ticket is a no-op (the
     * double-submit the fork's panel allowed stays harmless) and writes no
     * second trail row — the controller logs only a real transition.
     */
    public function closeTicket(Ticket $ticket): bool
    {
        if ($ticket->status === 'CLOSED') {
            return false;
        }

        $this->setStatus($ticket, 'CLOSED');

        return true;
    }

    /**
     * Count the open tickets. Memoised on the instance (one instance lives
     * for one request): the sidebar badge hits this on every panel page, so
     * a page render pays for one COUNT at most.
     */
    public function openCount(): int
    {
        if ($this->openCountMemo !== null) {
            return $this->openCountMemo;
        }

        try {
            $this->openCountMemo = (int) Ticket::query()->where('status', 'OPEN')->count();
        } catch (Throwable) {
            $this->openCountMemo = 0;
        }

        return $this->openCountMemo;
    }

    /**
     * One user's tickets, newest activity first — the bot's «تیکت‌های من».
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Ticket>
     */
    public function forUser(string $chatId): \Illuminate\Database\Eloquent\Collection
    {
        return Ticket::query()
            ->where('chat_id', $chatId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * One ticket with its whole thread, oldest message first — the panel's
     * conversation view and the bot's history both read this.
     */
    public function thread(Ticket $ticket): Ticket
    {
        $ticket->setRelation(
            'messages',
            $ticket->messages()->orderBy('created_at')->orderBy('id')->get(),
        );

        return $ticket;
    }

    /**
     * Every ticket with its newest message attached, for list pages.
     *
     * @param  string  $status  one of the STATUSES or 'ALL'
     * @return Collection<int, array{ticket: Ticket, last: TicketMessage|null}>
     */
    public function history(string $status = 'ALL'): Collection
    {
        $query = Ticket::query()->orderByDesc('updated_at')->orderByDesc('id');

        if ($status !== 'ALL' && in_array($status, self::STATUSES, true)) {
            $query->where('status', $status);
        }

        return $query->get()->map(fn (Ticket $ticket): array => [
            'ticket' => $ticket,
            'last' => $ticket->messages()->orderByDesc('created_at')->orderByDesc('id')->first(),
        ]);
    }

    /**
     * The next unique zero-padded ticket number: `TK-000001` style.
     *
     * Started past the fork's own first sequence so the two id spaces do not
     * collide on an imported database, and retried inside the transaction on
     * the (theoretical) unique-key collision.
     */
    private function nextTicketNumber(): string
    {
        $base = (int) Ticket::query()->max('id');

        do {
            $candidate = 'TK-'.str_pad((string) ($base + 1), 6, '0', STR_PAD_LEFT);
            $taken = Ticket::query()->where('ticket_number', $candidate)->exists();
            $base++;
        } while ($taken);

        return $candidate;
    }

    /**
     * Insert one thread row. The ticket's `updated_at` only moves for a
     * reply or a status change — the opening message is written while the
     * create already stamped it.
     */
    private function storeMessage(
        Ticket $ticket,
        string $senderType,
        ?string $senderId,
        ?string $senderName,
        string $message,
        bool $touch = true,
        ?string $attachment = null,
    ): TicketMessage {
        $message = TicketMessage::query()->create([
            'ticket_id' => $ticket->id,
            'sender_type' => $senderType,
            'sender_id' => $senderId,
            'sender_name' => $senderName,
            'message' => $message,
            'attachment' => $attachment,
            'is_read' => false,
            'created_at' => now(),
        ]);

        if ($touch) {
            $ticket->forceFill(['updated_at' => now()])->save();
        }

        return $message;
    }

    // ==================================================================
    // Bot side
    //
    // Port of the fork's bot branches: `tickets`, `tickets_new`, the
    // ticketslist/myticket/user/uclose callbacks (callBackCheck) and the
    // ticket_new / user_reply / admin_reply text steps of bot.php. The
    // rewrite's texts keep the fork's wording; what changed is spelled out
    // where the fork's schema (rating, TCK- numbers, a single admin id)
    // does not exist here.
    // ==================================================================

    /**
     * The support landing page, port of the `tickets` branch of bot.php:
     * it clears any half-finished flow (legacy actionStep('clear')) and
     * offers a new ticket, the ticket list and the main menu.
     *
     * @return array{text: string, reply_markup: string}
     */
    public function menu(User $user): array
    {
        $this->states()->clear($user);

        $text = "🎫 <b>سیستم پشتیبانی تیکتینگ</b>\n\n"
            .'چنانچه سوال، مشکل فنی یا پیشنهادی دارید، میتوانید تیکت ارسال کنید تا کارشناسان ما در سریعترین زمان بررسی نمایند.';

        return [
            'text' => $text,
            'reply_markup' => $this->keyboard([
                [['text' => '✍️ ارسال تیکت جدید', 'callback_data' => 'tickets_new']],
                [['text' => '📋 تیکتهای من', 'callback_data' => 'ticketslist_1']],
                [['text' => '🔙 بازگشت به منوی اصلی', 'callback_data' => 'main_menu']],
            ]),
        ];
    }

    /**
     * Arm the new-ticket text step, port of the `tickets_new` branch.
     *
     * @return array{text: string, reply_markup: string}
     */
    public function startNewTicket(User $user): array
    {
        $this->states()->set($user, ['action' => self::ACTION_TICKET_NEW, 'step' => null]);

        return [
            'text' => "✍️ <b>ارسال پیام به پشتیبانی</b>\n\nلطفاً متن پیام یا سوال خود را ارسال نمایید:",
            'reply_markup' => $this->keyboard([
                [['text' => '❌ انصراف', 'callback_data' => 'main_menu']],
            ]),
        ];
    }

    /**
     * Turn the text collected in the `ticket_new` step into a ticket and
     * notify every administrator chat id (port of bot.php:315).
     *
     * The fork passed 'NORMAL' where its createTicket() expected the
     * department, so bot tickets landed in the priority column — the
     * rewrite writes the department the schema expects. The tracking number
     * in the confirmation is the ticket's id, as the fork showed it.
     *
     * @return array{text: string, reply_markup: string}
     */
    public function submitTicket(User $user, string $text): array
    {
        $this->states()->clear($user);

        $ticket = $this->create(
            (string) $user->chat_id,
            'پیام از ربات تلگرام',
            'پشتیبانی عمومی',
            'MEDIUM',
            $user->id,
            $text !== '' ? $text : 'بدون متن',
            $this->senderName($user),
        );

        $this->notifyAdminsNewTicket($ticket, $text);

        $safeTid = self::e((string) $ticket->id);

        return [
            'text' => "✅ تیکت شما با شماره پیگیری #{$safeTid} با موفقیت ثبت شد.\nبه زودی پاسخ پشتیبان برای شما ارسال خواهد شد.",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '🏠 | خانه', 'callback_data' => 'main_menu']],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    /**
     * «تیکتهای من», port of the fork's listForUser(): five tickets a page,
     * each row opening `myticket_{id}`, with prev/next navigation and the
     * way back to the menu.
     *
     * @param  int|string|null  $page  the raw `ticketslist_{page}` suffix
     * @return array{text: string, reply_markup: string}
     */
    public function listForUser(string $chatId, int|string|null $page = null): array
    {
        $tickets = $this->forUser($chatId);

        if ($tickets->isEmpty()) {
            return [
                'text' => "📋 <b>تیکتهای من</b>\n\nشما تاکنون هیچ تیکتی ثبت نکردهاید.",
                'reply_markup' => $this->keyboard([
                    [['text' => '✍️ ارسال تیکت جدید', 'callback_data' => 'tickets_new']],
                    [['text' => '🔙 بازگشت', 'callback_data' => 'tickets']],
                ]),
            ];
        }

        $totalPages = max(1, (int) ceil($tickets->count() / self::PER_PAGE));
        $current = max(1, min(((int) $page) ?: 1, $totalPages));

        $rows = $tickets
            ->slice(($current - 1) * self::PER_PAGE, self::PER_PAGE)
            ->map(fn (Ticket $ticket): array => [[
                'text' => $this->statusLabel($ticket->status).' | '.$ticket->ticket_number.' — '
                    .mb_substr($ticket->subject, 0, 25),
                'callback_data' => 'myticket_'.$ticket->id,
            ]])
            ->values()
            ->all();

        $nav = [];

        if ($current > 1) {
            $nav[] = ['text' => '◀️ قبلی', 'callback_data' => 'ticketslist_'.($current - 1)];
        }

        if ($current < $totalPages) {
            $nav[] = ['text' => 'بعدی ▶️', 'callback_data' => 'ticketslist_'.($current + 1)];
        }

        if ($nav !== []) {
            $rows[] = $nav;
        }

        $rows[] = [['text' => '🔙 بازگشت', 'callback_data' => 'tickets']];

        return [
            'text' => "📋 <b>تیکتهای من</b> (صفحه {$current} از {$totalPages})\n\nروی هر تیکت بزنید تا گفتگو و وضعیت آن را ببینید:",
            'reply_markup' => $this->keyboard($rows),
        ];
    }

    /**
     * One ticket's whole thread, port of the fork's viewForUser().
     *
     * Ownership is enforced here as in the fork: a ticket that is not the
     * sender's own reads as «not found», so an id from somebody else's list
     * never leaks its content (anti-IDOR).
     *
     * @return array{text: string, reply_markup: string}
     */
    public function viewForUser(string $chatId, int|string $ticketId): array
    {
        $ticket = Ticket::query()->find((int) $ticketId);

        if ($ticket === null || (string) $ticket->chat_id !== $chatId) {
            return [
                'text' => '❌ این تیکت یافت نشد.',
                'reply_markup' => $this->keyboard([
                    [['text' => '🔙 بازگشت', 'callback_data' => 'ticketslist_1']],
                ]),
            ];
        }

        $ticket = $this->thread($ticket);

        $text = '🎫 <b>تیکت '.self::e($ticket->ticket_number)."</b>\n"
            .'📌 موضوع: '.self::e($ticket->subject)."\n"
            .'وضعیت: '.$this->statusLabel($ticket->status)."\n\n"
            ."💬 <b>گفتگو:</b>\n\n".$this->conversation($ticket, false);

        $rows = [];

        if ($ticket->status !== 'CLOSED') {
            $rows[] = [['text' => '✍️ ارسال پاسخ', 'callback_data' => 'user_'.$ticket->id]];
            $rows[] = [['text' => '🔒 بستن تیکت', 'callback_data' => 'uclose_'.$ticket->id]];
        }

        $rows[] = [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'ticketslist_1']];

        return ['text' => $text, 'reply_markup' => $this->keyboard($rows)];
    }

    /**
     * Arm the customer's reply step, port of the fork's startUserReply()
     * (`user_{id}` — the same handler for the button inside the ticket view
     * and the one riding on the admin's reply notification).
     *
     * @return array{text: string, reply_markup: string}
     */
    public function startUserReply(User $user, int|string $ticketId): array
    {
        $ticket = Ticket::query()->find((int) $ticketId);

        if ($ticket === null || (string) $ticket->chat_id !== (string) $user->chat_id) {
            return ['text' => '❌ این تیکت یافت نشد.', 'reply_markup' => null];
        }

        if ($ticket->status === 'CLOSED') {
            return ['text' => '🔒 این تیکت بسته شده و امکان ارسال پاسخ وجود ندارد. برای پیگیری، تیکت جدید ثبت کنید.', 'reply_markup' => null];
        }

        $this->states()->set($user, [
            'action' => self::ACTION_USER_REPLY,
            'step' => null,
            'ticket_id' => (int) $ticket->id,
        ]);

        return [
            'text' => '✍️ <b>پاسخ به تیکت '.self::e($ticket->ticket_number)."</b>\n\nلطفاً متن پیام خود را ارسال کنید:",
            'reply_markup' => $this->keyboard([
                [['text' => '❌ انصراف', 'callback_data' => 'tickets']],
            ]),
        ];
    }

    /**
     * Store the text collected in the `user_reply` step and tell the
     * customer it landed (port of bot.php:343).
     *
     * @return array{text: string, reply_markup: string|null}
     */
    public function appendUserMessage(User $user, string $text): array
    {
        // Read the armed step before dropping it — the ticket id lives there.
        $ticket = Ticket::query()->find((int) $this->stateValue($user, 'ticket_id'));

        $this->states()->clear($user);

        $sent = false;

        if ($ticket !== null && $text !== '') {
            try {
                $this->reply($ticket, 'USER', $this->senderName($user), $text);
                $sent = true;
            } catch (Throwable) {
                $sent = false;
            }
        }

        if ($sent && $ticket !== null) {
            $this->notifyAdminsUserReply($ticket, $text, $this->senderName($user));

            return [
                'text' => '✅ پیام شما برای پشتیبانی ارسال شد.',
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '🏠 | خانه', 'callback_data' => 'main_menu']],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ];
        }

        return ['text' => '❌ ارسال پیام با خطا مواجه شد. تیکت یافت نشد یا بسته شده است.', 'reply_markup' => null];
    }

    /**
     * Close a ticket from the customer's side (`uclose_{id}`), port of the
     * fork's closeByUser(). Closing tells the administrators, exactly as
     * legacy did with its single $adminID.
     *
     * @return array{text: string, reply_markup: string}
     */
    public function closeByUser(User $user, int|string $ticketId): array
    {
        $back = $this->keyboard([
            [['text' => '🔙 بازگشت به لیست', 'callback_data' => 'ticketslist_1']],
        ]);

        $ticket = Ticket::query()->find((int) $ticketId);

        if ($ticket === null || (string) $ticket->chat_id !== (string) $user->chat_id) {
            return ['text' => '❌ این تیکت یافت نشد.', 'reply_markup' => $back];
        }

        if ($ticket->status === 'CLOSED') {
            return ['text' => '🔒 این تیکت قبلاً بسته شده است.', 'reply_markup' => $back];
        }

        $this->states()->clear($user);
        $ok = $this->closeTicket($ticket);

        if ($ok) {
            foreach ($this->adminIds() as $adminId) {
                $this->quietSend(
                    $adminId,
                    '🔒 تیکت '.self::e($ticket->ticket_number).' توسط کاربر بسته شد.'
                    ."\nموضوع: ".self::e($ticket->subject),
                );
            }
        }

        return [
            'text' => $ok ? '🔒 تیکت شما بسته شد. ممنون از شما 🙏' : '❌ بستن تیکت با خطا مواجه شد.',
            'reply_markup' => $back,
        ];
    }

    /**
     * Arm the administrator's reply step, port of the fork's
     * startAdminReply() (`reply_{id}`, pressed on the new-ticket
     * notification). Only administrators may enter it; everybody else gets
     * the fork's refusal.
     *
     * @return array{text: string, reply_markup: string|null}
     */
    public function startAdminReply(User $user, int|string $ticketId, bool $isAdmin): array
    {
        if (! $isAdmin) {
            return ['text' => '⛔ دسترسی غیرمجاز.', 'reply_markup' => null];
        }

        $found = Ticket::query()->find((int) $ticketId);

        if ($found === null) {
            return ['text' => '❌ این تیکت یافت نشد یا حذف شده است.', 'reply_markup' => null];
        }

        $ticket = $this->thread($found);

        $this->states()->set($user, [
            'action' => self::ACTION_ADMIN_REPLY,
            'step' => null,
            'ticket_id' => (int) $ticket->id,
        ]);

        $text = '✍️ <b>پاسخ به تیکت '.self::e($ticket->ticket_number)."</b>\n"
            .'📌 موضوع: '.self::e($ticket->subject)."\n"
            .'وضعیت: '.$this->statusLabel($ticket->status)."\n\n"
            ."💬 <b>سابقه گفتگو:</b>\n\n".$this->conversation($ticket, true, 2700)
            ."\n\n✏️ لطفاً متن پاسخ خود را ارسال کنید (بهصورت پیام متنی):";

        return [
            'text' => $text,
            'reply_markup' => $this->keyboard([
                [['text' => '🔒 بستن تیکت', 'callback_data' => 'close_'.$ticket->id]],
            ]),
        ];
    }

    /**
     * Store the text collected in the `admin_reply` step and hand it to the
     * customer (port of bot.php:332). An administrator who slipped into the
     * step without the guard is refused here too — the state alone never
     * makes a user staff.
     *
     * @return array{text: string, reply_markup: string|null}
     */
    public function addAdminMessage(User $user, string $text, bool $isAdmin): array
    {
        // Read the armed step before dropping it — the ticket id lives there.
        $ticket = Ticket::query()->find((int) $this->stateValue($user, 'ticket_id'));

        $this->states()->clear($user);

        if (! $isAdmin) {
            return ['text' => '⛔ دسترسی غیرمجاز.', 'reply_markup' => null];
        }

        $sent = false;

        if ($ticket !== null && $text !== '') {
            try {
                $this->reply($ticket, 'ADMIN', $this->senderName($user, 'پشتیبانی'), $text);
                $sent = true;
            } catch (Throwable) {
                $sent = false;
            }
        }

        if ($sent && $ticket !== null) {
            $this->notifyOwnerAdminReply($ticket, $text);

            foreach ($this->adminIds() as $adminId) {
                $this->quietSend(
                    $adminId,
                    '✅ پاسخ شما برای کاربر ارسال شد.',
                );
            }

            return ['text' => '✅ پاسخ شما برای کاربر ارسال شد.', 'reply_markup' => null];
        }

        return ['text' => '❌ ارسال پاسخ با خطا مواجه شد. تیکت یافت نشد یا حذف شده است.', 'reply_markup' => null];
    }

    /**
     * Close a ticket from the administrator side (`close_{id}`), port of
     * the fork's closeTicket().
     *
     * @return array{text: string, reply_markup: string|null}
     */
    public function adminCloseTicket(User $user, int|string $ticketId, bool $isAdmin): array
    {
        if (! $isAdmin) {
            return ['text' => '⛔ دسترسی غیرمجاز.', 'reply_markup' => null];
        }

        $ticket = Ticket::query()->find((int) $ticketId);

        if ($ticket === null) {
            return ['text' => '❌ این تیکت یافت نشد یا حذف شده است.', 'reply_markup' => null];
        }

        $this->states()->clear($user);
        $ok = $this->closeTicket($ticket);

        return [
            'text' => $ok ? '🔒 تیکت با موفقیت بسته شد.' : '❌ بستن تیکت با خطا مواجه شد.',
            'reply_markup' => $this->keyboard([
                [['text' => '🔙 بازگشت', 'callback_data' => 'not']],
            ]),
        ];
    }

    /**
     * The administrator's read-only thread view (`tkhist_{id}`), port of
     * the fork's historyForAdmin(): no reply step is armed, the buttons jump
     * into one or close the ticket.
     *
     * @return array{text: string, reply_markup: string|null}
     */
    public function historyForAdmin(User $user, int|string $ticketId, bool $isAdmin): array
    {
        if (! $isAdmin) {
            return ['text' => '⛔ دسترسی غیرمجاز.', 'reply_markup' => null];
        }

        $found = Ticket::query()->find((int) $ticketId);

        if ($found === null) {
            return ['text' => '❌ این تیکت یافت نشد یا حذف شده است.', 'reply_markup' => null];
        }

        $ticket = $this->thread($found);

        $text = '🎫 <b>تیکت '.self::e($ticket->ticket_number)."</b>\n"
            .'👤 کاربر: <code>'.self::e((string) $ticket->chat_id)."</code>\n"
            .'📌 موضوع: '.self::e($ticket->subject)."\n"
            .'وضعیت: '.$this->statusLabel($ticket->status)."\n\n"
            ."💬 <b>سابقه گفتگو:</b>\n\n".$this->conversation($ticket, true, 3000);

        $rows = [[['text' => '✍️ پاسخ به تیکت', 'callback_data' => 'reply_'.$ticket->id]]];

        if ($ticket->status !== 'CLOSED') {
            $rows[] = [['text' => '🔒 بستن تیکت', 'callback_data' => 'close_'.$ticket->id]];
        }

        return ['text' => $text, 'reply_markup' => $this->keyboard($rows)];
    }

    /**
     * Record a star rating, port of the fork's rateTicket()
     * (`ticketrate_{id}_{star}`). The fork sent its star buttons to the
     * customer, so the ticket's owner may rate without being staff; anyone
     * else gets the fork's «not found» instead of touching somebody else's
     * thread.
     *
     * The fork wrote the number into `tickets.rating`; this schema has no
     * such column, so the rating is recorded the way the prompt described —
     * on the thread's messages, as a note that flags itself `is_read` — and
     * the admin warning for a one-star rating the fork sent still fires.
     *
     * @return array{text: string, reply_markup: string|null}
     */
    public function rateTicket(User $user, string $query): array
    {
        $parts = explode('_', (string) $query);

        if (count($parts) < 2) {
            return ['text' => '❌ شناسه تیکت نامعتبر است.', 'reply_markup' => null];
        }

        $ticketId = (int) $parts[0];
        $rating = (int) ($parts[1] ?? 0);

        if ($rating < 1) {
            return ['text' => '❌ شناسه تیکت نامعتبر است.', 'reply_markup' => null];
        }

        // Old 5-star buttons may still be tapped; fold them onto the scale.
        $rating = min($rating, self::MAX_RATING);

        $ticket = Ticket::query()->find($ticketId);

        if ($ticket === null
            || ((string) $ticket->chat_id !== (string) $user->chat_id
                && ! $this->isAdmin($user))) {
            return ['text' => '❌ این تیکت یافت نشد یا حذف شده است.', 'reply_markup' => null];
        }

        $ticket = $this->thread($ticket);

        $already = $ticket->messages
            ->contains(fn (TicketMessage $message): bool => str_starts_with((string) $message->message, '⭐'));

        if ($already) {
            return ['text' => 'شما قبلاً به این تیکت امتیاز دادهاید. ممنون از نظرتون 🙏', 'reply_markup' => null];
        }

        $note = str_repeat('⭐', $rating).' (امتیاز کاربر)';

        foreach ($ticket->messages as $message) {
            $message->forceFill(['message' => $note."\n\n".$message->message, 'is_read' => true])->save();
        }

        if ($rating <= 1) {
            foreach ($this->adminIds() as $adminId) {
                $this->quietSend(
                    $adminId,
                    '⚠️ امتیاز پایین دریافت شد!\n\nتیکت '.self::e($ticket->ticket_number)
                    .' امتیاز '.$rating.' از '.self::MAX_RATING." گرفت.\nموضوع: ".self::e($ticket->subject),
                );
            }
        }

        return ['text' => 'ممنون از بازخورد شما! '.str_repeat('⭐', $rating), 'reply_markup' => null];
    }

    /** The label the fork's lists put in front of each status. */
    public function statusLabel(string $status): string
    {
        return match ($status) {
            'OPEN' => '🟢 باز',
            'ANSWERED' => '🟡 پاسخ داده شده',
            'CLOSED' => '🔴 بسته شده',
            default => self::e($status),
        };
    }

    // ------------------------------------------------------------------
    // Bot-side internals
    // ------------------------------------------------------------------

    /**
     * The conversation, trimmed to a Telegram-sized budget by dropping the
     * oldest messages — the fork's renderConversation().
     */
    private function conversation(Ticket $ticket, bool $forAdmin, int $budget = 3000): string
    {
        $blocks = [];

        foreach ($ticket->messages as $message) {
            $who = $message->sender_type === 'ADMIN'
                ? '👨💼 پشتیبانی'
                : ($forAdmin ? '👤 '.self::e((string) $message->sender_name) : '👤 شما');

            $time = '';

            if ($message->created_at !== null) {
                $time = ' <i>('.$this->timeText($message->created_at).')</i>';
            }

            $body = mb_substr((string) $message->message, 0, 700);

            if (mb_strlen((string) $message->message) > 700) {
                $body .= '...';
            }

            $blocks[] = "<b>{$who}</b>{$time}\n".self::e($body);
        }

        $kept = [];
        $used = 0;

        for ($i = count($blocks) - 1; $i >= 0; $i--) {
            $length = mb_strlen($blocks[$i]) + 2;

            if ($used + $length > $budget && $kept !== []) {
                break;
            }

            array_unshift($kept, $blocks[$i]);
            $used += $length;
        }

        $out = '';

        if (count($kept) < count($blocks)) {
            $out .= '<i>... '.(count($blocks) - count($kept))." پیام قدیمیتر نمایش داده نشد</i>\n\n";
        }

        return $out.implode("\n\n", $kept);
    }

    /** Tell every administrator chat id about a new ticket (fork's notification). */
    private function notifyAdminsNewTicket(Ticket $ticket, string $message): void
    {
        $text = "🎫 <b>تیکت جدید دریافت شد!</b>\n\n"
            .'🔢 شماره تیکت: <code>'.self::e($ticket->ticket_number)."</code>\n"
            .'👤 کاربر: <b>'.self::e($this->senderNameOf($ticket)).'</b> (<code>'.self::e((string) $ticket->chat_id)."</code>)\n"
            .'📁 بخش: '.self::e((string) $ticket->department)."\n"
            .'📌 موضوع: <b>'.self::e($ticket->subject)."</b>\n"
            .'💬 متن پیام:'.self::e($message)."\n\n"
            .'📅 '.$this->timeText(now());

        foreach ($this->adminIds() as $adminId) {
            $this->quietSend($adminId, $text, [
                'reply_markup' => $this->keyboard([
                    [['text' => '✍️ پاسخ به تیکت', 'callback_data' => 'reply_'.$ticket->id]],
                    [['text' => '🔒 بستن تیکت', 'callback_data' => 'close_'.$ticket->id]],
                ]),
            ]);
        }
    }

    /** Whether a chat id belongs to the administrators. */
    public function isAdmin(User $user): bool
    {
        try {
            return AdminGuard::fromSettings(
                app(PanelSettingsService::class)
            )->isAdmin($user->chat_id);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Tell the administrators a customer replied (fork's addMessage admin
     * branch): the thread, the message, and the three action buttons.
     */
    private function notifyAdminsUserReply(Ticket $ticket, string $message, string $senderName): void
    {
        $text = '📩 <b>پاسخ جدید کاربر در تیکت '.self::e($ticket->ticket_number)."</b>\n\n"
            .'👤 کاربر: <b>'.self::e($senderName)."</b>\n"
            .'📌 موضوع: '.self::e($ticket->subject)."\n"
            .'💬 پیام کاربر:'.self::e($message);

        foreach ($this->adminIds() as $adminId) {
            $this->quietSend($adminId, $text, [
                'reply_markup' => $this->keyboard([
                    [['text' => '✍️ پاسخ', 'callback_data' => 'reply_'.$ticket->id]],
                    [
                        ['text' => '📜 سابقه گفتگو', 'callback_data' => 'tkhist_'.$ticket->id],
                        ['text' => '🔒 بستن تیکت', 'callback_data' => 'close_'.$ticket->id],
                    ],
                ]),
            ]);
        }
    }

    /**
     * Tell the customer the staff answered (fork's addMessage user branch):
     * the reply and close buttons ride on the notification.
     */
    private function notifyOwnerAdminReply(Ticket $ticket, string $message): void
    {
        $text = '🔔 <b>پاسخ جدید به تیکت '.self::e($ticket->ticket_number)."</b>\n\n"
            .'📌 موضوع: '.self::e($ticket->subject)."\n"
            .'👨💼 پاسخ پشتیبان:'.self::e($message)."\n\n"
            .'برای پاسخ مجدد روی دکمه زیر کلیک کنید 👇';

        $this->quietSend((string) $ticket->chat_id, $text, [
            'reply_markup' => $this->keyboard([
                [['text' => '✍️ ارسال پاسخ به این تیکت', 'callback_data' => 'user_'.$ticket->id]],
                [['text' => '🔒 بستن تیکت', 'callback_data' => 'uclose_'.$ticket->id]],
            ]),
        ]);
    }

    /** The administrator chat ids: config first, then the seller panel. */
    private function adminIds(): array
    {
        $ids = array_values(array_filter(array_map(strval(...), (array) config('connectix_bot.admin_ids', []))));

        if ($ids !== []) {
            return $ids;
        }

        try {
            return app(PanelSettingsService::class)->adminIds();
        } catch (Throwable) {
            return [];
        }
    }

    /** The name the fork put on a message row. */
    private function senderName(User $user, string $fallback = ''): string
    {
        $name = (string) ($user->name ?? '');

        if ($name !== '') {
            return $name;
        }

        if ($fallback !== '') {
            return $fallback;
        }

        return 'کاربر '.(string) $user->chat_id;
    }

    /** The sender name recorded on a freshly created ticket. */
    private function senderNameOf(Ticket $ticket): string
    {
        $fromMessage = $ticket->messages()->first();

        if ($fromMessage !== null && (string) $fromMessage->sender_name !== '') {
            return (string) $fromMessage->sender_name;
        }

        $user = User::query()->where('chat_id', (string) $ticket->chat_id)->first();

        return $user !== null && (string) $user->name !== ''
            ? (string) $user->name
            : 'کاربر '.$ticket->chat_id;
    }

    /** A read of a state key written by startNewTicket()/startUserReply(). */
    private function stateValue(User $user, string $key): mixed
    {
        return $this->states()->get($user)[$key] ?? null;
    }

    /** Telegram-HTML escaping, the fork's EmojiService::escape(). */
    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** The fork's jdate() in Tehran time, used on the timestamps it showed. */
    private function timeText(\DateTimeInterface|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        try {
            $date = $value instanceof \DateTimeInterface ? $value : new \DateTimeImmutable((string) $value);
        } catch (Throwable) {
            return (string) $value;
        }

        $jalali = JalaliDate::gregorianToJalali(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date->format('j'),
        );

        return sprintf(
            '%04d-%02d-%02d %s',
            $jalali['year'],
            $jalali['month'],
            $jalali['day'],
            $date->format('H:i'),
        );
    }

    /** Send without letting a Telegram failure break the flow. */
    private function quietSend(string $chatId, string $text, array $params = []): void
    {
        try {
            $this->tg()->sendMessage($chatId, $text, array_merge(['parse_mode' => 'HTML'], $params));
        } catch (Throwable $e) {
            Log::warning('A ticket notification could not be delivered.', [
                'chat_id' => $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /** The inline keyboard JSON the fork handed back to bot.php. */
    private function keyboard(array $rows): string
    {
        return json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);
    }
}
