<?php

namespace App\Messaging;

use App\Models\Appointment;
use App\Models\ScheduledMessage;
use App\Support\QuietHours;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Takes due rows out of the outbox and hands them to a driver.
 *
 * ─── Everything is checked again here ───────────────────────────────────────
 * The planner already decided this message should go. That decision could be
 * twelve hours old by now: the appointment may have been cancelled, the customer
 * may have unsubscribed, the owner may have changed their quiet hours. Re-checking
 * at send time is not belt-and-braces, it is the only check that is actually true
 * at the moment of sending.
 *
 * It also means the Appointment observer is a convenience rather than a
 * dependency. If an event is ever missed — a bulk status update, a direct query,
 * an import — the worst case is a message that gets cancelled here instead of
 * earlier, not one that reaches a customer about an appointment they cancelled.
 */
class MessageDispatcher
{
    public function __construct(protected MessagingManager $messaging) {}

    /**
     * @return array{sent: int, failed: int, retrying: int, skipped: int, cancelled: int, deferred: int}
     */
    public function dispatch(?int $limit = null): array
    {
        $tally = [
            'sent' => 0, 'failed' => 0, 'retrying' => 0,
            'skipped' => 0, 'cancelled' => 0, 'deferred' => 0,
        ];

        $batch = ScheduledMessage::query()
            ->due()
            ->with(['customer', 'business', 'related'])
            // Oldest first, so a backlog drains in the order it built up rather
            // than starving the messages that have been waiting longest.
            ->orderBy('send_at')
            // Not `?? config(...)`: a --limit of 0 (or an unparseable one, which
            // casts to 0) would become LIMIT 0 and report "0 sent" with no hint
            // as to why nothing moved.
            ->limit($limit > 0 ? $limit : (int) config('messaging.batch_size', 50))
            ->get();

        foreach ($batch as $message) {
            $outcome = $this->handle($message);
            $tally[$outcome]++;
        }

        return $tally;
    }

    /** @return 'sent'|'failed'|'retrying'|'skipped'|'cancelled'|'deferred' */
    protected function handle(ScheduledMessage $message): string
    {
        $business = $message->business;
        $customer = $message->customer;

        if (! $business) {
            $message->giveUp('The business this message belonged to no longer exists.');

            return 'failed';
        }

        // Soft-deleted between planning and sending, so the relation resolves to
        // null even though the foreign key still points somewhere. A real GDPR
        // erasure hard-deletes and the FK cascade takes this row with it, so that
        // case never reaches here at all.
        if (! $customer) {
            $message->cancel('This customer\'s record has been removed.');

            return 'cancelled';
        }

        if ($reason = $customer->unreachableSentence()) {
            $message->skip($reason);

            return 'skipped';
        }

        if ($verdict = $this->relatedRecordVerdict($message)) {
            [$status, $reason] = $verdict;

            $status === 'cancelled'
                ? $message->cancel($reason)
                : $message->skip($reason);

            return $status;
        }

        /*
         * Quiet hours are re-applied, not re-judged.
         *
         * The owner may have widened the window since this was planned, and a row
         * that is due at 22:05 under the new setting should wait until morning,
         * not be thrown away. Pushing send_at forward costs one extra pass; the
         * alternative costs the customer a reminder.
         */
        if (QuietHours::isQuiet($business, now())) {
            $message->forceFill([
                'send_at' => QuietHours::nextAllowed($business, now()),
            ])->save();

            return 'deferred';
        }

        $driver = $this->messaging->driver($business, $message->channel);

        if (! $driver || ! $driver->isConfigured()) {
            $message->skip(
                $this->messaging->unavailableReason($business, $message->channel)
                    ?? 'This channel is not available.'
            );

            return 'skipped';
        }

        try {
            $result = $driver->send($customer, $message->body());
        } catch (Throwable $e) {
            /*
             * A driver should return a SendResult, not throw. If one does throw,
             * the batch must not die with it — the other forty-nine messages are
             * innocent, and a cron run that aborts halfway leaves the queue in a
             * state nobody can reason about.
             */
            Log::error('[messaging] driver threw', [
                'scheduled_message_id' => $message->id,
                'channel' => $message->channel,
                'exception' => $e->getMessage(),
            ]);

            $message->recordRetryableFailure('Unexpected error while sending: '.$e->getMessage());

            return $message->isPending() ? 'retrying' : 'failed';
        }

        if ($result->sent) {
            $message->markSent($result->providerMessageId);
            $this->stampAppointment($message);

            return 'sent';
        }

        if ($result->retryable) {
            $message->recordRetryableFailure($result->error ?? 'Sending failed.');

            return $message->isPending() ? 'retrying' : 'failed';
        }

        $message->giveUp($result->error ?? 'Sending failed.');

        return 'failed';
    }

    /**
     * Is the thing this message is about still in a state worth messaging about?
     *
     * @return array{0: 'cancelled'|'skipped', 1: string}|null
     */
    protected function relatedRecordVerdict(ScheduledMessage $message): ?array
    {
        $related = $message->related;

        /*
         * The record is gone.
         *
         * `related()` is a plain morphTo, so Appointment's soft-delete scope
         * applies and a deleted booking resolves to null. Falling through to
         * "nothing to object to" would send "your appointment is tomorrow" for a
         * booking that no longer exists — the exact failure the re-checking in
         * this class is here to prevent. The observer catches the ordinary
         * $appointment->delete(); it cannot catch a bulk delete, which fires no
         * model events at all.
         *
         * Guarded on related_id because a broadcast legitimately relates to
         * nothing, and must still go out.
         */
        if ($related === null && filled($message->related_id)) {
            return ['cancelled', 'The booking this reminder was for has been removed.'];
        }

        if (! $related instanceof Appointment) {
            return null;
        }

        if (! in_array($related->status, Appointment::REMINDABLE, true)) {
            // lcfirst because statusLabel() is written for a badge on the bookings
            // screen, where it is capitalised. Dropped into the middle of a
            // sentence it would read "The appointment was Cancelled before…".
            return ['cancelled', 'The appointment was '.lcfirst($related->statusLabel()).' before the reminder went out.'];
        }

        /*
         * The catch-all for cron downtime.
         *
         * If the server was off overnight, a queue of "your appointment is
         * tomorrow" messages is now due for appointments starting in an hour.
         * Sending them is worse than not: the customer is already on their way,
         * and the message says the wrong day.
         */
        $minNotice = (int) config('messaging.reminder.min_notice_minutes', 120);

        if (now()->addMinutes($minNotice)->gt($related->starts_at)) {
            return ['skipped', 'The appointment was too close by the time this could be sent.'];
        }

        return null;
    }

    /**
     * Mark the appointment as reminded.
     *
     * A reminder-specific step in an otherwise template-agnostic dispatcher, which
     * is a small wart. It earns its place: appointments.reminded_at already exists
     * and the booking list reads it, so leaving it null would mean the screen an
     * owner actually looks at disagrees with what was sent.
     */
    protected function stampAppointment(ScheduledMessage $message): void
    {
        if ($message->template_key !== ScheduledMessage::REMINDER_24H) {
            return;
        }

        $related = $message->related;

        if ($related instanceof Appointment) {
            $related->forceFill(['reminded_at' => now()])->save();
        }
    }
}
