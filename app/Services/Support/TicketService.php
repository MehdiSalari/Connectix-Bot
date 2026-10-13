<?php

declare(strict_types=1);

namespace App\Services\Support;

use App\Models\Ticket;
use App\Models\TicketMessage;
use Illuminate\Support\Facades\DB;
use RuntimeException;

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

    /**
     * Memoised open-ticket count for the current instance/request.
     */
    private ?int $openCountMemo = null;

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
        } catch (\Throwable) {
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
     * @return \Illuminate\Support\Collection<int, array{ticket: Ticket, last: TicketMessage|null}>
     */
    public function history(string $status = 'ALL'): \Illuminate\Support\Collection
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
}
