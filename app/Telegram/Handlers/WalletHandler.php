<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Services\Wallet\WalletService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The wallet page and the button that opens a top-up.
 *
 * Port of the `wallet` branch of bot.php and `walletReqs('increase:0')`: the
 * balance view clears any half-finished flow (legacy called
 * `actionStep('clear')` here), creates the wallet row on first visit, and the
 * increase button arms the amount step that PaymentHandler then collects.
 */
class WalletHandler implements UpdateHandler
{
    private const MENU = 'wallet';

    private const INCREASE_PREFIX = 'wallet_increase:';

    public function __construct(
        private readonly TelegramService $telegram,
        private readonly WalletService $wallets,
        private readonly UserStateService $state,
        private readonly MessageFactory $messages,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        $data = $update->callbackData();

        if ($data === null) {
            return false;
        }

        return $data === self::MENU || str_starts_with($data, self::INCREASE_PREFIX);
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $data = (string) $update->callbackData();

        if ($data === self::MENU) {
            $this->showBalance($update, $user);

            return;
        }

        $this->askForAmount($update, $user);
    }

    // -----------------------------------------------------------------
    // Balance
    // -----------------------------------------------------------------

    /**
     * The balance page, port of the `wallet` branch of bot.php.
     */
    private function showBalance(TelegramUpdate $update, User $user): void
    {
        $this->state->clear($user);

        $chatId = (string) $user->chat_id;

        if ($this->wallets->find($chatId) === null) {
            $this->wallets->create($chatId, 0);
        }

        $name = (string) ($user->name ?? '');

        if ($name === '') {
            $name = $user->telegram_id ? '@'.$user->telegram_id : 'نامشخص';
        }

        $text = $this->messages->make('wallet', [
            'walletBalance' => number_format($this->wallets->balance($chatId)),
            'userName' => $name,
            'userId' => $chatId,
        ]);

        $rows = [
            [['text' => '💰 | افزایش موجودی', 'callback_data' => self::INCREASE_PREFIX.'0']],
            [['text' => '↪️ | بازگشت', 'callback_data' => 'main_menu']],
        ];

        $this->render($update, $user, $text, $rows);
    }

    // -----------------------------------------------------------------
    // Top-up
    // -----------------------------------------------------------------

    /**
     * The amount prompt, port of `walletReqs('increase:0')`: the state arms
     * PaymentHandler's text step and the only button cancels back to the
     * wallet.
     */
    private function askForAmount(TelegramUpdate $update, User $user): void
    {
        $this->state->startWalletIncrease($user);

        $this->render($update, $user, $this->messages->make('wallet_increase'), [
            [['text' => '❌ | انصراف', 'callback_data' => self::MENU]],
        ]);
    }

    // -----------------------------------------------------------------
    // Rendering
    // -----------------------------------------------------------------

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
