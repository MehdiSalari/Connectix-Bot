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
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
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
    ) {}

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
     * @return Collection<int, Wallet>
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
     *
     * `$announce` reproduces the one notification legacy sent from
     * `createWalletTransaction()`: an administrator credit or debit tells the
     * user about it. It is deliberately limited to DONE_BY_ADMIN, because
     * every other transaction already announces itself from its own flow.
     * The message goes out after the commit, so a rolled back transaction
     * never tells the user about a change that did not happen.
     */
    public function adjust(
        string|int $chatId,
        WalletOperation $operation,
        int $amount,
        WalletTransactionType $type,
        WalletTransactionStatus $status = WalletTransactionStatus::Success,
        bool $announce = false,
    ): ?Wallet {
        $wallet = DB::transaction(function () use ($chatId, $operation, $amount, $type, $status): ?Wallet {
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

        if ($announce && $wallet !== null && $type === WalletTransactionType::DoneByAdmin) {
            $this->announceAdjustment($chatId, $operation, $amount);
        }

        return $wallet;
    }

    /**
     * One user's ledger, newest first.
     *
     * The admin profile previews the tail of this and the history modal pages
     * through the rest, so the whole ledger is never loaded for one page view.
     * Ordering is on `id` as well as `created_at`: this table has no second
     * column and `created_at` is second-resolution, so a page break inside one
     * second used to shuffle rows between pages.
     */
    public function history(string|int $chatId, int $perPage = 15, int $page = 1): LengthAwarePaginator
    {
        return WalletTransaction::query()
            ->where('chat_id', (string) $chatId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);
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
     *
     * Port of the `wallet('get')` check in `checkout()`. Legacy compared the
     * balance it had already read and then decreased the wallet in a separate
     * query, so two rapid taps could both pass the check and take the balance
     * negative. Use {@see self::decreaseIfAffordable()} to make the check and
     * the debit one decision.
     */
    public function canAfford(string|int $chatId, int $amount): bool
    {
        return $this->balance($chatId) >= $amount;
    }

    /**
     * Decrease a balance only if it covers the amount, deciding both under one
     * row lock.
     *
     * Returns null when the wallet is missing or cannot cover the amount, in
     * which case nothing is written: no balance change and no ledger entry.
     * This is what the purchase flow uses, so a buyer can never be charged
     * twice for one order or end up with a negative balance.
     */
    public function decreaseIfAffordable(
        string|int $chatId,
        int $amount,
        WalletTransactionType $type = WalletTransactionType::Buy,
        WalletTransactionStatus $status = WalletTransactionStatus::Success,
    ): ?Wallet {
        return DB::transaction(function () use ($chatId, $amount, $type, $status): ?Wallet {
            // The lock is what turns "is there enough" and "take the money" into
            // a single serialised step: a competing purchase blocks here until
            // this transaction commits, and then sees the new balance.
            $wallet = Wallet::query()
                ->where('chat_id', (string) $chatId)
                ->lockForUpdate()
                ->first();

            if ($wallet === null || $wallet->balanceAmount() < $amount) {
                return null;
            }

            $wallet->forceFill([
                'balance' => (string) ($wallet->balanceAmount() - $amount),
            ])->save();

            WalletTransaction::query()->create([
                'wallet_id' => $wallet->id,
                'amount' => (string) $amount,
                'operation' => WalletOperation::Decrease->value,
                'chat_id' => (string) $chatId,
                'status' => $status->value,
                'type' => $type->value,
            ]);

            return $wallet;
        });
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
     * Mark a pending deposit as paid and credit the balance, both under one
     * lock.
     *
     * Port of the `accept` branch of `walletReqs()`, which read the status,
     * then updated it and increased the balance in two separate queries. Two
     * rapid approvals could therefore both read PENDING and credit the balance
     * twice; locking the transaction row makes the decision and the credit one
     * step and a second approval returns null.
     *
     * Returns null when the transaction is missing or already decided.
     */
    public function approveDeposit(int|WalletTransaction $transaction): ?Wallet
    {
        return DB::transaction(function () use ($transaction): ?Wallet {
            /** @var WalletTransaction|null $tx */
            $tx = $transaction instanceof WalletTransaction
                ? WalletTransaction::query()->whereKey($transaction->id)->lockForUpdate()->first()
                : WalletTransaction::query()->whereKey($transaction)->lockForUpdate()->first();

            if ($tx === null || ! $tx->status->isPending()) {
                return null;
            }

            $tx->forceFill(['status' => WalletTransactionStatus::Success->value])->save();

            $wallet = Wallet::query()
                ->where('chat_id', $tx->chat_id)
                ->lockForUpdate()
                ->first();

            if ($wallet === null) {
                return null;
            }

            $wallet->forceFill([
                'balance' => (string) ($wallet->balanceAmount() + (int) $tx->amount),
            ])->save();

            return $wallet;
        });
    }

    /**
     * Mark a pending deposit as rejected.
     *
     * Port of the `reject` branch of `walletReqs()`, given the same atomic
     * guard as {@see self::approveDeposit()}: only a PENDING transaction is
     * decided by the first call.
     */
    public function rejectDeposit(int|WalletTransaction $transaction): bool
    {
        return DB::transaction(function () use ($transaction): bool {
            $query = $transaction instanceof WalletTransaction
                ? WalletTransaction::query()->whereKey($transaction->id)
                : WalletTransaction::query()->whereKey($transaction);

            $updated = $query
                ->where('status', WalletTransactionStatus::Pending->value)
                ->update(['status' => WalletTransactionStatus::RejectedByAdmin->value]);

            return $updated > 0;
        });
    }

    /**
     * Ledger entries for a chat, newest first. Port of `wallet('transactions')`.
     *
     * @return Collection<int, WalletTransaction>
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
