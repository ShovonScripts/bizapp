<?php

namespace Tests\Feature;

use App\Messaging\Contracts\MessageDriver;
use App\Messaging\MessageDispatcher;
use App\Messaging\MessagingManager;
use App\Messaging\SendResult;
use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ScheduledMessage;
use App\Models\Service;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

/**
 * The dispatcher is the last thing that runs before a message reaches a real
 * phone, so it is the last chance to catch a decision that has gone stale.
 *
 * Every test here uses a fake driver. Nothing in this file may touch the network,
 * and nothing in it may depend on a token — phpunit.xml already forces
 * MESSAGING_DRIVER=log and blanks the bot token, but a test that constructs its
 * own manager would walk straight past both, so the fake is the third lock.
 */
class MessageDispatcherTest extends TestCase
{
    use RefreshDatabase;

    protected Business $salon;

    protected Customer $sarah;

    protected Appointment $appointment;

    /** The fake driver the manager below hands out. Inspectable after a run. */
    protected object $driver;

    protected MessagingManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::forget();

        // Two o'clock on a July afternoon: British Summer Time is in force and we
        // are nowhere near quiet hours, so the default case is uncomplicated.
        Carbon::setTestNow(Carbon::parse('2026-07-15 14:00', 'Europe/London'));

        $this->salon = Business::factory()->create([
            'name' => 'Bright Hair Studio',
            'slug' => 'bright-hair',
            'timezone' => 'Europe/London',
        ]);

        $this->sarah = Customer::factory()
            ->forBusiness($this->salon)
            ->telegramLinked()
            ->create(['name' => 'Sarah Khan']);

        $service = Service::factory()->forBusiness($this->salon)->create([
            'name' => 'Signature Cut',
            'duration_minutes' => 30,
        ]);

        $starts = Carbon::parse('2026-07-16 14:00', 'Europe/London');

        $this->appointment = Appointment::factory()->create([
            'business_id' => $this->salon->id,
            'customer_id' => $this->sarah->id,
            'service_id' => $service->id,
            'starts_at' => $starts->clone()->utc(),
            'ends_at' => $starts->clone()->addMinutes(30)->utc(),
            'status' => Appointment::CONFIRMED,
        ]);

        $this->useDriver(SendResult::sent('tg_00001'));
    }

    protected function tearDown(): void
    {
        Tenant::forget();
        Carbon::setTestNow();

        parent::tearDown();
    }

    /* ------------------------------ Fake plumbing ------------------------- */

    protected function useDriver(SendResult $result, bool $configured = true): void
    {
        $this->driver = new class($result, $configured) implements MessageDriver
        {
            /** @var list<array{customer_id: int, body: string}> */
            public array $sent = [];

            public function __construct(
                protected SendResult $result,
                protected bool $configured,
            ) {}

            public function channel(): string
            {
                return 'telegram';
            }

            public function isConfigured(): bool
            {
                return $this->configured;
            }

            public function send(Customer $customer, string $body): SendResult
            {
                $this->sent[] = ['customer_id' => $customer->id, 'body' => $body];

                return $this->result;
            }
        };

        $this->manager = $this->managerReturning($this->driver);
    }

    protected function managerReturning(?MessageDriver $stub): MessagingManager
    {
        return new class($stub) extends MessagingManager
        {
            public function __construct(protected ?MessageDriver $stub) {}

            public function driver(Business $business, string $channel): ?MessageDriver
            {
                return $this->stub;
            }
        };
    }

    protected function dispatch(?int $limit = null): array
    {
        return (new MessageDispatcher($this->manager))->dispatch($limit);
    }

    protected function queue(array $attributes = []): ScheduledMessage
    {
        return ScheduledMessage::factory()
            ->forCustomer($this->sarah)
            ->about($this->appointment)
            ->create(array_merge([
                'payload' => ['body' => 'Hi Sarah Khan, your appointment is tomorrow.'],
            ], $attributes));
    }

    /* --------------------------- The happy path -------------------------- */

    public function test_a_due_message_is_sent(): void
    {
        $message = $this->queue();

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['sent']);
        $this->assertCount(1, $this->driver->sent);

        $message->refresh();

        $this->assertSame(ScheduledMessage::SENT, $message->status);
        $this->assertNotNull($message->sent_at);
        $this->assertSame(1, $message->attempts);
        $this->assertNull($message->error);

        // Kept so a "did this actually arrive?" question can be answered against
        // the provider's own records rather than our word for it.
        $this->assertSame('tg_00001', data_get($message->payload, 'provider_message_id'));
    }

    /**
     * What was planned is what gets sent.
     *
     * The body is rendered at plan time precisely so an owner previewing the queue
     * sees the real text. If the dispatcher re-rendered, a service renamed in the
     * meantime would silently change a message that had already been approved.
     */
    public function test_the_stored_body_is_the_body_that_goes_out(): void
    {
        $this->queue(['payload' => ['body' => 'Exactly these words.']]);

        $this->dispatch();

        $this->assertSame('Exactly these words.', $this->driver->sent[0]['body']);
        $this->assertSame($this->sarah->id, $this->driver->sent[0]['customer_id']);
    }

    /**
     * The bookings screen reads appointments.reminded_at. Leaving it null would
     * mean the page the owner actually looks at disagrees with what was sent.
     */
    public function test_sending_marks_the_appointment_as_reminded(): void
    {
        $this->assertNull($this->appointment->reminded_at);

        $this->queue();
        $this->dispatch();

        $this->assertNotNull($this->appointment->fresh()->reminded_at);
    }

    public function test_a_message_that_is_not_due_yet_is_left_alone(): void
    {
        $message = $this->queue(['send_at' => now()->addHour()]);

        $tally = $this->dispatch();

        $this->assertSame(0, $tally['sent']);
        $this->assertCount(0, $this->driver->sent);
        $this->assertSame(ScheduledMessage::PENDING, $message->fresh()->status);
    }

    public function test_a_sent_message_is_never_picked_up_twice(): void
    {
        $this->queue();

        $this->assertSame(1, $this->dispatch()['sent']);
        $this->assertSame(0, $this->dispatch()['sent']);

        $this->assertCount(1, $this->driver->sent);
    }

    /* ------------------------------- Failure ----------------------------- */

    /**
     * A timeout or a 500 is worth another go — but not immediately. Without the
     * backoff, a message due now would burn all three attempts inside sixty
     * seconds and be marked failed before the provider had finished rebooting.
     */
    public function test_a_retryable_failure_is_tried_again_later(): void
    {
        $this->useDriver(SendResult::retryableFailure('Telegram is rate limiting us.'));

        $message = $this->queue();

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['retrying']);
        $this->assertSame(0, $tally['failed']);

        $message->refresh();

        $this->assertSame(ScheduledMessage::PENDING, $message->status);
        $this->assertSame(1, $message->attempts);
        $this->assertSame('Telegram is rate limiting us.', $message->error);
        $this->assertSame(
            now()->addMinutes(5)->toDateTimeString(),
            $message->send_at->toDateTimeString(),
            'The first retry waits five minutes.'
        );
    }

    public function test_it_stops_retrying_after_the_third_attempt(): void
    {
        $this->useDriver(SendResult::retryableFailure('Still down.'));

        $message = $this->queue(['attempts' => 2]);

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['failed']);
        $this->assertSame(0, $tally['retrying']);

        $message->refresh();

        $this->assertSame(ScheduledMessage::FAILED, $message->status);
        $this->assertSame(3, $message->attempts);
    }

    /**
     * A customer who blocked the bot will still have blocked it in twenty minutes.
     * Retrying hides a problem the owner is the only one who can fix.
     */
    public function test_a_permanent_failure_does_not_burn_three_attempts(): void
    {
        $this->useDriver(SendResult::permanentFailure('This customer has blocked the bot.'));

        $message = $this->queue();

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['failed']);

        $message->refresh();

        $this->assertSame(ScheduledMessage::FAILED, $message->status);
        $this->assertSame(1, $message->attempts, 'One attempt, not three.');
        $this->assertSame('This customer has blocked the bot.', $message->error);
    }

    /**
     * A driver is supposed to return a SendResult rather than throw. If one throws
     * anyway, the other messages in the batch are innocent — and a cron run that
     * dies halfway leaves a queue nobody can reason about.
     */
    public function test_one_driver_explosion_does_not_take_the_batch_down(): void
    {
        $this->driver = new class implements MessageDriver
        {
            public array $sent = [];

            public function channel(): string
            {
                return 'telegram';
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function send(Customer $customer, string $body): SendResult
            {
                throw new RuntimeException('cURL error 28: Operation timed out');
            }
        };

        $this->manager = $this->managerReturning($this->driver);

        $this->queue();
        $this->queue(['send_at' => now()->subMinutes(2)]);

        $tally = $this->dispatch();

        $this->assertSame(2, $tally['retrying'], 'Both messages were handled, not just the first.');
        $this->assertSame(2, ScheduledMessage::query()->pending()->count());
        $this->assertStringContainsString(
            'Operation timed out',
            ScheduledMessage::query()->first()->error
        );
    }

    /* ------------------- Decisions that went stale ------------------------ */

    /**
     * Twelve hours can pass between planning and sending, which is plenty of time
     * for someone to change their mind. Re-checking here is not belt-and-braces;
     * it is the only check that is true at the moment of sending.
     */
    public function test_a_customer_who_unsubscribed_after_planning_is_not_messaged(): void
    {
        $message = $this->queue();

        $this->sarah->unsubscribe();

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['skipped']);
        $this->assertCount(0, $this->driver->sent);

        $message->refresh();

        $this->assertSame(ScheduledMessage::SKIPPED, $message->status);
        $this->assertSame('Asked us to stop.', $message->error);
    }

    public function test_a_customer_erased_after_planning_is_not_messaged(): void
    {
        $message = $this->queue();

        // A GDPR erasure soft-deletes, so the relation comes back null even though
        // the foreign key still points somewhere.
        $this->sarah->delete();

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['cancelled']);
        $this->assertCount(0, $this->driver->sent);
        $this->assertSame(ScheduledMessage::CANCELLED, $message->fresh()->status);
    }

    /**
     * The observer normally voids reminders when a booking is cancelled. This test
     * cancels it behind the observer's back — a bulk update, an import, a direct
     * query — to prove the observer is a convenience and not the thing standing
     * between a customer and a reminder for an appointment that no longer exists.
     */
    public function test_a_booking_cancelled_without_events_is_still_caught_here(): void
    {
        $message = $this->queue();

        Appointment::withoutEvents(function () {
            $this->appointment->forceFill(['status' => Appointment::CANCELLED])->save();
        });

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['cancelled']);
        $this->assertCount(0, $this->driver->sent);

        $message->refresh();

        $this->assertSame(ScheduledMessage::CANCELLED, $message->status);
        $this->assertStringContainsString('cancelled before the reminder went out', $message->error);
    }

    /**
     * A deleted booking is not the same as a cancelled one, and it used to slip
     * through.
     *
     * `related()` is a plain morphTo, so a soft-deleted appointment resolves to
     * null — and "not an Appointment" used to mean "nothing to object to, carry
     * on and send". The observer covers `$appointment->delete()`; a bulk delete
     * fires no model events at all, which is exactly the case below.
     */
    public function test_a_deleted_booking_does_not_still_get_a_reminder(): void
    {
        $message = $this->queue();

        Appointment::query()->whereKey($this->appointment->id)->delete();

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['cancelled']);
        $this->assertCount(0, $this->driver->sent);

        $message->refresh();

        $this->assertSame(ScheduledMessage::CANCELLED, $message->status);
        $this->assertStringContainsString('has been removed', $message->error);
    }

    /**
     * The cron-was-down case.
     *
     * A backlog of "your appointment is tomorrow" messages, now due for
     * appointments starting within the hour. Sending them is worse than silence:
     * the customer is already on their way and the message names the wrong day.
     */
    public function test_a_reminder_that_became_too_close_is_dropped(): void
    {
        $message = $this->queue();

        Appointment::withoutEvents(function () {
            $this->appointment->forceFill([
                'starts_at' => now()->addHour(),
                'ends_at' => now()->addHour()->addMinutes(30),
            ])->save();
        });

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['skipped']);
        $this->assertCount(0, $this->driver->sent);
        $this->assertSame(
            'The appointment was too close by the time this could be sent.',
            $message->fresh()->error
        );
    }

    /* ------------------------------ Timing ------------------------------- */

    /**
     * Deferred, not dropped.
     *
     * The owner may have widened their quiet hours since this was planned. A row
     * that is now due at 10pm should wait until morning — throwing it away costs
     * the customer their reminder over a settings change they made in good faith.
     */
    public function test_a_message_due_during_quiet_hours_waits_for_morning(): void
    {
        $message = $this->queue();

        Carbon::setTestNow(Carbon::parse('2026-07-15 22:00', 'Europe/London'));

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['deferred']);
        $this->assertCount(0, $this->driver->sent);

        $message->refresh();

        $this->assertSame(ScheduledMessage::PENDING, $message->status);
        $this->assertSame(0, $message->attempts, 'Waiting is not a failed attempt.');
        $this->assertSame(
            Carbon::parse('2026-07-16 08:00', 'Europe/London')->utc()->toDateTimeString(),
            $message->send_at->toDateTimeString()
        );
    }

    /* --------------------------- Channel trouble -------------------------- */

    public function test_a_channel_with_no_credentials_is_skipped_with_a_reason(): void
    {
        $this->useDriver(SendResult::sent(), configured: false);

        $message = $this->queue();

        $tally = $this->dispatch();

        $this->assertSame(1, $tally['skipped']);
        $this->assertCount(0, $this->driver->sent);
        $this->assertSame(
            'Telegram is not connected for this business yet.',
            $message->fresh()->error
        );
    }

    public function test_a_channel_with_no_driver_at_all_is_skipped(): void
    {
        $this->manager = $this->managerReturning(null);

        $message = $this->queue();

        $this->assertSame(1, $this->dispatch()['skipped']);
        $this->assertSame(ScheduledMessage::SKIPPED, $message->fresh()->status);
    }

    /* ------------------------------ Batching ----------------------------- */

    public function test_it_sends_no_more_than_the_batch_size(): void
    {
        $this->queue();
        $this->queue();
        $this->queue();

        $tally = $this->dispatch(2);

        $this->assertSame(2, $tally['sent']);
        $this->assertSame(1, ScheduledMessage::query()->pending()->count());
    }

    /**
     * Oldest first, so a backlog drains in the order it built up. Newest-first
     * would leave the messages that have waited longest waiting indefinitely
     * whenever the queue is longer than one batch.
     */
    public function test_the_longest_waiting_message_goes_first(): void
    {
        $recent = $this->queue(['send_at' => now()->subMinute()]);
        $oldest = $this->queue(['send_at' => now()->subHours(3)]);

        $this->dispatch(1);

        $this->assertSame(ScheduledMessage::SENT, $oldest->fresh()->status);
        $this->assertSame(ScheduledMessage::PENDING, $recent->fresh()->status);
    }

    /**
     * One run covers the whole platform. The due scope is deliberately not
     * business-scoped, and with no tenant set the global scope adds no WHERE
     * clause — so a second salon's messages must go out in the same pass.
     */
    public function test_every_business_is_dispatched_in_one_run(): void
    {
        $gym = Business::factory()->create([
            'name' => 'Iron Works Gym',
            'slug' => 'iron-works',
            'timezone' => 'Europe/London',
        ]);

        $member = Customer::factory()->forBusiness($gym)->telegramLinked()->create();

        $this->queue();
        ScheduledMessage::factory()->forCustomer($member)->create([
            'payload' => ['body' => 'Reminder from the gym.'],
        ]);

        $this->assertSame(2, $this->dispatch()['sent']);
        $this->assertCount(2, $this->driver->sent);
    }
}
