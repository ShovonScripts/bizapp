<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled tasks
|--------------------------------------------------------------------------
| cPanel fires ONE cron entry every minute:
|
|   * * * * * cd /home/USER/apps/bizapp && /path/to/php artisan schedule:run >> /dev/null 2>&1
|
| Everything below is driven by that single entry.
|
| withoutOverlapping() is not optional. Without it, a slow run can still be
| going when the next minute starts, and the planner can queue the same
| reminder twice.
*/

// Phase 1: proves cron is alive. Keep it — it is the cheapest possible
// monitoring, and the first thing to check when reminders stop going out.
Schedule::command('app:heartbeat')
    ->everyMinute()
    ->withoutOverlapping();

/*
| Phase 5 onwards — commented out until those commands exist.
|
| Schedule::command('messages:plan')->everyFiveMinutes()->withoutOverlapping();
| Schedule::command('messages:dispatch')->everyMinute()->withoutOverlapping();
| Schedule::command('invoices:mark-overdue')->dailyAt('01:00');
| Schedule::command('customers:refresh-stats')->dailyAt('02:00');
| Schedule::command('gdpr:purge-old-data')->weekly();
|
| Shared-hosting queue trick — no long-running daemon needed.
| Starts a worker each minute, which exits as soon as the queue is empty
| or after 50 seconds, whichever comes first:
|
| Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
|     ->everyMinute()
|     ->withoutOverlapping();
*/
