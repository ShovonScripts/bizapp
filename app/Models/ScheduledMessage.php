<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One queued message. The outbox row that `messages:plan` writes and
 * `messages:dispatch` sends.
 *
 * The row is the audit trail as much as the instruction. When an owner asks why a
 * customer was not reminded, the answer has to come from data, not from guessing —
 * so a message that is deliberately not sent keeps its row and gets a reason,
 * rather than quietly never existing.
 */
class ScheduledMessage extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    public const PENDING = 'pending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const CANCELLED = 'cancelled';

    public const SKIPPED = 'skipped';

    /**
     * The one template that exists so far.
     *
     * A constant rather than a loose string because it is also the dedupe key: a
     * typo in one of two places would mean every appointment gets reminded twice.
     */
    public const REMINDER_24H = 'appointment_reminder_24h';

    protected $fillable = [
        'business_id',
        'customer_id',
        'channel',
        'template_key',
        'payload',
        'send_at',
        'status',
        'attempts',
        'sent_at',
        'error',
        'related_type',
        'related_id',
    ];

    protected $casts = [
        'payload' => 'array',
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
        'attempts' => 'integer',
    ];

    /* ----------------------------- Relations ----------------------------- */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** The appointment (today) or invoice (later) this message is about. */
    public function related(): MorphTo
    {
        return $this->morphTo();
    }

    /* ------------------------------- Scopes ------------------------------ */

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', static::PENDING);
    }

    /**
     * Ready to send: pending and its moment has arrived.
     *
     * Deliberately not scoped to a business — the dispatcher runs once for the
     * whole platform, which is why the (status, send_at) index leads with status.
     */
    public function scopeDue(Builder $query): Builder
    {
        return $query->where('status', static::PENDING)
            ->where('send_at', '<=', now());
    }

    /* ---------------------------- Transitions ---------------------------- */

    /**
     * The text that goes out.
     *
     * Rendered at plan time and stored, so what an owner previews in the queue is
     * exactly what the customer receives. If this were rendered at send time, a
     * service renamed in between would change a message already approved.
     */
    public function body(): string
    {
        return (string) data_get($this->payload, 'body', '');
    }

    public function markSent(?string $providerMessageId = null): void
    {
        $this->forceFill([
            'status' => static::SENT,
            'sent_at' => now(),
            'attempts' => $this->attempts + 1,
            'error' => null,
            'payload' => array_merge($this->payload ?? [], [
                'provider_message_id' => $providerMessageId,
            ]),
        ])->save();
    }

    /**
     * A send failed. Whether that is final depends on how many tries it has had.
     *
     * Called only for retryable failures — a permanent one goes straight to
     * giveUp(), because counting attempts against something that cannot succeed
     * just delays telling the owner the truth.
     */
    public function recordRetryableFailure(string $error): void
    {
        $attempts = $this->attempts + 1;
        $max = (int) config('messaging.max_attempts', 3);

        if ($attempts >= $max) {
            $this->forceFill([
                'status' => static::FAILED,
                'attempts' => $attempts,
                'error' => $error,
            ])->save();

            return;
        }

        $backoff = (int) (config('messaging.retry_after_minutes')[$attempts] ?? 15);

        $this->forceFill([
            'status' => static::PENDING,
            'attempts' => $attempts,
            'error' => $error,
            // Pushed forward so the next run does not pick it up immediately and
            // burn all three attempts inside one minute.
            'send_at' => now()->addMinutes($backoff),
        ])->save();
    }

    public function giveUp(string $error): void
    {
        $this->forceFill([
            'status' => static::FAILED,
            'attempts' => $this->attempts + 1,
            'error' => $error,
        ])->save();
    }

    /**
     * Not sent, on purpose.
     *
     * Distinct from failed: nothing went wrong, we decided. Consent withdrawn, no
     * contact route, or the appointment moved too close for the reminder to mean
     * anything. The reason is written for the owner to read.
     */
    public function skip(string $reason): void
    {
        $this->forceFill([
            'status' => static::SKIPPED,
            'error' => $reason,
        ])->save();
    }

    /** The underlying appointment was cancelled or moved, so this is void. */
    public function cancel(string $reason = 'The appointment was cancelled.'): void
    {
        $this->forceFill([
            'status' => static::CANCELLED,
            'error' => $reason,
        ])->save();
    }

    public function isPending(): bool
    {
        return $this->status === static::PENDING;
    }
}
