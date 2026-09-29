<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\HandledUpdate;
use App\Services\Payment\SmsPaymentService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Housekeeping for the rows a shared-hosting install accumulates.
 *
 * Two of these are legacy cleanups that only ever ran as a side effect of a
 * user action, so an idle install kept them forever:
 *
 *  - `smsPayment()` deleted expired unmatched bank deposits on its next call,
 *    which never arrives when no SMS and no receipt come in. Matched rows are
 *    the audit trail and are kept.
 *  - the update ledger from the webhook deduplication grows with every message,
 *    so rows older than a day are dropped: Telegram only redelivers an update
 *    while it is still queued, and a day of slack is far beyond that.
 *
 * Nothing here is required for the bot to work; it only keeps a small shared
 * database tidy, which is why it is safe to run hourly from cron.
 */
class PruneCommand extends Command
{
    protected $signature = 'connectix:prune
                            {--updates-hours=48 : Drop handled Telegram updates older than this}';

    protected $description = 'Delete expired bank SMS deposits and old handled Telegram updates';

    public function handle(SmsPaymentService $sms): int
    {
        $lock = Cache::lock('connectix:prune', 600);

        if (! $lock->get()) {
            $this->warn('A prune run is already in progress.');

            return self::SUCCESS;
        }

        try {
            $smsRows = $sms->pruneExpired();

            $hours = max(1, (int) $this->option('updates-hours'));
            $updateRows = HandledUpdate::query()
                ->where('handled_at', '<', now()->subHours($hours))
                ->delete();

            $this->info(sprintf('Deleted %d expired bank deposits and %d handled updates.', $smsRows, $updateRows));
        } catch (Throwable $e) {
            $this->error('The prune failed: '.$e->getMessage());

            return self::FAILURE;
        } finally {
            $lock->release();
        }

        return self::SUCCESS;
    }
}
