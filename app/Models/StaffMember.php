<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class StaffMember extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    protected $fillable = [
        'business_id',
        'user_id',
        'name',
        'phone',
        'color',
        'active',
        'sort_order',
        'working_hours',
        'time_off',
    ];

    protected $casts = [
        'active' => 'boolean',
        'sort_order' => 'integer',
        'working_hours' => 'array',
        'time_off' => 'array',
    ];

    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => Phone::normalise($value),
        );
    }

    /** Nullable: most salon staff never log in, the owner books for them. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /* -----------------------------------------------------------------
     | Schedule & Time-off Methods
     * ----------------------------------------------------------------- */

    public static function defaultWorkingHours(): array
    {
        return [
            'monday' => ['is_working' => true, 'start' => '09:00', 'end' => '17:00'],
            'tuesday' => ['is_working' => true, 'start' => '09:00', 'end' => '17:00'],
            'wednesday' => ['is_working' => true, 'start' => '09:00', 'end' => '17:00'],
            'thursday' => ['is_working' => true, 'start' => '09:00', 'end' => '18:00'],
            'friday' => ['is_working' => true, 'start' => '09:00', 'end' => '18:00'],
            'saturday' => ['is_working' => true, 'start' => '09:00', 'end' => '16:00'],
            'sunday' => ['is_working' => false, 'start' => '10:00', 'end' => '14:00'],
        ];
    }

    public function workingHoursFor(string|int $dayOfWeek): array
    {
        $dayMap = [
            0 => 'sunday',
            1 => 'monday',
            2 => 'tuesday',
            3 => 'wednesday',
            4 => 'thursday',
            5 => 'friday',
            6 => 'saturday',
            7 => 'sunday',
        ];

        if (is_numeric($dayOfWeek)) {
            $key = $dayMap[(int) $dayOfWeek] ?? 'monday';
        } else {
            $key = strtolower((string) $dayOfWeek);
        }

        $defaults = static::defaultWorkingHours();
        $custom = data_get($this->working_hours, $key);

        if ($custom && is_array($custom)) {
            $isWorking = $custom['is_working'] ?? $custom['active'] ?? false;

            return [
                'is_working' => (bool) $isWorking,
                'active' => (bool) $isWorking,
                'start' => (string) ($custom['start'] ?? '09:00'),
                'end' => (string) ($custom['end'] ?? '17:00'),
            ];
        }

        $default = $defaults[$key] ?? ['is_working' => false, 'start' => '09:00', 'end' => '17:00'];
        $default['active'] = $default['is_working'];

        return $default;
    }

    public function isWorkingOnDate(\DateTimeInterface|string $date): bool
    {
        $carbonDate = is_string($date) ? Carbon::parse($date) : Carbon::instance($date);
        $dayName = strtolower($carbonDate->format('l'));
        $dayConfig = $this->workingHoursFor($dayName);

        if (! $dayConfig['is_working']) {
            return false;
        }

        $dateString = $carbonDate->toDateString();
        $timeOffs = (array) ($this->time_off ?? []);

        foreach ($timeOffs as $off) {
            $isAllDay = $off['all_day'] ?? $off['is_all_day'] ?? false;
            if (($off['date'] ?? '') === $dateString && $isAllDay) {
                return false;
            }
        }

        return true;
    }

    public function isAvailableForSlot(
        \DateTimeInterface $slotStartLocal,
        \DateTimeInterface $slotEndLocal,
        ?\DateTimeInterface $slotStartUtc = null,
        ?\DateTimeInterface $slotEndUtc = null
    ): bool {
        $slotStartLocal = Carbon::instance($slotStartLocal);
        $slotEndLocal = Carbon::instance($slotEndLocal);

        if (! $this->isWorkingOnDate($slotStartLocal)) {
            return false;
        }

        $dayConfig = $this->workingHoursFor(strtolower($slotStartLocal->format('l')));
        $tz = $slotStartLocal->getTimezone();
        $shiftStartLocal = Carbon::parse($slotStartLocal->toDateString().' '.$dayConfig['start'], $tz);
        $shiftEndLocal = Carbon::parse($slotStartLocal->toDateString().' '.$dayConfig['end'], $tz);

        if ($slotStartLocal->lt($shiftStartLocal) || $slotEndLocal->gt($shiftEndLocal)) {
            return false;
        }

        $dateString = $slotStartLocal->toDateString();
        $timeOffs = (array) ($this->time_off ?? []);

        foreach ($timeOffs as $off) {
            if (($off['date'] ?? '') !== $dateString) {
                continue;
            }

            $isAllDay = $off['all_day'] ?? $off['is_all_day'] ?? false;
            if ($isAllDay) {
                return false;
            }

            $start = $off['start'] ?? $off['start_time'] ?? null;
            $end = $off['end'] ?? $off['end_time'] ?? null;

            if (! empty($start) && ! empty($end)) {
                $offStart = Carbon::parse($dateString.' '.$start, $tz);
                $offEnd = Carbon::parse($dateString.' '.$end, $tz);

                if ($slotStartLocal->lt($offEnd) && $slotEndLocal->gt($offStart)) {
                    return false;
                }
            }
        }

        return true;
    }

    public function workingDaysSummary(): string
    {
        $days = ['Mon' => 'monday', 'Tue' => 'tuesday', 'Wed' => 'wednesday', 'Thu' => 'thursday', 'Fri' => 'friday', 'Sat' => 'saturday', 'Sun' => 'sunday'];
        $working = [];

        foreach ($days as $short => $full) {
            if ($this->workingHoursFor($full)['is_working']) {
                $working[] = $short;
            }
        }

        if (count($working) === 7) {
            return 'Every Day';
        }

        if ($working === ['Mon', 'Tue', 'Wed', 'Thu', 'Fri']) {
            return 'Mon - Fri';
        }

        if ($working === ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat']) {
            return 'Mon - Sat';
        }

        if ($working === ['Tue', 'Wed', 'Thu', 'Fri', 'Sat']) {
            return 'Tue - Sat';
        }

        if (empty($working)) {
            return 'Off Duty';
        }

        return implode(', ', $working);
    }
}
