<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 1 milestone proof.
 *
 * The whole product depends on cPanel's cron firing `artisan schedule:run`
 * every minute. If that does not work, the automation engine has no
 * foundation — so we prove it on day one rather than discovering it in week five.
 *
 * Run manually:   php artisan app:heartbeat
 * Then check:     storage/app/private/heartbeat.log   (Laravel 11)
 *                 or the /heartbeat?token=... route in the browser.
 */
class CronHeartbeat extends Command
{
    protected $signature = 'app:heartbeat';

    protected $description = 'Append a timestamp line to prove the scheduler is being fired by cron.';

    public function handle(): int
    {
        $utc = now()->toDateTimeString();
        $london = now()->setTimezone('Europe/London')->toDateTimeString();

        $line = sprintf(
            '%s UTC | %s Europe/London | PHP %s | sapi=%s',
            $utc,
            $london,
            PHP_VERSION,
            PHP_SAPI
        );

        // Storage::append() creates the file if it does not exist.
        Storage::disk('local')->append('heartbeat.log', $line);

        $this->info($line);

        return self::SUCCESS;
    }
}
