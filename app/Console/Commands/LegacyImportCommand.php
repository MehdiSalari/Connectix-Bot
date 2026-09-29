<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Migration\LegacyImportService;
use App\Services\Migration\LegacySource;
use Illuminate\Console\Command;
use Throwable;

/**
 * Copy an existing legacy installation into this database.
 *
 * Port of the data half of `setup/setup.php`, kept out of the install flow on
 * purpose: the installer and the importer must be able to fail independently.
 * A reseller who installed yesterday and wants today's legacy rows has a
 * different problem from someone standing up a fresh install.
 *
 * The command never deletes or truncates anything, so re-running it after an
 * interruption converges instead of duplicating, and the only rollback is the
 * backup the operator took beforehand. Writing is opt-in through `--confirm`;
 * without it the command is a dry run and only reports what it would do.
 */
class LegacyImportCommand extends Command
{
    protected $signature = 'legacy:import
                            {--only=* : Limit the run to these tables}
                            {--chunk=500 : Rows read per query}
                            {--dry-run : Read and report without writing}
                            {--confirm : Actually write the rows}';

    protected $description = 'Import the rows of a legacy installation into this database';

    public function handle(LegacyImportService $importer, LegacySource $source): int
    {
        if (! $source->configured()) {
            $this->error('The legacy database is not configured.');
            $this->line('  Set LEGACY_DB_DATABASE, LEGACY_DB_USERNAME and LEGACY_DB_PASSWORD in .env.');

            return self::FAILURE;
        }

        $only = $this->option('only');
        $only = $only === [] ? null : $only;
        $write = $this->option('confirm') && ! $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        try {
            $this->components->info($write
                ? 'Importing the legacy rows. Nothing is deleted or updated.'
                : 'Dry run: no row is written. Add --confirm to import.');

            $report = $importer->import(
                $only,
                ! $write,
                $chunk,
                fn (string $line) => $this->line($line),
            );
        } catch (Throwable $e) {
            $this->error('The import stopped: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->table(
            ['Table', 'Read', 'Written', 'Unchanged', 'Failed'],
            $this->rows($report),
        );

        $failed = array_sum(array_column($report, 'failed'));

        if ($failed > 0) {
            $this->warn("{$failed} row(s) could not be mapped. They are listed in the log and skipped.");
        }

        if (! $write) {
            $this->components->warn('Nothing was written. Re-run with --confirm.');

            return self::SUCCESS;
        }

        $this->components->info('Import finished. Run legacy:verify to compare the record counts.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, array{read: int, written: int, skipped: int, failed: int}>  $report
     * @return array<int, array<int, int|string>>
     */
    private function rows(array $report): array
    {
        $rows = [];

        foreach ($report as $table => $stats) {
            $rows[] = [$table, $stats['read'], $stats['written'], $stats['skipped'], $stats['failed']];
        }

        return $rows;
    }
}
