<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Migration\LegacySource;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Compare the legacy tables with the tables of this installation.
 *
 * The last step of the import, and the check the operator runs on the copy
 * before pointing the live bot at it. A mismatch is not automatically an
 * error: the import only adds rows that are missing, so the local count can be
 * higher than the legacy one. The command therefore reports the numbers and
 * only fails when a legacy row is missing locally, which is what an operator
 * cannot explain away.
 */
class LegacyVerifyCommand extends Command
{
    protected $signature = 'legacy:verify
                            {--only=* : Limit the check to these tables}';

    protected $description = 'Compare the record counts of the legacy database with this one';

    public function handle(LegacySource $source): int
    {
        if (! $source->configured()) {
            $this->error('The legacy database is not configured.');

            return self::FAILURE;
        }

        // The option names tables, which end up as identifiers in the queries
        // below: only names this application already knows are accepted, the
        // same rule legacy:import applies.
        $only = $this->option('only');
        $tables = $only === [] ? $source->tables() : array_values(array_intersect($only, $source->tables()));

        if ($tables === []) {
            $this->error('None of the given --only tables are tables of this application.');

            return self::FAILURE;
        }

        $rows = [];
        $missing = 0;

        foreach ($tables as $table) {
            try {
                $legacy = $source->count($table);

                if ($legacy === null) {
                    $rows[] = [$table, '-', DB::getSchemaBuilder()->hasTable($table) ? 'yes' : 'no', 'table absent in legacy'];

                    continue;
                }

                if (! DB::getSchemaBuilder()->hasTable($table)) {
                    $missing += $legacy;
                    $rows[] = [$table, $legacy, 'no', 'table missing here'];

                    continue;
                }

                $local = (int) DB::table($table)->count();
                $short = max(0, $legacy - $local);
                $missing += $short;

                $rows[] = [
                    $table,
                    $legacy,
                    $local,
                    $short === 0 ? 'ok' : "{$short} row(s) short",
                ];
            } catch (Throwable $e) {
                $rows[] = [$table, 'error', '-', $e->getMessage()];
                $missing++;
            }
        }

        $this->table(['Table', 'Legacy', 'Local', 'Result'], $rows);

        if ($missing > 0) {
            $this->error("{$missing} legacy row(s) are not present locally. Re-run legacy:import --confirm.");

            return self::FAILURE;
        }

        $this->components->info('Every legacy row is present locally.');

        return self::SUCCESS;
    }
}
