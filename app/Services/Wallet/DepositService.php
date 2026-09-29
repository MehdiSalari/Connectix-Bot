<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Exceptions\TelegramApiException;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Telegram\TelegramService;
use App\Services\User\UserService;
use App\Services\User\UserStateService;
use Illuminate\Support\Facades\Log;

/**
 * What an admin or the SMS auto-payment did to a pending wallet deposit.
 */
final class DepositOutcome
{
    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public const ALREADY_DECIDED = 'already_decided';

    public const MISSING = 'missing';

    public function __construct(
        public readonly string $status,
        public readonly ?WalletTransaction $transaction = null,
        public readonly ?Wallet $wallet = null,
        public readonly ?User $buyer = null,
    ) {}

    public function isApproved(): bool
    {
        return $this->status === self::APPROVED;
    }

    public function isRejected(): bool
    {
        return $this->status === self::REJECTED;
    }

    public function isAlreadyDecided(): bool
    {
        return $this->status === self::ALREADY_DECIDED;
    }

    public function isMissing(): bool
    {
        return $this->status === self::MISSING;
    }

    public function balance(): int
    {
        return $this->wallet?->balanceAmount() ?? 0;
    }
}

/**
 * Approving and rejecting a pending wallet top-up.
 *
 * Port of the `accept` and `reject` branches of `walletReqs()`, shared by the
 * admin callback flow and the bank SMS auto-payment, which both end in the
 * same place: the transaction is decided once, the balance is moved, and the
 * buyer is told about it.
 *
 * The decision itself lives in {@see WalletService}, which locks the row so a
 * second approval can never credit the balance twice. This service adds the
 * parts that are common to both callers: looking the transaction up, finding
 * the buyer, clearing their conversation state (as legacy did) and sending the
 * notification.
 */
class DepositService
{
    public function __construct(
        private readonly WalletService $wallets,
        private readonly UserService $users,
        private readonly UserStateService $state,
        private readonly TelegramService $telegram,
    ) {}

    public function find(int $transactionId): ?WalletTransaction
    {
        return $this->wallets->findTransaction($transactionId);
    }

    /**
     * Credit the deposit and tell the buyer, returning the outcome.
     */
    public function approve(int $transactionId): DepositOutcome
    {
        $transaction = $this->wallets->findTransaction($transactionId);

        if ($transaction === null) {
            return new DepositOutcome(DepositOutcome::MISSING);
        }

        $wallet = $this->wallets->approveDeposit($transaction);

        if ($wallet === null) {
            return new DepositOutcome(
                DepositOutcome::ALREADY_DECIDED,
                $transaction,
            );
        }

        $buyer = $this->users->findByChatId((string) $transaction->chat_id);

        if ($buyer !== null) {
            $this->state->tryClear($buyer);
        }

        $this->notifyUserApproved($transaction, $wallet->balanceAmount());

        if ($buyer === null) {
            Log::warning('Approved a wallet deposit for a user that no longer exists.', [
                'transaction_id' => $transaction->id,
                'chat_id' => $transaction->chat_id,
            ]);
        }

        return new DepositOutcome(
            DepositOutcome::APPROVED,
            $transaction,
            $wallet,
            $buyer,
        );
    }

    /**
     * Reject the deposit and tell the buyer, returning the outcome.
     */
    public function reject(int $transactionId): DepositOutcome
    {
        $transaction = $this->wallets->findTransaction($transactionId);

        if ($transaction === null) {
            return new DepositOutcome(DepositOutcome::MISSING);
        }

        if (! $this->wallets->rejectDeposit($transaction)) {
            return new DepositOutcome(
                DepositOutcome::ALREADY_DECIDED,
                $transaction,
            );
        }

        $buyer = $this->users->findByChatId((string) $transaction->chat_id);

        if ($buyer !== null) {
            $this->state->tryClear($buyer);
        }

        $this->notifyUserRejected($transaction);

        return new DepositOutcome(
            DepositOutcome::REJECTED,
            $transaction,
            $this->wallets->find((string) $transaction->chat_id),
            $buyer,
        );
    }

    // -----------------------------------------------------------------
    // Messages
    // -----------------------------------------------------------------

    /**
     * The text of the admin caption after an approval, exactly as legacy
     * built it for the photo the admin was sent.
     */
    public function approvedCaption(DepositOutcome $outcome): array
    {
        $transaction = $outcome->transaction;
        $userName = $this->buyerName($outcome->buyer);

        $caption = "✅ شماره تراکنش {$transaction->id} با موفقیت تایید شد.\n\n"
            ."👝 شماره کیف پول: {$outcome->wallet->id}\n"
            ."🔢 آیدی: <code>{$transaction->chat_id}</code>\n"
            ."👤 نام کاربری: {$userName}\n"
            .'💵 مبلغ: '.number_format((int) $transaction->amount);

        return $this->captionResult($caption, '✅ | تایید شده');
    }

    /**
     * The admin caption after a rejection.
     */
    public function rejectedCaption(DepositOutcome $outcome): array
    {
        $transaction = $outcome->transaction;
        $userName = $this->buyerName($outcome->buyer);

        $caption = "❌ شماره تراکنش {$transaction->id}  رد شد.\n\n"
            ."👝 شماره کیف پول: {$outcome->wallet->id}\n"
            ."🔢 آیدی: <code>{$transaction->chat_id}</code>\n"
            ."👤 نام کاربری: {$userName}\n"
            .'💵 مبلغ: '.number_format((int) $transaction->amount);

        return $this->captionResult($caption, '❌ | رد شده');
    }

    /**
     * The admin caption for a deposit that was already decided, which is what
     * a second tap on the button renders instead of acting twice.
     */
    public function decidedCaption(WalletTransaction $transaction): array
    {
        $status = $transaction->status;

        $caption = "⚠️ تراکنش شماره {$transaction->id} در وضعیت {$status->labelForAdmin()} است.";

        return $this->captionResult($caption, "{$status->icon()} | {$status->labelForAdmin()}");
    }

    /**
     * @param  array{caption: string, reply_markup: string}  $extra  `caption` and `reply_markup`.
     * @return array{caption: string, reply_markup: string}
     */
    private function captionResult(string $caption, string $button): array
    {
        return [
            'caption' => $caption,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => $button, 'callback_data' => 'not']],
                ],
            ], JSON_UNESCAPED_UNICODE),
        ];
    }

    private function notifyUserApproved(WalletTransaction $transaction, int $balance): void
    {
        $text = "✅ تراکنش شما جهت افزایش موجودی کیف پول تایید شد.\n\n"
            .'💵 مبلغ تراکنش: '.number_format((int) $transaction->amount)."\n"
            .'💰 موجودی کیف پول: '.number_format($balance);

        $this->sendToBuyer($transaction, $text);
    }

    private function notifyUserRejected(WalletTransaction $transaction): void
    {
        $text = "❌ تراکنش شما جهت افزایش موجودی کیف پول رد شد.\n\n"
            .'💵 مبلغ تراکنش: '.number_format((int) $transaction->amount);

        $this->sendToBuyer($transaction, $text);
    }

    private function sendToBuyer(WalletTransaction $transaction, string $text): void
    {
        try {
            $this->telegram->sendMessage($transaction->chat_id, $text, [
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '👝 |  کیف پول', 'callback_data' => 'wallet']],
                        [['text' => '🏡 | خانه', 'callback_data' => 'main_menu']],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (TelegramApiException $e) {
            Log::warning('Could not deliver the deposit result to the buyer.', [
                'chat_id' => $transaction->chat_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The buyer's stored Telegram username with the `@` sign, or the literal
     * legacy fallback when they have none.
     */
    private function buyerName(?User $buyer): string
    {
        $username = $buyer?->telegram_id;

        return $username !== null && trim((string) $username) !== ''
            ? '@'.$username
            : 'نامشخص';
    }
}
