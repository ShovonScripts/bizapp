<?php

namespace App\Messaging;

use App\Models\Appointment;
use App\Models\Business;
use App\Models\Customer;
use App\Models\ScheduledMessage;
use App\Support\QuietHours;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Decides which appointments get a reminder, and writes the outbox rows.
 *
 * ─── Why a rolling window instead of queueing at booking time ───────────────
 * A reminder created the moment a booking is made has to be found and rewritten
 * every time the customer moves their slot, changes channel, or unsubscribes —
 * three chances to leave a stale message queued for a week. Planning a few hours
 * ahead means the row is built from what is true almost immediately before it is
 * needed. The observer that cancels on reschedule is then a safety net rather than
 * the load-bearing part.
 *
 * ─── Why rows are written for messages we are NOT going to send ─────────────
 * "Why didn't Sarah get her reminder?" is a question an owner will ask weeks
 * later, when the dashboard only shows what is true today. A `skipped` row with a
 * sentence in it answers that; silence does not. The one exception is the
 * too-close-to-bother case below, which is a non-event rather than a failure.
 *
 * ─── A skipped row is a note, not a verdict ─────────────────────────────────
 * The owner reads "Telegram not linked yet", goes and links the customer, and
 * reasonably expects the reminder to go out. So every run re-examines its own
 * skipped rows and rewrites them where they stand: revived to `pending` once the
 * obstacle is gone, or left skipped with a freshly worded reason if it is not.
 * Updating in place rather than inserting is what stops one unreachable customer
 * collecting an identical row per run across a twelve hour horizon.
 */
class ReminderPlanner
{
    public function __construct(protected MessagingManager $messaging) {}

    /**
     * The range of appointment START times this planner will consider.
     *
     * Exposed so `messages:plan` can explain a run that found nothing. "Planned:
     * 0 queued" is indistinguishable from a broken planner, and the first thing
     * anyone does when a reminder does not appear is run the command by hand and
     * stare at the zeros.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function window(?Carbon $now = null): array
    {
        $now ??= now();
        $offset = (int) config('messaging.reminder.offset_minutes', 1440);

        return [
            /*
             * It reaches BACKWARDS by catch_up_hours as well as forwards. Shared
             * hosting cron does stop; without the look-back, every reminder that
             * came due during an outage is lost silently and permanently, which
             * is the failure mode an owner would never think to check for.
             */
            $now->copy()
                ->subHours((int) config('messaging.reminder.catch_up_hours', 6))
                ->addMinutes($offset),

            $now->copy()
                ->addHours((int) config('messaging.reminder.plan_horizon_hours', 12))
                ->addMinutes($offset),
        ];
    }

    /**
     * @return array{queued: int, skipped: int, already_planned: int, too_close: int}
     */
    public function plan(): array
    {
        $now = now();
        $offset = (int) config('messaging.reminder.offset_minutes', 1440);

        /*
         * The window is expressed in appointment start times, not send times,
         * because that is the indexed column. See window() above for why it
         * reaches backwards as well as forwards.
         */
        [$from, $to] = $this->window($now);

        /*
         * No withoutGlobalScope() here, deliberately.
         *
         * From the scheduler there is no current tenant, so the scope adds no
         * WHERE clause and every business is planned. From a web request there IS
         * one, and scoping to it is exactly right — a "plan now" button must not
         * quietly process the whole platform.
         */
        $appointments = Appointment::query()
            ->remindable()
            ->whereBetween('starts_at', [$from, $to])
            ->with(['customer', 'service', 'business'])
            ->orderBy('starts_at')
            ->get();

        if ($appointments->isEmpty()) {
            return $this->summary();
        }

        // Two lookups, two queries, both outside the loop. Between them they answer
        // "leave this appointment alone" and "rewrite this row rather than adding
        // one" for every appointment in the window at once.
        $planned = $this->alreadyPlanned($appointments);
        $revivable = $this->revivableSkipped($appointments);

        $tally = $this->summary();

        // Grouped so the driver is resolved once per business rather than once
        // per appointment — the difference between one query and four hundred on
        // a busy morning.
        foreach ($appointments->groupBy('business_id') as $forBusiness) {
            $business = $forBusiness->first()->business;

            if (! $business) {
                continue;
            }

            $channelChecked = [];

            foreach ($forBusiness as $appointment) {
                if ($planned->contains($appointment->id)) {
                    $tally['already_planned']++;

                    continue;
                }

                $customer = $appointment->customer;

                /*
                 * A GDPR erasure soft-deletes the customer, so the relation comes
                 * back null. There is no row to write — scheduled_messages.
                 * customer_id is not nullable, and inventing one would recreate
                 * the personal data we were asked to remove.
                 */
                if (! $customer) {
                    continue;
                }

                $sendAt = $this->sendAtFor($business, $appointment, $offset, $now);

                if ($sendAt === null) {
                    $tally['too_close']++;

                    continue;
                }

                $channel = $customer->preferred_channel;

                // unreachableSentence() covers consent and contact route; the
                // manager covers "we can't send on that channel at all". Both
                // produce a sentence the owner can act on, and both are written
                // once — in the model and in the manager — so the queue and the
                // dashboard cannot end up explaining the same fact differently.
                $reason = $customer->unreachableSentence();

                if ($reason === null) {
                    // array_key_exists, not ??=, because the answer we most want
                    // to cache is null ("this channel is fine") and ??= treats
                    // null as absent — the connection lookup would then run once
                    // per appointment instead of once per channel.
                    if (! array_key_exists($channel, $channelChecked)) {
                        $channelChecked[$channel] = $this->messaging
                            ->unavailableReason($business, $channel);
                    }

                    $reason = $channelChecked[$channel];
                }

                $this->write(
                    $business,
                    $appointment,
                    $customer,
                    $channel,
                    $sendAt,
                    $reason,
                    // Null on a first-time plan; the appointment's existing skipped row
                    // when this run is re-examining a note it left earlier.
                    $revivable->get($appointment->id),
                );

                $reason === null ? $tally['queued']++ : $tally['skipped']++;
            }
        }

        return $tally;
    }

    /**
     * When should this reminder go out? Null means "don't bother".
     *
     * Three things happen here, in this order:
     *   1. offset back from the appointment,
     *   2. pushed out of quiet hours if it landed at 3am,
     *   3. pulled up to now if it is already overdue (the catch-up case).
     *
     * Then the sanity check: a "reminder" that arrives ninety minutes before the
     * appointment is not a reminder, it is a surprise. Somebody who booked this
     * morning for this afternoon does not need one, and sending it anyway makes
     * the product look broken to the owner watching their own phone.
     */
    protected function sendAtFor(Business $business, Appointment $appointment, int $offset, Carbon $now): ?Carbon
    {
        $sendAt = $appointment->starts_at->copy()->subMinutes($offset);

        $sendAt = QuietHours::nextAllowed($business, $sendAt);

        if ($sendAt->lt($now)) {
            /*
             * Quiet hours are applied a second time, not skipped.
             *
             * `messages:plan` runs round the clock, so a catch-up run at 3am would
             * otherwise clamp straight back to 3am and undo the line above. Nobody
             * would actually be messaged — the dispatcher checks again — but the
             * queue the owner previews would show a send time we have no intention
             * of honouring, and every one of those rows burns a dispatcher pass.
             */
            $sendAt = QuietHours::nextAllowed($business, $now->copy());
        }

        $minNotice = (int) config('messaging.reminder.min_notice_minutes', 120);

        // Written as a date comparison rather than diffInMinutes(): Carbon 2 and
        // Carbon 3 disagree about whether a diff is signed by default, and a sign
        // flip here would silently cancel every reminder in the system.
        if ($sendAt->copy()->addMinutes($minNotice)->gt($appointment->starts_at)) {
            return null;
        }

        return $sendAt;
    }

    /**
     * Create the outbox row — or bring the appointment's existing skipped row back
     * up to date, which is the same job a later run in the same day is doing.
     *
     * Everything is rewritten on a revival, not just the status. The body was
     * rendered from the booking as it stood when we skipped it, and the observer
     * that voids reminders on a reschedule only touches PENDING rows — so a skipped
     * row can be sitting there quoting a time the customer is no longer booked for.
     * Flipping its status without rebuilding it would send them to the salon at the
     * wrong hour.
     */
    protected function write(
        Business $business,
        Appointment $appointment,
        Customer $customer,
        string $channel,
        Carbon $sendAt,
        ?string $skipReason,
        ?ScheduledMessage $existing = null,
    ): void {
        $variables = $this->variables($business, $appointment, $customer);

        $attributes = [
            // Set explicitly. BelongsToBusiness only fills business_id from the
            // current tenant, and in a scheduled run there isn't one — every row
            // would land with a null business_id and become invisible to its owner.
            'business_id' => $business->id,
            'customer_id' => $customer->id,
            'channel' => $channel,
            'template_key' => ScheduledMessage::REMINDER_24H,
            'payload' => [
                'body' => TemplateRenderer::render(ScheduledMessage::REMINDER_24H, $variables),
                'variables' => $variables,
            ],
            'send_at' => $sendAt,
            'status' => $skipReason === null
                ? ScheduledMessage::PENDING
                : ScheduledMessage::SKIPPED,
            'error' => $skipReason,
            'related_type' => $appointment->getMorphClass(),
            'related_id' => $appointment->id,
        ];

        if ($existing === null) {
            ScheduledMessage::create($attributes);

            return;
        }

        /*
         * attempts and sent_at are reset because a revived row is a first attempt, not
         * the continuation of one. Leaving a count behind would have the dispatcher
         * reading a retry history belonging to a send that never happened, and could
         * burn through max_attempts before the first real try.
         *
         * forceFill rather than update(): every column is being rebuilt here, and a
         * later change to $fillable should not be able to silently drop one.
         */
        $existing->forceFill($attributes + [
            'attempts' => 0,
            'sent_at' => null,
        ])->save();
    }

    /**
     * The body is rendered even for a skipped message, on purpose: it costs
     * nothing and it lets an owner see exactly what would have gone out, which is
     * usually the thing that convinces them to go and fix the contact details.
     *
     * @return array<string, string|null>
     */
    protected function variables(Business $business, Appointment $appointment, Customer $customer): array
    {
        $local = $business->toLocal($appointment->starts_at);

        return [
            'customer_name' => $customer->name,
            'business_name' => $business->name,

            // Local time, always. Formatted the way a UK customer reads it —
            // "Thursday 16 July", "9:30am" — not ISO.
            'appointment_date' => $local?->format('l j F'),
            'appointment_time' => $local?->format('g:ia'),

            // Nullable on both sides: the service may have been removed, and a
            // business may never have entered a phone number. The renderer drops
            // whichever line loses its variable.
            'service_name' => $appointment->service?->name,
            'business_phone' => $business->phone,
        ];
    }

    /**
     * Appointment ids whose reminder is settled — leave these completely alone.
     *
     * ─── The dedupe rule, in one place ──────────────────────────────────────
     * A `pending`, `sent` or `failed` row blocks: this appointment has had its one
     * reminder, or has one waiting to go out, and planning again would double-
     * message the customer.
     *
     * `cancelled` does not block. The appointment moved, the old message was voided
     * and a fresh one SHOULD be planned for the new time — the whole reason the
     * observer cancels rather than deletes.
     *
     * `skipped` does not block either, and does not produce a second row:
     * revivableSkipped() hands the existing row to write(), which rewrites it where
     * it stands. So the appointment is reconsidered on every run without the queue
     * filling up with copies of the same note.
     *
     * `failed` sitting on the blocking side is a decision, not an oversight.
     * Skipped means we never tried; failed means we did, and the driver told us why
     * not — usually something permanent, like a customer who has blocked the bot.
     * Reviving those automatically would fire a doomed request at the provider
     * every five minutes for as long as the booking sits in the window. Retrying
     * the transient ones is the dispatcher's job and it already has a backoff for
     * it; clearing a genuinely failed row is a deliberate act by whoever fixed the
     * underlying problem.
     *
     * This cannot be a unique index. MySQL has no partial one, and a plain unique
     * index would also block the legitimate repeats other templates will need
     * (weekly invoice chasing, a second win-back cycle a year later).
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, int>
     */
    protected function alreadyPlanned(Collection $appointments): Collection
    {
        return ScheduledMessage::query()
            ->where('related_type', (new Appointment)->getMorphClass())
            ->whereIn('related_id', $appointments->pluck('id'))
            ->where('template_key', ScheduledMessage::REMINDER_24H)
            ->whereNotIn('status', [ScheduledMessage::CANCELLED, ScheduledMessage::SKIPPED])
            ->pluck('related_id')
            // Cast because related_id is an unsignedBigInteger with no model cast
            // behind it, and PDO hands back strings on MySQL but ints on SQLite.
            // The tests would pass and production would re-plan every reminder.
            ->map(fn ($id) => (int) $id);
    }

    /**
     * The skipped rows this run may rewrite, keyed by appointment id.
     *
     * One query for the whole window rather than a lookup per appointment: a salon
     * coming back from an outage can have hundreds of these at once, and the
     * planner runs every five minutes all day.
     *
     * A row only reaches write() through here if alreadyPlanned() did not claim the
     * appointment first, so a skipped row sitting beside a sent or failed one is
     * never consulted. Settled beats unsettled, and that ordering is what keeps a
     * customer from being messaged twice.
     *
     * @param  Collection<int, Appointment>  $appointments
     * @return Collection<int, ScheduledMessage>
     */
    protected function revivableSkipped(Collection $appointments): Collection
    {
        return ScheduledMessage::query()
            ->where('related_type', (new Appointment)->getMorphClass())
            ->whereIn('related_id', $appointments->pluck('id'))
            ->where('template_key', ScheduledMessage::REMINDER_24H)
            ->where('status', ScheduledMessage::SKIPPED)
            // Oldest first so keyBy() leaves the newest one in place. Nothing writes
            // two skipped rows for one appointment now, but rows created before this
            // method existed can already be sitting in the table.
            ->orderBy('id')
            ->get()
            // Same string/int split as above, and it matters more here: the key is
            // looked up with an appointment id, not compared loosely.
            ->keyBy(fn (ScheduledMessage $message) => (int) $message->related_id);
    }

    protected function summary(): array
    {
        return ['queued' => 0, 'skipped' => 0, 'already_planned' => 0, 'too_close' => 0];
    }
}
