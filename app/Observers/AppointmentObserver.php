<?php

namespace App\Observers;

use App\Models\Appointment;
use App\Models\ScheduledMessage;

/**
 * Keeps queued reminders honest when a booking changes underneath them.
 *
 * ─── A safety net, not the mechanism ────────────────────────────────────────
 * The dispatcher re-checks every appointment at send time anyway, so nothing here
 * is load-bearing: if an event is ever missed — a bulk update, a raw query, an
 * import — the worst case is a message cancelled slightly later rather than one
 * that reaches a customer about an appointment they cancelled.
 *
 * What this buys is honesty in the meantime. Between a cancellation and the next
 * dispatch run, the owner's queue would otherwise show a reminder still due for a
 * booking that no longer exists, and they would reasonably believe it was going
 * to be sent.
 *
 * ─── Cancelled, never deleted ───────────────────────────────────────────────
 * The planner treats `cancelled` as "this may be planned again" and every other
 * status as "already dealt with". That distinction is what makes a reschedule
 * work: the old message is voided, and the next planner run builds a fresh one
 * for the new time. Deleting the row instead would work too, right up until
 * someone asks why a customer was messaged twice and there is nothing to look at.
 */
class AppointmentObserver
{
    public function creating(Appointment $appointment): void
    {
        if (blank($appointment->cancellation_token)) {
            $appointment->cancellation_token = \Illuminate\Support\Str::random(64);
        }
    }

    public function updated(Appointment $appointment): void
    {
        // A booking that moved needs a new reminder with the new time in it, not
        // an edit of the old one — the body was rendered at plan time, so
        // rewriting it in place would leave the preview and the message disagreeing.
        if ($appointment->wasChanged('starts_at')) {
            $this->void($appointment, 'The appointment was moved, so this was replaced.');

            return;
        }

        if ($appointment->wasChanged('status')
            && ! in_array($appointment->status, Appointment::REMINDABLE, true)) {
            $this->void($appointment, 'The appointment was '.$appointment->statusLabel().'.');
        }
    }

    /** Soft delete. The booking is gone from every screen, so the reminder is void. */
    public function deleted(Appointment $appointment): void
    {
        $this->void($appointment, 'The appointment was removed.');
    }

    /**
     * Only PENDING rows are touched.
     *
     * A message already sent cannot be unsent, and rewriting its status to
     * `cancelled` would both lose that fact and — because the planner re-plans
     * cancelled ones — send the customer a second reminder.
     */
    protected function void(Appointment $appointment, string $reason): void
    {
        ScheduledMessage::query()
            // Unscoped by necessity: this fires from the scheduler as well as
            // from a logged-in request, and the global scope adds no WHERE clause
            // when there is no tenant. Matching on the appointment itself is the
            // real constraint, and an appointment belongs to exactly one business.
            ->withoutGlobalScope('business')
            ->where('related_type', $appointment->getMorphClass())
            ->where('related_id', $appointment->id)
            ->pending()
            ->get()
            ->each(fn (ScheduledMessage $message) => $message->cancel($reason));
    }
}
