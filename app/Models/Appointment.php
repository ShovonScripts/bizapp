<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Observers\AppointmentObserver;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/*
 * The observer is attached here rather than in a service provider so that it is
 * visible from the model itself. Anyone reading Appointment can see that saving
 * one has consequences for queued reminders; a registration buried in
 * AppServiceProvider is the kind of thing you only find after wondering for an
 * hour why cancelling a booking cancelled a message.
 */
#[ObservedBy(AppointmentObserver::class)]
class Appointment extends Model
{
    use BelongsToBusiness;
    use HasFactory;
    use SoftDeletes;

    /* Status values. Kept as constants so a typo is a fatal error, not a silently
       broken query — `status = 'cancelled'` vs `'canceled'` is a classic. */
    public const PENDING = 'pending';
    public const CONFIRMED = 'confirmed';
    public const COMPLETED = 'completed';
    public const CANCELLED = 'cancelled';
    public const NO_SHOW = 'no_show';

    /** Statuses a reminder should be sent for. Nothing else. */
    public const REMINDABLE = [self::PENDING, self::CONFIRMED];

    /** Statuses that block the slot in the calendar. */
    public const BLOCKING = [self::PENDING, self::CONFIRMED, self::COMPLETED];

    /** Every legal value, for validation. A status outside this list is a bug, not input. */
    public const STATUSES = [
        self::PENDING,
        self::CONFIRMED,
        self::COMPLETED,
        self::CANCELLED,
        self::NO_SHOW,
    ];

    protected $fillable = [
        'business_id',
        'customer_id',
        'service_id',
        'staff_member_id',
        'starts_at',
        'ends_at',
        'status',
        'price',
        'deposit_required',
        'deposit_amount',
        'deposit_status',
        'paid_amount',
        'stripe_payment_intent_id',
        'stripe_session_id',
        'notes',
        'source',
        'reminded_at',
        'cancellation_token',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reminded_at' => 'datetime',
        'price' => 'decimal:2',
        'deposit_required' => 'boolean',
        'deposit_amount' => 'decimal:2',
        'paid_amount' => 'decimal:2',
    ];

    public function balanceDue(): float
    {
        $price = (float) $this->price;
        $paid = (float) $this->paid_amount;

        return max(0.0, round($price - $paid, 2));
    }

    public function isFullyPaid(): bool
    {
        return $this->balanceDue() <= 0.00;
    }

    public function hasPaidDeposit(): bool
    {
        return $this->deposit_status === 'paid' || $this->paid_amount > 0;
    }

    /* ----------------------------- Relations ----------------------------- */

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    /* ------------------------------- Scopes ------------------------------ */

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('starts_at', '>=', now())->orderBy('starts_at');
    }

    public function scopePast(Builder $query): Builder
    {
        return $query->where('starts_at', '<', now())->orderByDesc('starts_at');
    }

    public function scopeRemindable(Builder $query): Builder
    {
        return $query->whereIn('status', self::REMINDABLE);
    }

    public function scopeBlocking(Builder $query): Builder
    {
        return $query->whereIn('status', self::BLOCKING);
    }

    /**
     * Appointments on a given calendar day IN THE BUSINESS'S TIMEZONE.
     *
     * This is the method to use for any "today"/"tomorrow" view. Doing
     * whereDate('starts_at', $date) instead compares against UTC, which puts
     * a 23:00 London booking on the wrong day for half the year.
     */
    public function scopeOnLocalDate(Builder $query, Business $business, Carbon|string $date): Builder
    {
        $tz = $business->timezone ?: 'Europe/London';

        $start = Carbon::parse($date, $tz)->startOfDay()->utc();
        $end = Carbon::parse($date, $tz)->endOfDay()->utc();

        return $query->whereBetween('starts_at', [$start, $end]);
    }

    /**
     * Double-booking check for one staff member. Overlap is
     * (existing.starts_at < new.ends_at) AND (existing.ends_at > new.starts_at) —
     * touching edges (one ends 14:00, next starts 14:00) is allowed on purpose.
     *
     * Not a DB constraint: MySQL cannot express range exclusion, and salons
     * genuinely do want to force a double-booking sometimes. So this warns,
     * it does not forbid.
     */
    public function scopeConflictingWith(
        Builder $query,
        ?int $staffMemberId,
        Carbon $startsAt,
        Carbon $endsAt,
        ?int $ignoreAppointmentId = null
    ): Builder {
        $query->blocking()
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);

        // No staff assigned means we cannot say it clashes with anyone.
        $query->when(
            $staffMemberId !== null,
            fn (Builder $q) => $q->where('staff_member_id', $staffMemberId),
            fn (Builder $q) => $q->whereRaw('1 = 0')
        );

        return $query->when(
            $ignoreAppointmentId !== null,
            fn (Builder $q) => $q->whereKeyNot($ignoreAppointmentId)
        );
    }

    /* ------------------------------ Helpers ------------------------------ */

    /**
     * Change the status and keep the customer's visit rollup honest.
     *
     * Every status change must come through here. `last_visit_at` and
     * `total_spend` on the customer are derived from COMPLETED appointments, and
     * they feed the lapsed-customer list and the earnings figures — so a bare
     * `update(['status' => ...])` anywhere else silently desyncs the numbers the
     * owner is paying us to get right. Reversal matters as much as completion:
     * marking a booking completed and then cancelling it has to take the money
     * back off.
     */
    public function changeStatus(string $status): void
    {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown appointment status [{$status}].");
        }

        $this->update(['status' => $status]);

        // Null only if the customer has been soft-deleted, in which case their
        // stale figures are the least of anyone's worries.
        $this->customer?->recomputeVisitStats();
    }

    public function localStartsAt(): ?Carbon
    {
        return $this->business?->toLocal($this->starts_at);
    }

    public function localEndsAt(): ?Carbon
    {
        return $this->business?->toLocal($this->ends_at);
    }

    public function durationMinutes(): int
    {
        return (int) $this->starts_at->diffInMinutes($this->ends_at);
    }

    public function isCancelled(): bool
    {
        return in_array($this->status, [self::CANCELLED, self::NO_SHOW], true);
    }

    public function countsAsRevenue(): bool
    {
        return $this->status === self::COMPLETED;
    }

    /** Human label for the status badge. */
    public function statusLabel(): string
    {
        return match ($this->status) {
            self::PENDING => 'Pending',
            self::CONFIRMED => 'Confirmed',
            self::COMPLETED => 'Completed',
            self::CANCELLED => 'Cancelled',
            self::NO_SHOW => 'No-show',
            default => ucfirst(str_replace('_', ' ', (string) $this->status)),
        };
    }
}
