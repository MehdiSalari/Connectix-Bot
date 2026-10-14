<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Support\TicketService;
use App\Services\Telegram\AdminGuard;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The ticketing menu and its callbacks, port of the `tickets`, `tickets_new`
 * and `ticketslist/myticket/user/uclose/close/reply/tkhist/ticketrate`
 * branches of bot.php (callBackCheck) plus the three ticket text steps
 * (ticket_new, user_reply, admin_reply).
 *
 * The admin-only families (`reply`, `close`, `tkhist`) and the `admin_reply`
 * text step are refused with the fork's «⛔ دسترسی غیرمجاز.» for anybody who
 * is not an administrator — the same gate the fork ran before dispatching the
 * callback ($adminOnlyCmds in functions.php). `ticketrate` stays open to the
 * ticket's owner: the fork only ever put its star buttons in the customer's
 * chat.
 *
 * A bare family (`tkhist` with no id) falls through to the service with an
 * empty suffix, reproducing the fork's `(int) ''` → 0 → «تیکت یافت نشد»
 * answers. Ownership is enforced inside the service: a ticket id belonging to
 * another chat reads as «not found», so crafted callback_data cannot open a
 * stranger's thread (anti-IDOR).
 */
class TicketHandler implements UpdateHandler
{
    /**
     * Callback families that carry a suffix after `_`: a page number, a
     * ticket id, or `{id}_{rating}`. The bare family is accepted too (empty
     * suffix), matching the fork, which forwarded it unchanged.
     *
     * @var list<string>
     */
    private const SUFFIXED = [
        'ticketslist',
        'myticket',
        'user',
        'uclose',
        'reply',
        'close',
        'tkhist',
        'ticketrate',
    ];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly TelegramService $telegram,
        private readonly UserStateService $state,
        private readonly AdminGuard $admins,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data !== null) {
            return $data === 'tickets'
                || $data === 'tickets_new'
                || $this->suffixOf($data) !== null;
        }

        if ($update->text() === null) {
            return false;
        }

        // /start escapes every step in the fork (its case precedes the
        // actionStep branch), so it must reach StartHandler instead.
        if (trim((string) $update->text()) === '/start') {
            return false;
        }

        // The three text steps the ticket flows arm.
        return in_array(
            ($this->state->get($user) ?? [])['action'] ?? null,
            [
                TicketService::ACTION_TICKET_NEW,
                TicketService::ACTION_USER_REPLY,
                TicketService::ACTION_ADMIN_REPLY,
            ],
            true,
        );
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = $update->callbackData();

        if ($data === null) {
            $this->handleText($update, $user);

            return;
        }

        if ($data === 'tickets') {
            $this->respond($update, $user, $this->tickets->menu($user));

            return;
        }

        if ($data === 'tickets_new') {
            $this->respond($update, $user, $this->tickets->startNewTicket($user));

            return;
        }

        [$family, $suffix] = $this->suffixOf($data);
        $isAdmin = $this->isAdmin($update);

        $result = match ($family) {
            'ticketslist' => $this->tickets->listForUser((string) $user->chat_id, $suffix),
            'myticket' => $this->tickets->viewForUser((string) $user->chat_id, $suffix),
            'user' => $this->tickets->startUserReply($user, $suffix),
            'uclose' => $this->tickets->closeByUser($user, $suffix),
            'reply' => $this->tickets->startAdminReply($user, $suffix, $isAdmin),
            'close' => $this->tickets->adminCloseTicket($user, $suffix, $isAdmin),
            'tkhist' => $this->tickets->historyForAdmin($user, $suffix, $isAdmin),
            default => $this->tickets->rateTicket($user, $suffix),
        };

        $this->respond($update, $user, $result);
    }

    /**
     * The three text steps: the new ticket, the customer's reply and the
     * administrator's reply (port of bot.php:315-356).
     */
    private function handleText(TelegramUpdate $update, User $user): void
    {
        $state = $this->state->get($user) ?? [];
        $text = (string) ($update->text() ?? '');
        $action = $state['action'] ?? null;

        $result = match ($action) {
            TicketService::ACTION_TICKET_NEW => $this->tickets->submitTicket($user, $text),
            TicketService::ACTION_USER_REPLY => $this->tickets->appendUserMessage($user, $text),
            TicketService::ACTION_ADMIN_REPLY => $this->tickets->addAdminMessage($user, $text, $this->isAdmin($update)),
            default => null,
        };

        if ($result !== null) {
            $this->respond($update, $user, $result);
        }
    }

    /**
     * The `family` and `suffix` behind a callback: `ticketslist_2` →
     * ['ticketslist', '2'], bare `tkhist` → ['tkhist', ''], anything else →
     * null.
     *
     * @return array{0: string, 1: string}|null
     */
    private function suffixOf(string $data): ?array
    {
        foreach (self::SUFFIXED as $family) {
            if ($data === $family) {
                return [$family, ''];
            }

            if (str_starts_with($data, $family.'_')) {
                return [$family, substr($data, strlen($family) + 1)];
            }
        }

        return null;
    }

    /**
     * Render a service result: edit the callback message when the update
     * carried one, otherwise send fresh — the same edit-then-send split
     * bot.php:1428 made with `editMessageText`.
     *
     * @param  array{text: string, reply_markup: string|null}  $result
     */
    private function respond(TelegramUpdate $update, User $user, array $result): void
    {
        $chatId = (string) $user->chat_id;
        $messageId = $update->callbackMessageId();
        $params = ['parse_mode' => 'HTML'];

        if (($result['reply_markup'] ?? null) !== null) {
            $params['reply_markup'] = $result['reply_markup'];
        }

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $result['text'], $params);

            return;
        }

        $this->telegram->sendMessage($chatId, $result['text'], $params);
    }

    /** Whether the sender of this update is an administrator. */
    private function isAdmin(TelegramUpdate $update): bool
    {
        return $this->admins->isAdmin($update->fromUserId());
    }
}
