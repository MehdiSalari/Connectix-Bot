<?php

declare(strict_types=1);

namespace App\Services\Wallet;

use App\Enums\WalletOperation;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\TelegramApiException;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Telegram\TelegramService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Wallet balances and the wallet ledger.
 *
 * Port of `wallet()`, `createWalletTransaction()` and the query half of
 * `getWalletTransactions()`. Balance arithmetic is integer based because the
 * legacy column is a string.
 *
 * Every balance change and its ledger entry are written inside a database
 * transaction so the two can never drift apart. Telegram notifications are
 * sent after the commit, never inside it.
 */
class WalletService
{
    public function __construct(
        private readonly TelegramService $telegram,
    ) {
    }

    /**
     * Look up a wallet by chat id.
     */
    public function find(string|int $chatId): ?Wallet
    {
        return Wallet::query()->where('chat_id', (string) $chatId)->first();
    }

    /**
     * Every wallet, newest last. Port of `wallet('get')` without an argument.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Wallet>
     */
    public function all()
    {
        return Wallet::query()->get();
    }

    /**
     * Create a wallet with an initial balance.
     */
    public function create(string|int $chatId, int $balance = 0): ?Wallet
    {
        try {
            return Wallet::query()->create([
                'chat_id' => (string) $chatId,
                'balance' => (string) $balance,
            ]);
        } catch (QueryException $e) {
            Log::error('Failed to create wallet.', [
                'chat_id' => (string) $chatId,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Current balance, or zero when the wallet does not exist yet.
     */
    public function balance(string|int $chatId): int
    {
        return $this->find($chatId)?->balanceAmount() ?? 0;
    }

    /**
     * Apply a balance change and record it in the ledger atomically.
     *
     * Returns the resulting wallet, or null when the wallet is missing.
     */
    public function adjust(
        string|int $chatId,
        WalletOperation $operation,
        int $amount,
        WalletTransactionType $type,
        WalletTransactionStatus $status = WalletTransactionStatus::Success,
        bool $announce = false,
    ): ?Wallet {
        return DB::transaction(function () use ($chatId, $operation, $amount, $type, $status, $announce): ?Wallet {
            // Lock the row so concurrent purchases cannot both read the old balance.
            $wallet = Wallet::query()
                ->where('chat_id', (string) $chatId)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                return null;
            }

            $current = $wallet->balanceAmount();

            $newBalance = $operation === WalletOperation::Increase
                ? $current + $amount
                : $current - $amount;

            $wallet->forceFill(['balance' => (string) $newBalance])->save();

            WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'amount' => (string) $amount,
                'operation' => $operation->value,
                'chat_id' => (string) $chatId,
                'status' => $status->value,
                'type' => $type->value,
            ]);

            return $wallet;
        });
    }

    /**
     * Increase a balance. Port of `wallet('INCREASE', ...)`.
     */
    public function increase(
        string|int $chatId,
        int $amount,
        WalletTransactionType $type = WalletTransactionType::DoneByAdmin,
        WalletTransactionStatus $status = WalletTransactionStatus::Success,
        bool $announce = false,
    ): ?Wallet {
        return $this->adjust($chatId, WalletOperation::Increase, $amount, $type, $status, $announce);
    }

    /**
     * Decrease a balance. Port of `wallet('DECREASE', ...)`.
     */
    public function decrease(
        string|int $chatId,
        int $amount,
        WalletTransactionType $type = WalletTransactionType::Buy,
        WalletTransactionStatus $status = WalletTransactionStatus::Success,
    ): ?Wallet {
        return $this->adjust($chatId, WalletOperation::Decrease, $amount, $type, $status);
    }

    /**
     * Whether the wallet can cover the amount.
     */
    public function canAfford(string|int $chatId, int $amount): bool
    {
        return $this->balance($chatId) >= $amount;
    }

    /**
     * Create a pending deposit request that awaits admin approval.
     *
     * Port of the `createWalletTransaction(null, 'PENDING', ...)` call made by
     * bot.php when a receipt photo arrives.
     */
    public function createPendingDeposit(
        string|int $chatId,
        int $amount,
        WalletTransactionType $type = WalletTransactionType::CardToCard,
    ): ?WalletTransaction {
        $wallet = $this->find($chatId);

        if ($wallet === null) {
            Log::error('Cannot create a deposit for a user without a wallet.', [
                'chat_id' => (string) $chatId,
            ]);

            return null;
        }

        return WalletTransaction::query()->create([
            'wallet_id' => $wallet->id,
            'amount' => (string) $amount,
            'operation' => WalletOperation::Increase->value,
            'chat_id' => (string) $chatId,
            'status' => WalletTransactionStatus::Pending->value,
            'type' => $type->value,
        ]);
    }

    /**
     * Change the status of an existing transaction.
     *
     * Port of `createWalletTransaction($id, $status)`.
     */
    public function setTransactionStatus(int $transactionId, WalletTransactionStatus $status): bool
    {
        return WalletTransaction::query()
            ->where('id', $transactionId)
            ->update(['status' => $status->value]) > 0;
    }

    public function findTransaction(int $transactionId): ?WalletTransaction
    {
        return WalletTransaction::query()->find($transactionId);
    }

    /**
     * Ledger entries for a chat, newest first. Port of `wallet('transactions')`.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, WalletTransaction>
     */
    public function transactionsFor(string|int $chatId)
    {
        return WalletTransaction::query()
            ->where('chat_id', (string) $chatId)
            ->orderByDesc('created_at')
            ->get();
    }

    /**
     * Notify the user about an admin initiated adjustment.
     *
     * Port of the `DONE_BY_ADMIN` + `$announce` branch of the legacy
     * `createWalletTransaction()`.
     */
    public function announceAdjustment(
        string|int $chatId,
        WalletOperation $operation,
        int $amount,
    ): void {
        $formatted = number_format($amount);

        $text = match ($operation) {
            WalletOperation::Increase => "💰 مبلغ $formatted تومان به کیف پول شما اضافه شد. 📈",
            WalletOperation::Decrease => "💰 مبلغ $formatted تومان از کیف پول شما کم شد. 📉",
        };

        try {
            $this->telegram->sendMessage($chatId, $text, [
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => '👝 |  کیف پول', 'callback_data' => 'wallet']],
                        [['text' => '🏡 | خانه', 'callback_data' => 'main_menu']],
                    ],
                ], JSON_UNESCAPED_UNICODE),
            ]);
        } catch (TelegramApiException $e) {
            Log::warning('Failed to announce wallet adjustment to the user.', [
                'chat_id' => (string) $chatId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
