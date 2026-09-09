<?php

namespace App\Support;

use App\Models\Business;
use Illuminate\Support\Carbon;

/**
 * "Is it too late at night to message someone, and if so, when is it not?"
 *
 * One class used by BOTH the planner and the dispatcher, on purpose.
 *
 * The obvious alternative — the planner shifts send_at, the dispatcher checks the
 * clock again — needs the same rule written twice, and we have already been bitten
 * by exactly that pattern: the dashboard once partitioned customers by one rule and
 * printed a reason derived from another, so the number and the explanation
 * disagreed. Two implementations of "quiet hours" would drift the same way, except
 * the symptom would be a customer's phone buzzing at 3am.
 *
 * All arithmetic happens in the business's local time and the answer comes back in
 * UTC, because that is what the database stores.
 */
class QuietHours
{
    /**
     * Would a message sent at this instant land inside the business's quiet window?
     */
    public static function isQuiet(Business $business, Carbon $utc): bool
    {
        [$from, $to] = static::window($business);

        if ($from === null) {
            return false;
        }

        $minute = static::localMinuteOfDay($business, $utc);

        // A window that crosses midnight (21:00 → 08:00, the normal case) is two
        // ranges, not one. Getting this backwards makes the whole night sendable
        // and the whole day quiet, which is the kind of bug that only shows up
        // when a real customer is asleep.
        return $from > $to
            ? ($minute >= $from || $minute < $to)
            : ($minute >= $from && $minute < $to);
    }

    /**
     * The earliest moment at or after $utc that is outside quiet hours.
     *
     * Returns $utc itself (a copy) when it is already fine — callers can use this
     * unconditionally without asking isQuiet() first.
     *
     * Note this only moves messages FORWARD. Pushing a 3am reminder to 8am is
     * helpful; pulling a 3am one back to the previous 9pm would send it before the
     * owner expected it to exist.
     */
    public static function nextAllowed(Business $business, Carbon $utc): Carbon
    {
        if (! static::isQuiet($business, $utc)) {
            return $utc->copy();
        }

        [, $to] = static::window($business);

        $local = $business->toLocal($utc);
        $minute = ($local->hour * 60) + $local->minute;

        $release = $local->copy();

        // Already past the wake-up time means we are in the evening half of a
        // midnight-crossing window, so the next opening is tomorrow morning.
        if ($minute >= $to) {
            $release->addDay();
        }

        // On the March DST morning a local time inside the skipped hour does not
        // exist; PHP resolves it forward, which is the behaviour we want anyway.
        $release->setTime(intdiv($to, 60), $to % 60, 0);

        return $release->utc();
    }

    /**
     * The window as minutes-past-local-midnight, or [null, null] if disabled.
     *
     * from === to means the window has no width, which we read as "no quiet hours"
     * rather than "quiet all day". The second reading would silently stop every
     * message in the system, and a setting that can brick the product by being
     * misunderstood should fail in the harmless direction.
     */
    protected static function window(Business $business): array
    {
        $hours = $business->quietHours();

        $from = static::minuteOfDay($hours['from'] ?? null);
        $to = static::minuteOfDay($hours['to'] ?? null);

        if ($from === null || $to === null || $from === $to) {
            return [null, null];
        }

        return [$from, $to];
    }

    protected static function localMinuteOfDay(Business $business, Carbon $utc): int
    {
        $local = $business->toLocal($utc);

        return ($local->hour * 60) + $local->minute;
    }

    /** "21:00" → 1260. Anything unparseable → null, i.e. treated as not set. */
    protected static function minuteOfDay(?string $time): ?int
    {
        if (! is_string($time) || ! preg_match('/^(\d{1,2}):(\d{2})$/', trim($time), $m)) {
            return null;
        }

        $hour = (int) $m[1];
        $minute = (int) $m[2];

        if ($hour > 23 || $minute > 59) {
            return null;
        }

        return ($hour * 60) + $minute;
    }
}
