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
 */
class ReminderPlanner
{
    public function __construct(protected MessagingManager $messaging) {}

    /**
     * @return array{queued: int, skipped: int, already_planned: int, too_close: int}
     */
    public function plan(): array
    {
        $now = now();
        $offset = (int) config('messaging.reminder.offset_minutes', 1440);

        /*
         * The window is expressed in appointment start times, not send times,
         * because that is the indexed column.
         *
         * It reaches BACKWARDS by catch_up_hours as well as forwards. Shared
         * hosting cron does stop; without the look-back, every reminder that came
         * due during an outage is lost silently and permanently, which is the
         * failure mode an owner would never think to check for.
         */
        $from = $now->copy()
            ->subHours((int) config('messaging.reminder.catch_up_hours', 6))
            ->addMinutes($offset);

        $to = $now->copy()
            ->addHours((int) config('messaging.reminder.plan_horizon_hours', 12))
            ->addMinutes($offset);

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

        $planned = $this->alreadyPlanned($appointments);

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

                $this->write($business, $appointment, $customer, $channel, $sendAt, $reason);

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

    protected function write(
        Business $business,
        Appointment $appointment,
        Customer $customer,
        string $channel,
        Carbon $sendAt,
        ?string $skipReason,
    ): void {
        $variables = $this->variables($business, $appointment, $customer);

        ScheduledMessage::create([
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
        ]);
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
     * Appointment ids that already have a 24h reminder on record.
     *
     * ─── The dedupe rule, in one place ──────────────────────────────────────
     * Any status EXCEPT cancelled blocks a new row.
     *
     *   sent / failed / skipped  — this appointment has had its one reminder, or
     *                              its one decision not to send. Planning again
     *                              would either double-message the customer or
     *                              add an identical skipped row every five
     *                              minutes for the next twelve hours.
     *   cancelled                — the appointment moved, so the old message was
     *                              voided and a fresh one SHOULD be planned for
     *                              the new time. This is the whole reason the
     *                              observer cancels rather than deletes.
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
            ->where('status', '!=', ScheduledMessage::CANCELLED)
            ->pluck('related_id')
            // Cast because related_id is an unsignedBigInteger with no model cast
            // behind it, and PDO hands back strings on MySQL but ints on SQLite.
            // The tests would pass and production would re-plan every reminder.
            ->map(fn ($id) => (int) $id);
    }

    protected function summary(): array
    {
        return ['queued' => 0, 'skipped' => 0, 'already_planned' => 0, 'too_close' => 0];
    }
}
