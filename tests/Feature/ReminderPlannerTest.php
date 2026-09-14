<?php

namespace Tests\Feature;

use App\Messaging\MessagingManager;
use App\Messaging\ReminderPlanner;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ScheduledMessage;
use App\Models\Service;
use App\Support\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The planner decides who gets messaged and when. Everything it gets wrong is
 * visible to somebody outside the business: a reminder that never arrives, one
 * that arrives twice, or one that arrives for an appointment that was cancelled.
 *
 * Time is frozen mid-July so British Summer Time is in force. That is not
 * incidental — with BST the local wall clock is an hour ahead of the stored UTC,
 * so any place that compares the two directly produces an answer that is wrong by
 * exactly one hour for half the year. Freezing in winter instead would let those
 * bugs through.
 */
class ReminderPlannerTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;

    protected Customer $sarah;

    protected function setUp(): void
    {
        parent::setUp();

        // Tenant holds static state and PHPUnit shares one process across the
        // whole suite, so a tenant left set by an earlier test would silently
        // scope these queries.
        Tenant::forget();

        Carbon::setTestNow(Carbon::parse('2026-07-15 10:00', 'Europe/London'));

        $this->salon = Business::factory()->create([
            'name' => 'Bright Hair Studio',
            'slug' => 'bright-hair',
            'timezone' => 'Europe/London',
            'phone' => '+441614960000',
        ]);

        $this->sarah = Customer::factory()
            ->forBusiness($this->salon)
            ->telegramLinked()
            ->create(['name' => 'Sarah Khan']);
    }

    protected function tearDown(): void
    {
        Tenant::forget();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------- Helpers ----------------------------- */

    protected function plan(): array
    {
        return (new ReminderPlanner(new MessagingManager))->plan();
    }

    /** Local wall-clock string → the UTC string the database would hold. */
    protected function utc(string $local): string
    {
        return Carbon::parse($local, 'Europe/London')->utc()->toDateTimeString();
    }

    protected function bookingAt(string $local, array $attributes = [], ?Customer $customer = null): Appointment
    {
        $customer ??= $this->sarah;
        $starts = Carbon::parse($local, 'Europe/London');

        $service = Service::factory()
            ->forBusiness($customer->business_id)
            ->create(['name' => 'Signature Cut', 'duration_minutes' => 30, 'price' => 35.00]);

        return Appointment::factory()->create(array_merge([
            'business_id' => $customer->business_id,
            'customer_id' => $customer->id,
            'service_id' => $service->id,
            'starts_at' => $starts->clone()->utc(),
            'ends_at' => $starts->clone()->addMinutes(30)->utc(),
            'status' => Appointment::CONFIRMED,
            'price' => 35.00,
        ], $attributes));
    }

    protected function messages(): Collection
    {
        return ScheduledMessage::query()->get();
    }

    /* -------------------------------- Tests ------------------------------ */

    public function test_a_booking_tomorrow_is_queued_for_a_day_before(): void
    {
        $this->bookingAt('2026-07-16 14:00');

        $tally = $this->plan();

        $this->assertSame(1, $tally['queued']);

        $message = $this->messages()->sole();

        $this->assertSame(ScheduledMessage::PENDING, $message->status);
        $this->assertSame('telegram', $message->channel);
        $this->assertSame(ScheduledMessage::REMINDER_24H, $message->template_key);
        $this->assertSame($this->salon->id, $message->business_id);
        $this->assertSame($this->sarah->id, $message->customer_id);

        $this->assertSame(
            $this->utc('2026-07-15 14:00'),
            $message->send_at->toDateTimeString(),
            'Exactly 24 hours before, in the salon’s own time.'
        );
    }

    /**
     * business_id must be written explicitly by the planner.
     *
     * BelongsToBusiness fills it from the current tenant, and a scheduled run has
     * none — so a planner that relied on the trait would create every row with a
     * null business_id. Those rows are invisible to their owner's queue and
     * visible to everybody else's, which is the worst possible pair of outcomes.
     */
    public function test_the_row_is_not_left_without_a_business(): void
    {
        $this->bookingAt('2026-07-16 14:00');
        $this->plan();

        $this->assertNotNull($this->messages()->sole()->business_id);
    }

    public function test_the_message_says_who_what_and_when(): void
    {
        $this->bookingAt('2026-07-16 09:30');
        $this->plan();

        $body = $this->messages()->sole()->body();

        $this->assertStringContainsString('Sarah Khan', $body);
        $this->assertStringContainsString('Bright Hair Studio', $body);
        $this->assertStringContainsString('Thursday 16 July', $body);
        $this->assertStringContainsString('9:30am', $body);
        $this->assertStringContainsString('Signature Cut', $body);
        $this->assertStringContainsString('+441614960000', $body);
    }

    /**
     * The planner runs every five minutes. If it were not idempotent, a customer
     * would receive one reminder for every run between now and the send time —
     * roughly a hundred and forty of them.
     */
    public function test_running_it_again_does_not_queue_a_second_copy(): void
    {
        $this->bookingAt('2026-07-16 14:00');

        $this->plan();
        $tally = $this->plan();

        $this->assertCount(1, $this->messages());
        $this->assertSame(0, $tally['queued']);
        $this->assertSame(1, $tally['already_planned']);
    }

    public function test_a_cancelled_booking_is_never_planned(): void
    {
        $this->bookingAt('2026-07-16 14:00', ['status' => Appointment::CANCELLED]);

        $this->plan();

        $this->assertCount(0, $this->messages());
    }

    /* --------------------- Reasons we do not send ------------------------ */

    /**
     * A skipped row rather than silence.
     *
     * "Why didn't Sarah get her reminder?" gets asked weeks later, when the
     * dashboard only knows about today. A row with a sentence in it answers that
     * question; no row at all leaves the owner assuming the product is broken.
     */
    public function test_an_unlinked_telegram_customer_is_recorded_as_skipped(): void
    {
        $unlinked = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Tom Reilly',
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => null,
        ]);

        $this->bookingAt('2026-07-16 14:00', [], $unlinked);

        $tally = $this->plan();

        $message = $this->messages()->sole();

        $this->assertSame(ScheduledMessage::SKIPPED, $message->status);
        $this->assertSame('Telegram not linked yet.', $message->error);
        $this->assertSame(1, $tally['skipped']);
        $this->assertSame(0, $tally['queued']);
    }

    public function test_an_unsubscribed_customer_is_not_queued(): void
    {
        $gone = Customer::factory()
            ->forBusiness($this->salon)
            ->telegramLinked()
            ->unsubscribed()
            ->create(['name' => 'Priya Shah']);

        $this->bookingAt('2026-07-16 14:00', [], $gone);
        $this->plan();

        $message = $this->messages()->sole();

        $this->assertSame(ScheduledMessage::SKIPPED, $message->status);

        // An unsubscribe silences appointment reminders too, not just marketing.
        // Transactional messages have a lawful basis without consent, but an
        // explicit "stop" is a decision we do not get to work around.
        $this->assertSame('Asked us to stop.', $message->error);
    }

    public function test_a_customer_with_reminders_switched_off_is_not_queued(): void
    {
        $quiet = Customer::factory()->forBusiness($this->salon)->create([
            'preferred_channel' => 'none',
        ]);

        $this->bookingAt('2026-07-16 14:00', [], $quiet);
        $this->plan();

        $this->assertSame('Reminders turned off.', $this->messages()->sole()->error);
    }

    /**
     * The customer is perfectly reachable; we are the ones who cannot send.
     *
     * Worth a row of its own, and worth different wording — this is the only
     * skip reason the owner cannot fix by editing the customer, and the boss
     * will want to know how often it happens before paying for WhatsApp.
     */
    public function test_a_channel_we_do_not_support_yet_is_recorded_not_ignored(): void
    {
        $smsCustomer = Customer::factory()->forBusiness($this->salon)->create([
            'preferred_channel' => 'sms',
            'phone' => '+447700900301',
        ]);

        $this->bookingAt('2026-07-16 14:00', [], $smsCustomer);
        $this->plan();

        $message = $this->messages()->sole();

        $this->assertSame(ScheduledMessage::SKIPPED, $message->status);
        $this->assertSame('SMS messages are not available yet.', $message->error);
    }

    public function test_whatsapp_channel_supported_but_not_connected_records_actionable_error(): void
    {
        config([
            'messaging.driver' => null,
            'messaging.whatsapp.access_token' => null,
            'messaging.whatsapp.phone_number_id' => null,
        ]);

        $whatsapp = Customer::factory()->forBusiness($this->salon)->create([
            'preferred_channel' => 'whatsapp',
            'whatsapp_number' => '+447700900301',
        ]);

        $this->bookingAt('2026-07-16 14:00', [], $whatsapp);
        $this->plan();

        $message = $this->messages()->sole();

        $this->assertSame(ScheduledMessage::SKIPPED, $message->status);
        $this->assertSame('WhatsApp is not connected for this business yet.', $message->error);
    }

    /* ------------------------------ Timing ------------------------------- */

    /**
     * A reminder due at half past nine at night waits until the morning.
     *
     * The appointment is at 21:30 tomorrow, so 24 hours before is 21:30 tonight —
     * inside the default 21:00–08:00 quiet window.
     */
    public function test_a_reminder_landing_at_night_waits_until_the_morning(): void
    {
        $this->bookingAt('2026-07-16 21:30');
        $this->plan();

        $this->assertSame(
            $this->utc('2026-07-16 08:00'),
            $this->messages()->sole()->send_at->toDateTimeString()
        );
    }

    /**
     * Cron was down; the reminder is overdue. Send it now rather than at a time
     * that has already passed — a send_at in the past would otherwise sit there
     * looking due while the dispatcher happily sent it, which is fine, but the
     * clamp makes the queue honest about when it will actually go.
     */
    public function test_an_overdue_reminder_is_brought_forward_to_now(): void
    {
        // 20 hours away: 24 hours before it was four hours ago.
        $this->bookingAt('2026-07-16 06:00');

        $this->plan();

        $this->assertSame(
            now()->toDateTimeString(),
            $this->messages()->sole()->send_at->toDateTimeString()
        );
    }

    /**
     * Catching up in the middle of the night must not schedule for the middle of
     * the night.
     *
     * `messages:plan` runs round the clock, so the catch-up branch above can fire
     * at 3am — and pulling an overdue reminder up to "now" then means 3am. Nobody
     * would actually be woken (the dispatcher re-checks and defers), but the queue
     * the owner previews would show a time we have no intention of honouring, and
     * every one of those rows burns a dispatcher pass until morning.
     *
     * A day-long catch-up window, because that is what an overnight outage needs.
     */
    public function test_catching_up_at_three_in_the_morning_still_waits_for_daylight(): void
    {
        config()->set('messaging.reminder.catch_up_hours', 24);

        $this->bookingAt('2026-07-16 14:00');

        // The cron came back at 3am, well inside quiet hours.
        Carbon::setTestNow(Carbon::parse('2026-07-16 03:00', 'Europe/London'));

        $this->plan();

        $this->assertSame(
            $this->utc('2026-07-16 08:00'),
            $this->messages()->sole()->send_at->toDateTimeString()
        );
    }

    /**
     * The one case that gets no row at all.
     *
     * A booking made this morning for this afternoon does not need reminding, and
     * "your appointment is tomorrow" arriving ninety minutes beforehand is worse
     * than nothing. This is a non-event rather than a failure, so it is not
     * recorded — otherwise every walk-in would leave a skipped row behind it.
     *
     * The catch-up window is widened so the appointment falls inside the planning
     * range at all; in normal running, a two-day outage is what would expose this.
     */
    public function test_a_booking_that_is_almost_here_is_left_alone(): void
    {
        config()->set('messaging.reminder.catch_up_hours', 48);

        $this->bookingAt('2026-07-15 11:00');   // one hour from now

        $tally = $this->plan();

        $this->assertCount(0, $this->messages());
        $this->assertSame(1, $tally['too_close']);
    }

    /* ---------------------------- Rescheduling --------------------------- */

    /**
     * Moving a booking must produce a new reminder carrying the new time.
     *
     * This is the whole reason the observer marks messages `cancelled` instead of
     * deleting them, and the reason the planner's dedupe ignores that one status.
     * Get it wrong in either direction and the customer is told the wrong day, or
     * told nothing at all.
     */
    public function test_moving_a_booking_replaces_its_reminder(): void
    {
        $appointment = $this->bookingAt('2026-07-16 14:00');
        $this->plan();

        $appointment->update([
            'starts_at' => Carbon::parse('2026-07-16 16:00', 'Europe/London')->utc(),
            'ends_at' => Carbon::parse('2026-07-16 16:30', 'Europe/London')->utc(),
        ]);

        $this->plan();

        $messages = $this->messages();
        $this->assertCount(2, $messages);

        $old = $messages->firstWhere('status', ScheduledMessage::CANCELLED);
        $new = $messages->firstWhere('status', ScheduledMessage::PENDING);

        $this->assertNotNull($old, 'The original reminder should have been voided.');
        $this->assertNotNull($new, 'A replacement should have been planned.');

        $this->assertSame($this->utc('2026-07-15 16:00'), $new->send_at->toDateTimeString());
        $this->assertStringContainsString('4:00pm', $new->body());
    }

    public function test_cancelling_a_booking_voids_its_reminder(): void
    {
        $appointment = $this->bookingAt('2026-07-16 14:00');
        $this->plan();

        $appointment->changeStatus(Appointment::CANCELLED);

        $message = $this->messages()->sole();

        $this->assertSame(ScheduledMessage::CANCELLED, $message->status);

        // And it stays voided — a cancelled appointment is not remindable, so the
        // next planner run must not resurrect it.
        $this->plan();
        $this->assertCount(1, $this->messages());
    }

    /* --------------------- Reviving a skipped reminder -------------------- */

    /**
     * The defect this whole section exists for.
     *
     * A skipped row is not a verdict, it is a note saying "I could not send this
     * yet, and here is why". The owner reads that note, adds the missing detail,
     * and reasonably expects the reminder to go out. Until this was fixed the
     * planner treated the note as the decision: the appointment counted as
     * already-planned for ever, and nothing short of deleting the row by hand
     * would revive it. The customer simply never heard from them.
     *
     * Found by reading the planner during the first live walkthrough rather than
     * by a failing test — which is why it gets six of them now.
     */
    public function test_a_skipped_reminder_is_revived_once_the_customer_can_be_reached(): void
    {
        $tom = Customer::factory()->forBusiness($this->salon)->create([
            'name' => 'Tom Reilly',
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => null,
        ]);

        $this->bookingAt('2026-07-16 14:00', [], $tom);

        $this->plan();

        $skipped = $this->messages()->sole();
        $this->assertSame(ScheduledMessage::SKIPPED, $skipped->status);

        // The owner does exactly what the queue told them to do.
        $tom->forceFill(['telegram_chat_id' => '123456789'])->save();

        $tally = $this->plan();

        $this->assertSame(1, $tally['queued']);
        $this->assertSame(0, $tally['skipped']);

        // sole() is half the assertion here: one row, not a second alongside it.
        $revived = $this->messages()->sole();

        $this->assertSame(
            $skipped->id,
            $revived->id,
            'The existing row should have been revived in place, not replaced.'
        );
        $this->assertSame(ScheduledMessage::PENDING, $revived->status);
        $this->assertNull($revived->error, 'The stale reason must not survive the revival.');
        $this->assertSame($this->utc('2026-07-15 14:00'), $revived->send_at->toDateTimeString());
    }

    /**
     * Why the fix could not simply be "stop treating skipped as planned".
     *
     * The planner runs every five minutes across a twelve hour horizon. A customer
     * who stays unreachable would collect around a hundred and forty identical
     * skipped rows, turning the one screen that is supposed to explain the problem
     * into the thing that buries it.
     */
    public function test_an_unreachable_customer_does_not_accumulate_a_row_every_run(): void
    {
        $tom = Customer::factory()->forBusiness($this->salon)->create([
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => null,
        ]);

        $this->bookingAt('2026-07-16 14:00', [], $tom);

        $this->plan();
        $this->plan();
        $tally = $this->plan();

        $this->assertCount(1, $this->messages());
        $this->assertSame(ScheduledMessage::SKIPPED, $this->messages()->sole()->status);
        $this->assertSame(1, $tally['skipped']);
        $this->assertSame(0, $tally['queued']);
    }

    /**
     * A revived row is re-rendered, never merely re-flagged.
     *
     * The body is written at plan time, so a row skipped while the booking was at
     * two o'clock still says two o'clock. The observer that voids reminders on a
     * reschedule only touches PENDING rows, so a skipped one survives the move
     * untouched — flipping its status without rebuilding it would tell the
     * customer to turn up at the wrong time.
     */
    public function test_a_revived_reminder_carries_the_bookings_current_time(): void
    {
        $tom = Customer::factory()->forBusiness($this->salon)->create([
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => null,
        ]);

        $appointment = $this->bookingAt('2026-07-16 14:00', [], $tom);
        $this->plan();

        $this->assertStringContainsString('2:00pm', $this->messages()->sole()->body());

        $appointment->update([
            'starts_at' => Carbon::parse('2026-07-16 16:00', 'Europe/London')->utc(),
            'ends_at' => Carbon::parse('2026-07-16 16:30', 'Europe/London')->utc(),
        ]);

        $tom->forceFill(['telegram_chat_id' => '123456789'])->save();

        $this->plan();

        $revived = $this->messages()->sole();

        $this->assertStringContainsString('4:00pm', $revived->body());
        $this->assertStringNotContainsString('2:00pm', $revived->body());
        $this->assertSame($this->utc('2026-07-15 16:00'), $revived->send_at->toDateTimeString());
    }

    /**
     * The reason stays current too, even while it stays unsendable.
     *
     * The queue is the owner's answer to "why hasn't this gone out?", so a row
     * still showing last week's obstacle sends them off to fix the wrong thing.
     */
    public function test_a_skipped_row_keeps_its_reason_up_to_date(): void
    {
        $tom = Customer::factory()->forBusiness($this->salon)->create([
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => null,
        ]);

        $this->bookingAt('2026-07-16 14:00', [], $tom);
        $this->plan();

        $this->assertSame('Telegram not linked yet.', $this->messages()->sole()->error);

        // unreachableReason() checks unsubscribed first, so the sentence changes
        // even though the missing chat id is still missing.
        $tom->unsubscribe();

        $this->plan();

        $message = $this->messages()->sole();

        $this->assertSame(ScheduledMessage::SKIPPED, $message->status);
        $this->assertSame('Asked us to stop.', $message->error);
    }

    /**
     * Failed is deliberately NOT revived, and this test is the record of that.
     *
     * Skipped means we never tried. Failed means we did, and the driver told us
     * why not — usually something permanent, like a customer who has blocked the
     * bot. Reviving those automatically would hammer the provider with a request
     * guaranteed to fail, every five minutes, for as long as the booking sits in
     * the window. Retrying transient failures is the dispatcher's job and it
     * already has a backoff for it; clearing a genuinely failed row is a
     * deliberate act by whoever fixed the underlying problem.
     */
    public function test_a_failed_reminder_is_not_revived_automatically(): void
    {
        $this->bookingAt('2026-07-16 14:00');
        $this->plan();

        $this->messages()->sole()->forceFill([
            'status' => ScheduledMessage::FAILED,
            'attempts' => 3,
            'error' => 'This customer has blocked the bot on Telegram.',
        ])->save();

        $tally = $this->plan();

        $this->assertCount(1, $this->messages());
        $this->assertSame(ScheduledMessage::FAILED, $this->messages()->sole()->status);
        $this->assertSame(0, $tally['queued']);
        $this->assertSame(1, $tally['already_planned']);
    }

    /** Loosening the dedupe rule must not open the door to double-messaging. */
    public function test_a_sent_reminder_is_never_planned_again(): void
    {
        $this->bookingAt('2026-07-16 14:00');
        $this->plan();

        $this->messages()->sole()->markSent('tg-1');

        $tally = $this->plan();

        $this->assertCount(1, $this->messages());
        $this->assertSame(ScheduledMessage::SENT, $this->messages()->sole()->status);
        $this->assertSame(1, $tally['already_planned']);
    }

    /**
     * A revived row is a fresh attempt, so the delivery bookkeeping starts over.
     *
     * Leaving `attempts` behind would have the dispatcher reading a retry history
     * belonging to a message it never actually sent, and could exhaust
     * max_attempts before the first real try.
     */
    public function test_reviving_clears_the_delivery_bookkeeping(): void
    {
        $tom = Customer::factory()->forBusiness($this->salon)->create([
            'preferred_channel' => 'telegram',
            'telegram_chat_id' => null,
        ]);

        $this->bookingAt('2026-07-16 14:00', [], $tom);
        $this->plan();

        $this->messages()->sole()->forceFill([
            'attempts' => 2,
            'sent_at' => now()->subDay(),
        ])->save();

        $tom->forceFill(['telegram_chat_id' => '123456789'])->save();

        $this->plan();

        $revived = $this->messages()->sole();

        $this->assertSame(ScheduledMessage::PENDING, $revived->status);
        $this->assertSame(0, $revived->attempts);
        $this->assertNull($revived->sent_at);
        $this->assertNull($revived->error);
    }

    /* --------------------------- Tenant safety --------------------------- */

    /**
     * One run plans for every business, so this is the moment a mix-up would
     * happen: a message written with one salon's business_id and another's
     * customer. In the UK that is a reportable breach, not a bug.
     */
    public function test_two_businesses_are_planned_without_crossing_over(): void
    {
        $gym = Business::factory()->create([
            'name' => 'Iron Works Gym',
            'slug' => 'iron-works',
            'timezone' => 'Europe/London',
        ]);

        $member = Customer::factory()
            ->forBusiness($gym)
            ->telegramLinked()
            ->create(['name' => 'Dan Ellis']);

        $this->bookingAt('2026-07-16 14:00');
        $this->bookingAt('2026-07-16 15:00', [], $member);

        $this->plan();

        $messages = $this->messages();
        $this->assertCount(2, $messages);

        foreach ($messages as $message) {
            $this->assertSame(
                $message->business_id,
                Customer::withoutGlobalScope('business')->find($message->customer_id)->business_id,
                'A message was queued for a customer of a different business.'
            );
        }

        $salonMessage = $messages->firstWhere('business_id', $this->salon->id);
        $gymMessage = $messages->firstWhere('business_id', $gym->id);

        $this->assertStringContainsString('Bright Hair Studio', $salonMessage->body());
        $this->assertStringNotContainsString('Iron Works Gym', $salonMessage->body());
        $this->assertStringContainsString('Dan Ellis', $gymMessage->body());
        $this->assertStringNotContainsString('Sarah Khan', $gymMessage->body());
    }

    /**
     * A business with no phone number on file still gets a usable message.
     *
     * The "call us to change it" line simply disappears rather than trailing off
     * into "Call ." — the one rendering failure a customer would actually see.
     */
    public function test_a_business_without_a_phone_number_still_sends_something_sensible(): void
    {
        $this->salon->forceFill(['phone' => null])->save();

        $this->bookingAt('2026-07-16 14:00');
        $this->plan();

        $body = $this->messages()->sole()->body();

        $this->assertStringNotContainsString('Call', $body);
        $this->assertStringContainsString('Sarah Khan', $body);
        $this->assertSame(trim($body), $body);
    }
}
