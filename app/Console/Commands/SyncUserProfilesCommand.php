<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sync\UserProfileSyncService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Refresh the cached name and avatar of known users.
 *
 * Replaces `update/users.php`, which the panel ran as a browser page over every
 * single user. Legacy scraped t.me in a tight loop with no pacing, so a large
 * user table was rate-limited and then killed by `max_execution_time`, leaving
 * the remaining rows stale with no way to tell how far it got.
 *
 * This version takes a budget, paces itself, prints the last user id it
 * reached, and skips a row whose fetch failed instead of aborting the run.
 */
class SyncUserProfilesCommand extends Command
{
    protected $signature = 'connectix:sync-users
                            {--limit=200 : How many users to refresh in this run}
                            {--from=0 : Only users with an id above this one, for resuming}
                            {--delay=700 : Milliseconds to wait between two profile fetches}';

    protected $description = 'Refresh the cached Telegram name and avatar of every known user';

    public function handle(UserProfileSyncService $sync): int
    {
        $lock = Cache::lock('connectix:sync-users', 1800);

        if (! $lock->get()) {
            $this->warn('A user profile sync is already running.');

            return self::FAILURE;
        }

        $limit = max(1, (int) $this->option('limit'));
        $from = max(0, (int) $this->option('from'));
        $delay = max(0, (int) $this->option('delay'));

        try {
            $stats = $sync->sync(fn (string $line) => $this->line($line), $limit, $from, $delay);
        } catch (Throwable $e) {
            $this->error('The sync stopped: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        $this->info(sprintf(
            'Done. %d checked, %d updated, %d skipped.',
            $stats['checked'],
            $stats['updated'],
            $stats['skipped'],
        ));

        if ($stats['checked'] >= $limit) {
            $this->comment('More users may remain. Continue with --from='.$stats['last_id'].'.');
        }

        return self::SUCCESS;
    }
}
