<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Telegram\KeyboardFactory;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The home screen, reached from the `main_menu` callback — and from the
 * legacy `new_menu` one, whose buttons still sit in old messages.
 *
 * Legacy re-sent the welcome message together with the home keyboard. When the
 * user pressed the home button on an existing message, the old one was edited
 * in place so the chat does not fill up with copies of the menu.
 *
 * The state is cleared first, because legacy reached this branch through
 * `userInfo()`, whose first statement was `actionStep('clear', ...)`. Leaving a
 * step open would keep answering later messages as if the flow were still
 * running: the next free text would be read as a coupon code, and an old
 * payment button would resume an abandoned order.
 */
class MainMenuHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly MessageFactory $messages,
        private readonly KeyboardFactory $keyboards,
        private readonly UserStateService $state,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        return in_array($update->callbackData(), ['main_menu', 'new_menu'], true);
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $chatId = (string) $user->chat_id;

        $this->state->tryClear($user);

        $keyboard = json_encode([
            'inline_keyboard' => $this->keyboards->mainMenu($chatId),
        ], JSON_UNESCAPED_UNICODE);

        $text = $this->messages->make('welcome_message');

        $messageId = $update->callbackMessageId();

        // Legacy answered `new_menu` (bot.php:400) with a fresh message even
        // though the button sits on an existing one: keyboards sent before the
        // rewrite still carry that callback and must keep working.
        if ($update->callbackData() === 'new_menu' || $messageId === null) {
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => $keyboard,
            ]);

            return;
        }

        $this->telegram->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => $keyboard,
        ]);
    }
}
