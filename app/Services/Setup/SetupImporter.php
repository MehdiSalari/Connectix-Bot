<?php

declare(strict_types=1);

namespace App\Services\Setup;

use App\Enums\SetupStep;
use App\Services\Migration\LegacyImportService;
use App\Services\Sync\ClientSyncService;
use App\Services\Sync\WalletSyncService;
use App\Support\LogRedaction;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The two data imports of the install wizard, out of the controller.
 *
 * Both callers — the HTTP controller (inline driver) and the `setup:import`
 * command (process driver) — need identical behaviour: the same progress
 * callbacks, the same fail-safe messaging, the same closing report. Keeping
 * the body here means the two paths cannot drift, and the command path needs
 * no request, no session and no authenticated user to run.
 *
 * Every result is an array instead of a redirect so each caller decides where
 * the outcome goes: flash messages for the request, stdout/exit code for the
 * command, and always the shared progress file for the watching tab.
 */
final class SetupImporter
{
    /** Lines kept in memory for the flash log of an inline run. */
    private const FLASH_LOG_LINES = 200;

    public function __construct(private readonly SetupStateStore $store) {}

    /**
     * Copy the rows of the previous installation over.
     *
     * @param  array{dryRun?: bool, tables?: array<int, string>|null}  $inputs
     * @return array{ok: bool, status?: string, message?: string, report?: array<mixed>, log?: string, error?: string}
     */
    public function legacy(array $inputs): array
    {
        $dryRun = (bool) ($inputs['dryRun'] ?? true);
        $tables = $inputs['tables'] ?? null;
        $lines = [];

        ImportProgress::start('legacy', $dryRun ? 'آزمایش انتقال از دیتابیس قبلی' : 'انتقال اطلاعات نصب قبلی');

        try {
            $report = app(LegacyImportService::class)->import(
                only: $tables ?: null,
                dryRun: $dryRun,
                chunk: 200,
                onLine: function (string $line) use (&$lines): void {
                    if (count($lines) < self::FLASH_LOG_LINES) {
                        $lines[] = $line;
                    }

                    ImportProgress::line($line);
                },
                onTable: function (string $table, int $done, int $total) use ($dryRun): void {
                    ImportProgress::update(
                        (int) floor($done / max($total, 1) * 100),
                        ($dryRun ? 'آزمایش جدول ' : 'انتقال جدول ').$table,
                    );
                },
            );
        } catch (Throwable $e) {
            Log::error('The installer could not read the legacy database.', ['detail' => LogRedaction::mask($e->getMessage())]);
            $this->store->markFailed(SetupStep::Import->value, $this->safe($e));
            ImportProgress::line('error: '.$this->safe($e));
            ImportProgress::fail('خواندن دیتابیس قبلی ناموفق بود. اتصال را بررسی کنید.');

            return ['ok' => false, 'error' => 'خواندن دیتابیس قبلی ناموفق بود. اتصال را بررسی کنید.'];
        }

        $message = $dryRun ? 'حالت آزمایشی تمام شد؛ چیزی نوشته نشد.' : 'انتقال اطلاعات تمام شد.';
        ImportProgress::finish($message, $report);

        return [
            'ok' => true,
            'status' => $dryRun ? 'حالت آزمایشی اجرا شد؛ چیزی نوشته نشد.' : 'انتقال اطلاعات انجام شد.',
            'message' => $message,
            'report' => $report,
            'log' => implode(PHP_EOL, $lines),
        ];
    }

    /**
     * Read the whole seller panel: every page of clients, then the wallets.
     *
     * @return array{ok: bool, status?: string, message?: string, report?: array<mixed>, log?: string, error?: string}
     */
    public function panel(): array
    {
        // Hundreds of clients over dozens of pages: neither the inline request
        // nor the spawned process must be cut short by max_execution_time, and
        // a dropped connection must not kill the run half way through (the
        // import only ever adds rows).
        set_time_limit(0);
        @ignore_user_abort(true);

        $lines = [];
        $writer = function (string $line) use (&$lines): void {
            if (count($lines) < self::FLASH_LOG_LINES) {
                $lines[] = $line;
            }

            ImportProgress::line($line);
        };

        ImportProgress::start('panel', 'خواندن مشتریان از پنل');

        try {
            // The whole panel, every page: legacy setup.php looped until the panel
            // had no more rows, and the daily sync job after install does the same.
            // The percentage tracks the client count (0 -> 90), wallets close it.
            $clients = app(ClientSyncService::class)->sync($writer, 0, function (array $stats): void {
                $total = (int) ($stats['total'] ?? 0);

                if ($total < 1) {
                    return;
                }

                ImportProgress::update(
                    (int) min(90, floor((int) $stats['processed'] * 90 / $total)),
                    'خواندن مشتریان از پنل',
                    processed: (int) $stats['processed'],
                    total: $total,
                );
            });

            ImportProgress::update(
                95,
                'خواندن کیف پول‌ها از پنل',
                processed: $clients['processed'],
                total: $clients['total'] > 0 ? $clients['total'] : $clients['processed'],
            );

            $wallets = app(WalletSyncService::class)->sync($writer);
        } catch (Throwable $e) {
            Log::error('The installer could not sync from the seller panel.', ['detail' => LogRedaction::mask($e->getMessage())]);
            $this->store->markFailed(SetupStep::Import->value, $this->safe($e));
            ImportProgress::line('error: '.$this->safe($e));
            ImportProgress::fail('خواندن اطلاعات از پنل ناموفق بود.');

            return ['ok' => false, 'error' => 'خواندن اطلاعات از پنل ناموفق بود.'];
        }

        $message = 'خواندن اطلاعات از پنل تمام شد.';
        $report = ['clients' => $clients, 'wallets' => $wallets];
        ImportProgress::finish($message, $report);

        return [
            'ok' => true,
            'status' => 'اطلاعات پنل خوانده شد.',
            'message' => $message,
            'report' => $report,
            'log' => implode(PHP_EOL, $lines),
        ];
    }

    /**
     * A message safe to put in front of an operator and in the log.
     *
     * The driver of a failed database call echoes the username, password or
     * DSN, so anything that looks like a credential is masked first and the
     * rest is reduced to a single short line.
     */
    private function safe(Throwable $e): string
    {
        Log::warning('The installer reported a failed step.', [
            'exception' => $e::class,
            'detail' => LogRedaction::mask($e->getMessage()),
        ]);

        $firstLine = (string) str(LogRedaction::mask($e->getMessage()))->limit(180, '');

        return $firstLine !== '' ? $firstLine : 'ارتباط برقرار نشد.';
    }
}
