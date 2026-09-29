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
 * `/start`: the welcome message and the home keyboard.
 *
 * Port of the `/start` branch of the legacy `bot.php` switch, which sent the
 * panel's welcome text with `keyboard('main_menu')`. A `/start` sent as a
 * callback (Telegram keeps the button on a private chat) is treated the same,
 * as legacy did by matching the text.
 *
 * The state is cleared first because legacy got here through `userInfo()`,
 * which started with `actionStep('clear', ...)`.
 */
class StartHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly MessageFactory $messages,
        private readonly KeyboardFactory $keyboards,
        private readonly UserStateService $state,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        if ($update->isCallbackQuery()) {
            return $update->callbackData() === '/start';
        }

        return trim((string) $update->text()) === '/start';
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $chatId = (string) $user->chat_id;

        $this->state->tryClear($user);

        $this->telegram->sendMessage($chatId, $this->messages->make('welcome_message'), [
            'reply_markup' => json_encode([
                'inline_keyboard' => $this->keyboards->mainMenu($chatId),
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
