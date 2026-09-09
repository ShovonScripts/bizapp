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

        /*
         * Four zeros looks identical to a broken planner, and running the command
         * by hand is the first thing anyone does when a reminder fails to appear.
         * So when there was genuinely nothing in range, say what the range was —
         * the usual answer is that the appointment is simply not due yet.
         *
         * Only on an all-zero run. Printing it every time would bury the tally in
         * the cron mail that matters.
         */
        if (array_sum($tally) === 0) {
            [$from, $to] = $planner->window();

            $this->line('Nothing was in range. Reminders go out '
                .$this->hours((int) config('messaging.reminder.offset_minutes', 1440))
                .' before an appointment, so this run looked at appointments starting between '
                .$from->format('D j M, H:i').' and '.$to->format('D j M, H:i').' UTC.');

            $this->line('An appointment outside that is not late — it is not due yet.');
        }

        return self::SUCCESS;
    }

    /** 1440 → "24 hours", 90 → "90 minutes". */
    protected function hours(int $minutes): string
    {
        return $minutes % 60 === 0
            ? intdiv($minutes, 60).' hours'
            : $minutes.' minutes';
    }
}
