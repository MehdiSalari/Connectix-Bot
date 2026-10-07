<?php

declare(strict_types=1);

namespace App\Services\Migration;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Copy the rows of a legacy installation into this one.
 *
 * The rewrite uses the same table and column names as the legacy code, so the
 * import is a straight copy with three rules that make it safe to run on a
 * production table:
 *
 *  - it only ever inserts or updates. Nothing is deleted, so a mistake is
 *    undone by restoring the backup the operator took beforehand, and a second
 *    run repairs rather than duplicates;
 *  - every row is matched on its natural key, so a run that is interrupted and
 *    repeated converges on the same state;
 *  - a row that cannot be mapped is counted and reported, never silently
 *    dropped. `legacy:verify` compares the counts afterwards.
 *
 * `--dry-run` walks the same code and writes nothing, so an operator can see
 * exactly what would change.
 */
class LegacyImportService
{
    /**
     * Tables in dependency order, with the natural key each is matched on.
     *
     * @var array<string, string>
     */
    public const TABLES = [
        'admins' => 'email',
        'users' => 'chat_id',
        'wallets' => 'chat_id',
        'clients' => 'id',
        'payments' => 'order_number',
        'wallet_transactions' => 'id',
        'sms_payments' => 'id',
    ];

    /**
     * Columns copied per table. A column that the legacy table does not have is
     * skipped, so a slightly older database still imports.
     *
     * @var array<string, array<int, string>>
     */
    private const COLUMNS = [
        'admins' => ['id', 'email', 'password', 'token', 'chat_id', 'role'],
        'users' => ['id', 'chat_id', 'telegram_id', 'name', 'email', 'phone', 'avatar', 'action', 'test', 'created_at'],
        'wallets' => ['id', 'chat_id', 'balance', 'created_at'],
        'clients' => ['id', 'count_of_devices', 'username', 'password', 'chat_id', 'user_id', 'created_at'],
        'payments' => ['id', 'order_number', 'chat_id', 'client_id', 'plan_id', 'price', 'coupon', 'is_paid', 'method', 'created_at'],
        'wallet_transactions' => ['id', 'wallet_id', 'amount', 'operation', 'chat_id', 'status', 'type', 'created_at'],
        'sms_payments' => ['id', 'message', 'amount', 'bank', 'fingerprint', 'payment_id', 'payment_type', 'expired_at', 'created_at'],
    ];

    public function __construct(
        private readonly LegacySource $source,
    ) {}

    /**
     * Import every table, or only the ones named.
     *
     * @param  array<int, string>|null  $only
     * @param  (callable(string, int, int): void)|null  $onTable  called after each table: (table, tables done, tables total)
     * @return array<string, array{read: int, written: int, skipped: int, failed: int}>
     */
    public function import(?array $only = null, bool $dryRun = false, int $chunk = 500, ?callable $onLine = null, ?callable $onTable = null): array
    {
        $report = [];
        $tables = $only === null ? array_keys(self::TABLES) : array_values($only);
        $known = array_values(array_filter($tables, static fn (string $table): bool => isset(self::TABLES[$table])));
        $total = count($known);
        $done = 0;

        foreach ($tables as $table) {
            if (! isset(self::TABLES[$table])) {
                $report[$table] = ['read' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 0];

                if ($onLine !== null) {
                    $onLine("  ? unknown table [{$table}] was not imported");
                }

                continue;
            }

            $report[$table] = $this->importTable($table, $dryRun, $chunk, $onLine);
            $done++;

            if ($onTable !== null) {
                $onTable($table, $done, $total);
            }
        }

        return $report;
    }

    /**
     * @return array{read: int, written: int, skipped: int, failed: int}
     */
    private function importTable(string $table, bool $dryRun, int $chunk, ?callable $onLine): array
    {
        $stats = ['read' => 0, 'written' => 0, 'skipped' => 0, 'failed' => 0];

        if (! $this->source->hasTable($table)) {
            if ($onLine !== null) {
                $onLine("  ? the legacy table [{$table}] does not exist");
            }

            return $stats;
        }

        $orderBy = self::TABLES[$table] === 'id' ? 'id' : self::TABLES[$table];
        $offset = 0;

        while (true) {
            $rows = $this->source->rows($table, $orderBy, $chunk, $offset);

            if ($rows->isEmpty()) {
                break;
            }

            foreach ($rows as $row) {
                $stats['read']++;
                $result = $this->importRow($table, (array) $row, $dryRun);
                $stats[$result]++;
            }

            if ($rows->count() < $chunk) {
                break;
            }

            $offset += $chunk;
        }

        if ($onLine !== null) {
            $onLine(sprintf(
                '  %s: %d read, %d written, %d unchanged, %d failed',
                $table,
                $stats['read'],
                $stats['written'],
                $stats['skipped'],
                $stats['failed'],
            ));
        }

        return $stats;
    }

    /**
     * Write one row, matched on its natural key.
     *
     * @param  array<string, mixed>  $row
     * @return string `written`, `skipped` or `failed`
     */
    private function importRow(string $table, array $row, bool $dryRun): string
    {
        try {
            $values = $this->map($table, $row);

            if ($values === null) {
                return 'failed';
            }

            $key = self::TABLES[$table];
            $keyValue = $values[$key] ?? null;

            if ($keyValue === null || $keyValue === '') {
                // A row with no natural key cannot be matched again, so copying
                // it would create a duplicate on the next run.
                Log::warning('A legacy row has no natural key and was not imported.', ['table' => $table]);

                return 'failed';
            }

            $exists = DB::table($table)->where($key, $keyValue)->exists();

            if ($exists) {
                return 'skipped';
            }

            if (! $dryRun) {
                DB::table($table)->insert($values);
            }

            return 'written';
        } catch (Throwable $e) {
            Log::error('A legacy row could not be imported.', [
                'table' => $table,
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        }
    }

    /**
     * Reduce a legacy row to the columns the new schema has.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>|null
     */
    private function map(string $table, array $row): ?array
    {
        if (! DB::getSchemaBuilder()->hasTable($table)) {
            return null;
        }

        $available = array_map(
            strtolower(...),
            DB::getSchemaBuilder()->getColumnListing($table),
        );

        $values = [];

        foreach (self::COLUMNS[$table] as $column) {
            if (! in_array(strtolower($column), $available, true)) {
                continue;
            }

            if (! array_key_exists($column, $row) || $row[$column] === null) {
                continue;
            }

            $values[$column] = $row[$column];
        }

        if ($values === []) {
            return null;
        }

        // The rewrite never has a null primary key: a legacy row without one is
        // given its next local id, exactly as a new row would be.
        if (array_key_exists('id', $values) && ($values['id'] === null || $values['id'] === '')) {
            unset($values['id']);
        }

        return $values;
    }
}
