<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The FAQ page, port of the `faq` branch of bot.php.
 *
 * The answers come from the panel (`questions_and_answers`) and the keyboard
 * is a single way back home, exactly like `keyboard('faq')`.
 */
class FaqHandler implements UpdateHandler
{
    private const MENU = 'faq';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly MessageFactory $messages,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        return $update->callbackData() === self::MENU;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $this->render($update, $user, $this->messages->make('faq'), [
            [['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']],
        ]);
    }

    /**
     * Edit the callback message when there is one, otherwise send fresh.
     *
     * @param  array<int, array<int, array<string, string>>>  $rows
     */
    private function render(TelegramUpdate $update, User $user, string $text, array $rows): void
    {
        $chatId = (string) $user->chat_id;

        $keyboard = json_encode(['inline_keyboard' => $rows], JSON_UNESCAPED_UNICODE);

        $messageId = $update->callbackMessageId();

        if ($messageId !== null) {
            $this->telegram->editMessageText($chatId, $messageId, $text, [
                'parse_mode' => 'HTML',
                'reply_markup' => $keyboard,
            ]);

            return;
        }

        $this->telegram->sendMessage($chatId, $text, [
            'parse_mode' => 'HTML',
            'reply_markup' => $keyboard,
        ]);
    }
}
