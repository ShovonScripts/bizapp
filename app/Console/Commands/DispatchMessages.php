<?php

namespace App\Console\Commands;

use App\Messaging\MessageDispatcher;
use Illuminate\Console\Command;

/**
 * Sends whatever is due. Runs every minute.
 *
 * Kept separate from messages:plan so that sending — the part that touches the
 * network and therefore the part that fails — can run often and in small batches
 * without re-scanning every appointment sixty times an hour.
 *
 * --limit exists for the local walkthrough: send one message, look at your phone,
 * decide whether the wording is right before forty more go out.
 */
class DispatchMessages extends Command
{
    protected $signature = 'messages:dispatch {--limit= : How many to send this run}';

    protected $description = 'Send queued messages that are due';

    public function handle(MessageDispatcher $dispatcher): int
    {
        $limit = $this->option('limit');

        $tally = $dispatcher->dispatch($limit !== null ? (int) $limit : null);

        $this->info(sprintf(
            'Dispatched: %d sent, %d retrying, %d failed, %d skipped, %d cancelled, %d held for quiet hours.',
            $tally['sent'],
            $tally['retrying'],
            $tally['failed'],
            $tally['skipped'],
            $tally['cancelled'],
            $tally['deferred'],
        ));

        /*
         * Still SUCCESS when messages failed.
         *
         * A non-zero exit makes cron send mail, and individual send failures are
         * normal traffic — a blocked bot, a wrong number. Exiting non-zero for
         * those trains everyone to ignore the mail, which is exactly what you do
         * not want when something real breaks.
         */
        return self::SUCCESS;
    }
}
