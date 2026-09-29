<?php

use App\Console\Commands\PruneCommand;
use App\Console\Commands\SyncClientsCommand;
use App\Console\Commands\SyncUserProfilesCommand;
use App\Console\Commands\SyncWalletsCommand;
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
