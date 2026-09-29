<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\WalletSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Reconcile local wallets and their transaction history with the panel.
 *
 * Port of the wallet half of legacy `setup/setup.php`. Legacy ran it once during
 * installation, so a bot that was installed before this command existed, or one
 * whose panel balances changed out of band, had no way to catch up: the panel
 * was the only place the history lived and the local ledger only ever grew
 * through the bot's own purchases and deposits.
 *
 * The command takes the same lock as the other sync commands, because the panel
 * list is walked wallet by wallet and two overlapping runs would write the same
 * history twice.
 */
class SyncWalletsCommand extends Command
{
    protected $signature = 'connectix:sync-wallets';

    protected $description = 'Sync wallets and wallet transactions from the Connectix seller panel';

    public function handle(WalletSyncService $sync): int
    {
        $lock = Cache::lock('connectix:sync-wallets', 3600);

        if (! $lock->get()) {
            $this->warn('Another wallet sync is already running.');

            return self::FAILURE;
        }

        $this->info('Syncing wallets from the panel. Press Ctrl+C to stop; run it again to continue.');

        try {
            $stats = $sync->sync(fn (string $line) => $this->line($line));
        } catch (Throwable $e) {
            $this->error('The sync stopped: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            'Done. %d wallets (%d created, %d updated), %d transactions, %d skipped.',
            $stats['wallets'],
            $stats['created'],
            $stats['updated'],
            $stats['transactions'],
            $stats['skipped'],
        ));

        return self::SUCCESS;
    }
}
