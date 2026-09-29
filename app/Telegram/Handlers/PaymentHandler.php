<?php

declare(strict_types=1);

namespace App\Telegram\Handlers;

use App\Models\User;
use App\Services\Payment\ReceiptService;
use App\Services\Telegram\MessageFactory;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserStateService;
use App\Telegram\Contracts\UpdateHandler;
use App\Telegram\TelegramUpdate;

/**
 * The payment receipt half of the checkout.
 *
 * Port of the default branch of bot.php: whatever message arrives next decides
 * one of three things, depending on the conversation state.
 *
 *  - a card purchase (`pay` set, not `discount`) must be answered with a
 *    photo, which becomes the receipt;
 *  - a wallet top-up first collects a numeric amount, then a photo;
 *  - the `pending` step a deposit ends in has no reply, so a stray message
 *    after the receipt was forwarded is ignored exactly as legacy ignored it.
 *
 * The photo itself is handed to {@see ReceiptService}; this handler only asks
 * for it and rejects anything that is not a photo.
 */
class PaymentHandler implements UpdateHandler
{
    public function __construct(
        private readonly TelegramService $telegram,
        private readonly MessageFactory $messages,
        private readonly UserStateService $state,
        private readonly ReceiptService $receipts,
    ) {}

    public function supports(TelegramUpdate $update, User $user): bool
    {
        if ($update->text() === null && $update->photo() === []) {
            return false;
        }

        if ($this->state->awaitingReceipt($user)) {
            return true;
        }

        $action = ($this->state->get($user) ?? [])['action'] ?? null;

        return $action === UserStateService::ACTION_WALLET_INCREASE;
    }

    public function handle(TelegramUpdate $update, User $user): void
    {
        $state = $this->state->get($user) ?? [];

        if ($this->state->awaitingReceipt($user)) {
            $this->handleCardReceipt($update, $user, $state);

            return;
        }

        if (($state['action'] ?? null) === UserStateService::ACTION_WALLET_INCREASE) {
            $this->handleWalletIncrease($update, $user, $state);
        }
    }

    // -----------------------------------------------------------------
    // Card purchase
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $state
     */
    private function handleCardReceipt(TelegramUpdate $update, User $user, array $state): void
    {
        $fileId = $this->photoFileId($update);

        if ($fileId === null) {
            // Legacy asked for a photo again and offered one way out: home.
            $this->askForPhoto($user, 'main_menu');

            return;
        }

        $this->receipts->submitPurchase($user, $state, $fileId);
    }

    // -----------------------------------------------------------------
    // Wallet top-up
    // -----------------------------------------------------------------

    /**
     * @param  array<string, mixed>  $state
     */
    private function handleWalletIncrease(TelegramUpdate $update, User $user, array $state): void
    {
        switch ($state['step'] ?? null) {
            case 'get_amount':
                $this->collectDepositAmount($update, $user);

                return;

            case 'get_receipt':
                $fileId = $this->photoFileId($update);

                if ($fileId === null) {
                    // The refund button for a deposit returns to the wallet.
                    $this->askForPhoto($user, 'wallet');

                    return;
                }

                $amount = (int) ($state['amount'] ?? 0);

                if ($amount <= 0) {
                    return;
                }

                $this->receipts->submitDeposit($user, $amount, $fileId);

                return;

            default:
                // The `pending` step has no reply in legacy; leave it alone.
                return;
        }
    }

    /**
     * The text-to-amount step of a wallet top-up.
     */
    private function collectDepositAmount(TelegramUpdate $update, User $user): void
    {
        $text = $update->text();

        if ($text === null || ! is_numeric($text)) {
            $this->telegram->sendMessage($user->chat_id, '🔢 لطفا مبلغ را به صورت اعداد انگلیسی وارد کنید!');

            return;
        }

        $amount = (int) $text;

        if ($amount < $this->messages->minimumDeposit()) {
            $this->telegram->sendMessage(
                $user->chat_id,
                '💲 حداقل مبلغ واریزی '.number_format($this->messages->minimumDeposit()).' تومان است!',
            );

            return;
        }

        $this->state->set($user, [
            'action' => UserStateService::ACTION_WALLET_INCREASE,
            'step' => 'get_receipt',
            'amount' => $amount,
        ]);

        $this->telegram->sendMessage($user->chat_id, $this->messages->make('card', ['amount' => $amount]), [
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '❌ | لغو', 'callback_data' => 'wallet']],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    /**
     * The `file_id` of the largest photo in the message, or null without one.
     */
    private function photoFileId(TelegramUpdate $update): ?string
    {
        $photo = $update->largestPhoto();

        return $photo['file_id'] ?? null;
    }

    /**
     * The nudge legacy showed when the user sent anything but a photo.
     */
    private function askForPhoto(User $user, string $cancelCallback): void
    {
        $this->telegram->sendMessage($user->chat_id, '🖼️ لطفا سند واریزی را به صورت تصویر ارسال کنید!', [
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '❌ | انصراف', 'callback_data' => $cancelCallback]],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ]);
    }
}
