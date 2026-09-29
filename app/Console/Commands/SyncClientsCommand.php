<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\ClientSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reconcile local users and clients with the seller panel.
 *
 * Replaces the `update/clients.php` + `update/clients_update.php` pair, which
 * the legacy panel triggered from the browser and which could only run once per
 * click. This is a console command, so shared hosting runs it from cron, and
 * the `Cache::lock()` guard means an overlapping run (a cron tick that arrives
 * while the previous one is still working through a large panel) exits instead
 * of interleaving two upserts on the same rows.
 *
 * Every client is written in its own transaction, exactly as legacy did, so an
 * interrupted run leaves a consistent table and the next run simply continues.
 */
class SyncClientsCommand extends Command
{
    protected $signature = 'connectix:sync-clients
                            {--pages=0 : Stop after this many pages, 0 for all of them}';

    protected $description = 'Sync users and clients from the Connectix seller panel';

    public function handle(ClientSyncService $sync): int
    {
        $lock = Cache::lock('connectix:sync-clients', 3600);

        if (! $lock->get()) {
            $this->warn('Another client sync is already running.');

            return self::FAILURE;
        }

        $pages = max(0, (int) $this->option('pages'));

        $this->info('Syncing clients from the panel. Press Ctrl+C to stop; run it again to continue.');

        try {
            $stats = $sync->sync(fn (string $line) => $this->line($line), $pages);
        } catch (Throwable $e) {
            $this->error('The sync stopped: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            'Done. %d clients processed, %d users created, %d clients created, %d skipped.',
            $stats['processed'],
            $stats['users'],
            $stats['clients'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
