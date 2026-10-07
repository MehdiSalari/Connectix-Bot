<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Setup\ImportProgress;
use App\Services\Setup\ImportSpawner;
use App\Services\Setup\SetupImporter;
use Illuminate\Console\Command;

/**
 * Run a wizard import out of the web request.
 *
 * Spawned by ImportSpawner the moment the operator presses an import button
 * under the single-threaded dev server, so the HTTP request can return at
 * once and the tab's progress polls get answered while the import runs. The
 * payload file it reads is written by the controller; it is consumed on the
 * first line of business, so a crashed run never leaves credentials behind.
 *
 * All reporting goes through the shared progress file — the same hand-off
 * legacy setup_progress.php used — so whichever tab is watching sees percent,
 * phase, log lines and the closing report exactly as an inline run shows them.
 */
class SetupImportCommand extends Command
{
    protected $signature = 'setup:import {--payload= : Path of the payload file the wizard wrote}';

    protected $description = 'Run a setup data import outside of the web request';

    public function handle(SetupImporter $importer): int
    {
        $path = $this->option('payload') ?: ImportSpawner::payloadPath();
        $raw = @file_get_contents($path);
        $payload = $raw !== false ? json_decode($raw, true) : null;

        if (! is_array($payload)) {
            ImportProgress::fail('درخواست ایمپورت پیدا نشد؛ دوباره از صفحهٔ انتقال شروع کنید.');
            $this->error('No import payload at '.$path);

            return self::FAILURE;
        }

        @unlink($path);

        $action = (string) ($payload['action'] ?? 'panel');
        $inputs = is_array($payload['inputs'] ?? null) ? $payload['inputs'] : [];
        $this->line('Starting the '.$action.' import.');

        $result = $action === 'legacy'
            ? $importer->legacy($inputs)
            : $importer->panel();

        if (! ($result['ok'] ?? false)) {
            $this->error((string) ($result['error'] ?? 'The import failed.'));

            return self::FAILURE;
        }

        $this->info((string) ($result['message'] ?? 'The import finished.'));

        return self::SUCCESS;
    }
}
