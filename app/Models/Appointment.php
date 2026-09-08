<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

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

    protected $fillable = [
        'business_id',
        'customer_id',
        'service_id',
        'staff_member_id',
        'starts_at',
        'ends_at',
        'status',
        'price',
        'notes',
        'source',
        'reminded_at',
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'reminded_at' => 'datetime',
        'price' => 'decimal:2',
    ];

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
