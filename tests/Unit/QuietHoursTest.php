<?php

namespace Tests\Unit;

use App\Models\Business;
use App\Support\QuietHours;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Quiet hours are the one rule in the messaging system whose failure a customer
 * feels directly: getting it wrong means a phone buzzing at 3am. It is also the
 * rule most likely to break silently, because the arithmetic is in local time,
 * the storage is in UTC, and the UK spends half the year an hour off.
 *
 * No database here on purpose. This is pure arithmetic, and a test that needs a
 * migration to prove 22:00 is after 21:00 is a test nobody will run.
 */
class QuietHoursTest extends TestCase
{
    protected function business(?string $from = null, ?string $to = null): Business
    {
        return new Business([
            'name' => 'Bright Hair Studio',
            'timezone' => 'Europe/London',
            'settings' => $from === null ? [] : [
                'quiet_hours' => ['from' => $from, 'to' => $to],
            ],
        ]);
    }

    /** Local wall-clock time → the UTC instant it corresponds to. */
    protected function at(string $local): Carbon
    {
        return Carbon::parse($local, 'Europe/London')->utc();
    }

    public function test_the_middle_of_the_night_is_quiet(): void
    {
        $business = $this->business();

        $this->assertTrue(QuietHours::isQuiet($business, $this->at('2026-07-15 22:00')));
        $this->assertTrue(QuietHours::isQuiet($business, $this->at('2026-07-16 03:00')));
        $this->assertTrue(QuietHours::isQuiet($business, $this->at('2026-07-16 07:59')));
    }

    public function test_the_working_day_is_not(): void
    {
        $business = $this->business();

        // Both edges, because an off-by-one here is invisible in the middle of
        // the day and obvious at the boundary.
        $this->assertFalse(QuietHours::isQuiet($business, $this->at('2026-07-15 08:00')));
        $this->assertFalse(QuietHours::isQuiet($business, $this->at('2026-07-15 14:30')));
        $this->assertFalse(QuietHours::isQuiet($business, $this->at('2026-07-15 20:59')));
    }

    /**
     * The reason none of this can be done in UTC.
     *
     * 20:30 UTC is 21:30 in London in July and 20:30 in London in January. One is
     * inside the quiet window and one is not, from the same number. A comparison
     * against the stored UTC value would get exactly half the year wrong — and
     * the half it got wrong would change twice a year on its own.
     */
    public function test_the_same_utc_time_is_quiet_in_summer_and_not_in_winter(): void
    {
        $business = $this->business();

        $this->assertTrue(
            QuietHours::isQuiet($business, Carbon::parse('2026-07-15 20:30', 'UTC')),
            'In July, 20:30 UTC is 21:30 in London — inside quiet hours.'
        );

        $this->assertFalse(
            QuietHours::isQuiet($business, Carbon::parse('2026-01-15 20:30', 'UTC')),
            'In January, 20:30 UTC is 20:30 in London — before quiet hours start.'
        );
    }

    public function test_an_evening_message_waits_until_the_morning(): void
    {
        $business = $this->business();

        $allowed = QuietHours::nextAllowed($business, $this->at('2026-07-15 22:00'));

        $this->assertSame(
            $this->at('2026-07-16 08:00')->toDateTimeString(),
            $allowed->toDateTimeString(),
            'A 10pm message should go out at 8am the NEXT day, not the same one.'
        );
    }

    public function test_an_early_hours_message_waits_until_later_that_morning(): void
    {
        $business = $this->business();

        $allowed = QuietHours::nextAllowed($business, $this->at('2026-07-16 03:00'));

        // The other half of the midnight-crossing window. Adding a day here — the
        // classic mistake — would delay it by a full 24 hours.
        $this->assertSame(
            $this->at('2026-07-16 08:00')->toDateTimeString(),
            $allowed->toDateTimeString()
        );
    }

    public function test_a_message_outside_quiet_hours_is_left_exactly_where_it_was(): void
    {
        $business = $this->business();
        $when = $this->at('2026-07-15 14:30');

        $this->assertSame(
            $when->toDateTimeString(),
            QuietHours::nextAllowed($business, $when)->toDateTimeString()
        );
    }

    /**
     * Carbon is mutable, and this class is called in a loop over a batch of
     * messages. If nextAllowed() edited its argument in place, the appointment's
     * own start time would move — and the bug would show up as reminders being
     * skipped for being "too close", nowhere near this file.
     */
    public function test_it_does_not_move_the_time_it_was_given(): void
    {
        $business = $this->business();
        $when = $this->at('2026-07-15 22:00');
        $before = $when->toDateTimeString();

        QuietHours::nextAllowed($business, $when);

        $this->assertSame($before, $when->toDateTimeString());
    }

    public function test_the_answer_comes_back_in_utc(): void
    {
        // Because it is written straight into scheduled_messages.send_at, and a
        // value stored in local time would be an hour early for half the year.
        $allowed = QuietHours::nextAllowed($this->business(), $this->at('2026-07-15 22:00'));

        $this->assertSame('UTC', $allowed->getTimezone()->getName());
    }

    public function test_a_business_can_set_its_own_window(): void
    {
        $business = $this->business('20:00', '09:30');

        $this->assertTrue(QuietHours::isQuiet($business, $this->at('2026-07-15 20:30')));
        $this->assertFalse(QuietHours::isQuiet($business, $this->at('2026-07-15 19:30')));

        $this->assertSame(
            $this->at('2026-07-16 09:30')->toDateTimeString(),
            QuietHours::nextAllowed($business, $this->at('2026-07-15 20:30'))->toDateTimeString()
        );
    }

    /**
     * A window that does not cross midnight still has to work. Nobody is likely
     * to set one, but "quiet during opening hours" is a coherent thing to want,
     * and the two-range logic has to handle it rather than inverting.
     */
    public function test_a_window_inside_a_single_day_works_too(): void
    {
        $business = $this->business('09:00', '17:00');

        $this->assertTrue(QuietHours::isQuiet($business, $this->at('2026-07-15 12:00')));
        $this->assertFalse(QuietHours::isQuiet($business, $this->at('2026-07-15 22:00')));

        $this->assertSame(
            $this->at('2026-07-15 17:00')->toDateTimeString(),
            QuietHours::nextAllowed($business, $this->at('2026-07-15 12:00'))->toDateTimeString()
        );
    }

    /**
     * from === to has no sensible reading, so it is treated as "no quiet hours".
     *
     * The other reading — quiet for a full 24 hours — would stop every message in
     * the system from a single mistyped setting. A configuration mistake should
     * fail in the direction that is easy to notice and harmless.
     */
    public function test_an_empty_window_means_no_quiet_hours_rather_than_silence(): void
    {
        $business = $this->business('21:00', '21:00');

        $this->assertFalse(QuietHours::isQuiet($business, $this->at('2026-07-16 03:00')));
    }

    public function test_nonsense_settings_are_ignored_rather_than_obeyed(): void
    {
        // Same principle: a broken value must not silently mute the product.
        $business = $this->business('not a time', '08:00');

        $this->assertFalse(QuietHours::isQuiet($business, $this->at('2026-07-16 03:00')));
    }
}
