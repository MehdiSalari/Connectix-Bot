<?php

use App\Console\Commands\PruneCommand;
use App\Console\Commands\SyncClientsCommand;
use App\Console\Commands\SyncUserProfilesCommand;
use App\Console\Commands\SyncWalletsCommand;
use App\Services\Features\DbBackupAdminService;
use App\Services\Notifications\NotificationBotService;
use App\Services\Telegram\TelegramService;
use App\Services\Winback\WinbackAdminService;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Scheduled work
|--------------------------------------------------------------------------
|
| Everything here is safe on shared hosting: no queue worker, no daemon, no
| long-lived process. A single cron entry - `* * * * * cd /path/to/connectix &&
| php artisan schedule:run` - is enough, and the schedule works unchanged
| under `schedule:work` for anyone running a proper server.
|
| Legacy had no scheduler at all: `update/` and `functions.php` only did their
| work when a human opened a page, which is why this file is new rather than a
| port.
|
| `withoutOverlapping()` is belt and braces - each command also takes its own
| `Cache::lock()` - so a slow panel call cannot pile up runs on a 1-minute
| tick.
|
*/

Schedule::command(SyncClientsCommand::class, ['--pages' => 20])
    ->dailyAt('03:15')
    ->withoutOverlapping();

Schedule::command(SyncUserProfilesCommand::class, ['--limit' => 100, '--delay' => 900])
    ->hourlyAt(20)
    ->withoutOverlapping();

/*
| The wallet ledger. Legacy imported balances and the whole transaction history
| once, from the installer, and never again. Running it daily before the client
| sync keeps the admin panel's wallet page showing what the panel actually holds
| and backfills history for a bot that was installed without it.
*/
Schedule::command(SyncWalletsCommand::class)
    ->dailyAt('03:00')
    ->withoutOverlapping();

Schedule::command(PruneCommand::class)
    ->hourly()
    ->withoutOverlapping();

/*
|--------------------------------------------------------------------------
| Bot cron jobs (port of the fork's cron/ directory — W4.9)
|--------------------------------------------------------------------------
|
| The fork needed real crontab entries firing its PHP scripts; the rewrite
| runs the same work through this same scheduler with tests around it.
| Cadences match the fork: expiry reminders every 30 min (the fork used
| 30–60), win-back every 30 min capped at 200 recipients per run, and the
| DB backup every 15 min with isDue()'s slot check deciding whether this
| tick actually acts.
|
| FlashSale and WelcomeCredit deliberately have NO cron: both are
| event-driven (banner shown on purchase, gift granted on /start).
| Card-track reporting is admin-page driven in the fork too — there is no
| cron script for it.
|
*/

// یادآوری انقضا/مصرف (fork: cron/check_notifications.php)
Schedule::call(fn () => NotificationBotService::runDue(now('Asia/Tehran')))
    ->name('notifications-due')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// بازگشت مشتری: ارسال پیامهای صف‌شده‌شده در کمپین، سقف ۲۰۰ در هر نوبت
// (fork: cron/winback_cron.php — هر ۳۰ دقیقه)
Schedule::call(function (): void {
    if (! (bool) (WinbackAdminService::getSettings()['is_active'] ?? false)) {
        return;
    }

    WinbackAdminService::processQueued(function (array $payload): bool {
        $response = app(TelegramService::class)->sendMessage(
            (string) $payload['chat_id'],
            (string) $payload['text'],
            ['parse_mode' => 'HTML'],
        );

        return (bool) ($response['ok'] ?? false);
    }, 200);
})->name('winback-queued')
    ->everyThirtyMinutes()
    ->withoutOverlapping();

// بکاپ دیتابیس خودکار (fork: cron/db_backup_cron.php — هر ۱۵ دقیقه)
Schedule::call(fn () => DbBackupAdminService::runDue())
    ->name('db-backup-due')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
