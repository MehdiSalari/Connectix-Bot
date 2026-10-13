<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Resellers\ResellerService;
use App\Services\Telegram\TelegramService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The «نمایندگی» (representative) menu and its callbacks, port of the
 * `reseller`, `reseller_apply` and repreqok/repreqno branches of bot.php.
 *
 * `repreqok` / `repreqno` are the inline decision buttons the fork put on the
 * admin's notification. They are gated in the service with AdminGuard, so a
 * non-administrator sending a crafted callback gets the fork's refusal
 * «⛔ دسترسی غیرمجاز.» instead of approving their own request.
 */
class ResellerHandler implements UpdateHandler
{
    private const MENU = 'reseller';

    private const APPLY = 'reseller_apply';

    /** Approval / rejection buttons the admin taps. */
    private const APPROVE_PREFIX = 'repreqok';

    private const REJECT_PREFIX = 'repreqno';

    public function __construct(
        private readonly ResellerService $resellers,
        private readonly TelegramService $telegram,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data === null) {
            return false;
        }

        return $data === self::MENU
            || $data === self::APPLY
            || $data === self::APPROVE_PREFIX
            || str_starts_with($data, self::APPROVE_PREFIX.'_')
            || $data === self::REJECT_PREFIX
            || str_starts_with($data, self::REJECT_PREFIX.'_');
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();

        if ($data === self::MENU) {
            $result = $this->resellers->menuText($user);
            $this->edit($update, $user, $result['text'], $result['reply_markup']);

            return;
        }

        if ($data === self::APPLY) {
            $this->handleApply($update, $user);

            return;
        }

        $position = strrpos($data, '_');
        $suffix = $position === false ? '' : substr($data, $position + 1);

        $adminChat = (string) ($update->fromUserId() ?? $user->chat_id);

        $result = str_starts_with($data, self::APPROVE_PREFIX)
            ? $this->resellers->approveRequestBot($suffix, $adminChat)
            : $this->resellers->rejectRequestBot($suffix, $adminChat);

        $this->edit($update, $user, $result['text'], $result['reply_markup']);
    }

    /**
     * Send the request. The fork edited the pressed message into the
     * confirmation and answered the callback with an alert when the request
     * was refused (insufficient balance, already pending, already a
     * reseller).
     */
    private function handleApply(TelegramUpdate $update, User $user): void
    {
        $result = $this->resellers->apply($user);

        if (($result['answer'] ?? null) !== null) {
            $this->telegram->answerCallbackQueryQuietly(
                $update->callbackId(),
                (string) $result['answer'],
                true,
            );

            return;
        }

        $this->edit($update, $user, $result['text'], $result['reply_markup']);
    }

    /** Edit the callback message in place, or send fresh when there is none. */
    private function edit(TelegramUpdate $update, User $user, string $text, ?string $replyMarkup): void
    {
        $chatId = (string) $user->chat_id;
        $messageId = $update->callbackMessageId();
        $params = ['parse_mode' => 'HTML'];

        if ($replyMarkup !== null) {
            $params['reply_markup'] = $replyMarkup;
        }

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $text, $params);

            return;
        }

        $this->telegram->sendMessage($chatId, $text, $params);
    }
}