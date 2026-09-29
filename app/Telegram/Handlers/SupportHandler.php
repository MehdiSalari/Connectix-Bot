<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Panel\PanelSettingsService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The support page, port of the `support` branch of bot.php.
 *
 * The message comes from the panel (`contact_support`) and the only real
 * action is the direct link to the seller's support account; the second row
 * walks back to the home menu.
 */
class SupportHandler implements UpdateHandler
{
    private const MENU = 'support';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly PanelSettingsService $settings,
        private readonly MessageFactory $messages,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        return $update->callbackData() === self::MENU;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $rows = [];

        $support = $this->settings->supportTelegram();

        if ($support !== '') {
            $rows[] = [['text' => '📩 |  پیام به پشتیبانی', 'url' => 'https://t.me/'.$support]];
        }

        $rows[] = [['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']];

        $this->render($update, $user, $this->messages->make('support'), $rows);
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
