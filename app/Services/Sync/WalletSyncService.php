<?php

declare(strict_types=1);

namespace App\Services\Sync;

use App\Enums\WalletOperation;
use App\Enums\WalletTransactionStatus;
use App\Enums\WalletTransactionType;
use App\Exceptions\ConnectixApiException;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\Connectix\ConnectixService;
use App\Support\JalaliCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Reconcile the local `wallets` and `wallet_transactions` tables with the panel.
 *
 * Port of the wallet half of legacy `setup/setup.php`, which imported the panel
 * balances and the whole transaction history on every install. Two legacy
 * details are load bearing and are kept exactly:
 *
 *  - a wallet is keyed by `chat_id` and its balance is overwritten, because the
 *    panel is the authority for the balance. A local adjustment that the panel
 *    does not know about is therefore replaced on the next run; the admin
 *    adjustment flow is what writes those rows, and `WalletService` logs them
 *    with `DONE_BY_ADMIN` so the panel copy stays the balance of record.
 *  - the panel timestamps are Jalali, so they are converted before they are
 *    stored, and a transaction without a `transaction_id` that is a decrease is
 *    recorded as a `BUY`, which is how the panel labels a purchase.
 *
 * Transactions are inserted with `INSERT IGNORE` semantics on the panel's own
 * identifier, so re-running the sync does not duplicate history. The panel does
 * not expose a transaction id for every row, so the guard is on the tuple that
 * identifies one: wallet, amount, operation, status, type and timestamp.
 */
class WalletSyncService
{
    public function __construct(
        private readonly ConnectixService $connectix,
    ) {}

    /**
     * @param  callable(string $line): void  $onLine
     * @return array{wallets: int, created: int, updated: int, transactions: int, skipped: int}
     */
    public function sync(callable $onLine): array
    {
        $stats = ['wallets' => 0, 'created' => 0, 'updated' => 0, 'transactions' => 0, 'skipped' => 0];

        $wallets = $this->connectix->listWallets();

        $onLine(count($wallets).' wallet(s) on the panel');

        foreach ($wallets as $index => $row) {
            $panelId = $row['id'] ?? null;
            $chatId = $this->chatId($row);

            if ($panelId === null || $chatId === null) {
                // Without a chat id there is nobody to credit, and a wallet row
                // without one can never be reached by the bot.
                $stats['skipped']++;
                $onLine('  ! wallet without an id or chat id was skipped');

                continue;
            }

            try {
                $detail = $this->connectix->getWallet((string) $panelId);
            } catch (ConnectixApiException $e) {
                $stats['skipped']++;
                $onLine("  ! wallet {$panelId}: {$e->getMessage()}");

                continue;
            }

            try {
                $balance = JalaliCalendar::amount($row['balance'] ?? '0');

                DB::transaction(function () use ($chatId, $balance, $detail, &$stats, $onLine): void {
                    [$wallet, $created] = $this->storeWallet($chatId, $balance);

                    $stats['wallets']++;
                    $stats[$created ? 'created' : 'updated']++;

                    $stats['transactions'] += $this->storeTransactions($wallet, $detail, $onLine);
                });
            } catch (\Throwable $e) {
                $stats['skipped']++;
                $onLine("  ! wallet {$panelId} was not stored: ".$e->getMessage());

                Log::error('A panel wallet could not be imported.', [
                    'wallet_id' => $panelId,
                    'error' => $e->getMessage(),
                ]);

                continue;
            }

            if (($index + 1) % 10 === 0 || $index + 1 === count($wallets)) {
                $onLine("  {$stats['wallets']}/".count($wallets).' wallets, '.$stats['transactions'].' transactions');
            }
        }

        return $stats;
    }

    /**
     * @return array{0: Wallet, 1: bool} The wallet and whether it was created.
     */
    private function storeWallet(string $chatId, int $balance): array
    {
        $wallet = Wallet::query()->where('chat_id', $chatId)->first();

        if ($wallet !== null) {
            $wallet->forceFill([
                'balance' => (string) $balance,
                'created_at' => $wallet->created_at ?? now(),
            ])->save();

            return [$wallet, false];
        }

        $created = Wallet::query()->create([
            'chat_id' => $chatId,
            'balance' => (string) $balance,
            'created_at' => now(),
        ]);

        return [$created, true];
    }

    /**
     * @param  array<string, mixed>|null  $detail
     * @return int How many transaction rows were written.
     */
    private function storeTransactions(Wallet $wallet, ?array $detail, callable $onLine): int
    {
        $transactions = $detail['transactions'] ?? null;

        if (! is_array($transactions)) {
            return 0;
        }

        $written = 0;

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $values = $this->mapTransaction($wallet, $transaction);

            if ($values === null) {
                continue;
            }

            $exists = WalletTransaction::query()
                ->where('wallet_id', $wallet->id)
                ->where('amount', $values['amount'])
                ->where('operation', $values['operation'])
                ->where('status', $values['status'])
                ->where('type', $values['type'])
                ->where('created_at', $values['created_at'])
                ->exists();

            if ($exists) {
                continue;
            }

            WalletTransaction::query()->create($values);
            $written++;
        }

        return $written;
    }

    /**
     * @param  array<string, mixed>  $transaction
     * @return array<string, mixed>|null
     */
    private function mapTransaction(Wallet $wallet, array $transaction): ?array
    {
        $amount = JalaliCalendar::amount($transaction['amount'] ?? '0');

        if ($amount === 0) {
            return null;
        }

        $operation = $this->operation($transaction['type'] ?? null);
        $type = $this->type($transaction['transaction_id'] ?? null, $operation);
        $status = $this->status($transaction['status'] ?? null);
        $createdAt = JalaliCalendar::parseTimestamp($transaction['created_at'] ?? null) ?? now()->toDateTimeString();

        return [
            'wallet_id' => $wallet->id,
            'amount' => abs($amount),
            'operation' => $operation->value,
            'chat_id' => $wallet->chat_id,
            'status' => $status->value,
            'type' => $type->value,
            'created_at' => $createdAt,
        ];
    }

    private function operation(mixed $value): WalletOperation
    {
        return strtoupper((string) $value) === WalletOperation::Decrease->value
            ? WalletOperation::Decrease
            : WalletOperation::Increase;
    }

    /**
     * The panel omits `transaction_id` on a purchase, and legacy turned that
     * into a `BUY` when the row was a decrease.
     */
    private function type(mixed $transactionId, WalletOperation $operation): WalletTransactionType
    {
        $value = strtoupper(trim((string) $transactionId));

        return match ($value) {
            WalletTransactionType::CardToCard->value => WalletTransactionType::CardToCard,
            WalletTransactionType::DoneByAdmin->value => WalletTransactionType::DoneByAdmin,
            WalletTransactionType::Buy->value => WalletTransactionType::Buy,
            default => $operation === WalletOperation::Decrease
                ? WalletTransactionType::Buy
                : WalletTransactionType::CardToCard,
        };
    }

    private function status(mixed $value): WalletTransactionStatus
    {
        return WalletTransactionStatus::tryFrom(strtoupper(trim((string) $value)))
            ?? WalletTransactionStatus::Success;
    }

    private function chatId(array $row): ?string
    {
        $chatId = $row['chat_id'] ?? null;

        if ($chatId === null || is_array($chatId) || is_bool($chatId)) {
            return null;
        }

        $chatId = trim((string) $chatId);

        return $chatId === '' || strtolower($chatId) === 'null' ? null : $chatId;
    }
}
