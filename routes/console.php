<?php

use App\Console\Commands\PruneCommand;
use App\Console\Commands\SyncClientsCommand;
use App\Console\Commands\SyncUserProfilesCommand;
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

Schedule::command(PruneCommand::class)
    ->hourly()
    ->withoutOverlapping();
