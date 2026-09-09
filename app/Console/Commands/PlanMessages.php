<?php

namespace App\Console\Commands;

use App\Messaging\ReminderPlanner;
use Illuminate\Console\Command;

/**
 * Works out which reminders are coming due and writes them to the outbox.
 *
 * Runs every five minutes from the scheduler. Deliberately cheap and idempotent:
 * running it twice in a row produces nothing the second time, which matters
 * because the first thing anyone does when debugging is run it again by hand.
 */
class PlanMessages extends Command
{
    protected $signature = 'messages:plan';

    protected $description = 'Queue appointment reminders that are coming due';

    public function handle(ReminderPlanner $planner): int
    {
        $tally = $planner->plan();

        // One line, key=value, because this ends up in a cron mail or a log file
        // that someone reads on a phone. A table would wrap into nonsense.
        $this->info(sprintf(
            'Planned: %d queued, %d skipped, %d already planned, %d too close to bother.',
            $tally['queued'],
            $tally['skipped'],
            $tally['already_planned'],
            $tally['too_close'],
        ));

        return self::SUCCESS;
    }
}
