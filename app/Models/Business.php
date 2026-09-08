<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

class Business extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'name',
        'slug',
        'niche',
        'timezone',
        'currency',
        'phone',
        'email',
        'address',
        'logo_path',
        'settings',
        'trial_ends_at',
        'subscription_status',
    ];

    protected $casts = [
        'settings' => 'array',
        'trial_ends_at' => 'datetime',
    ];

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /* -----------------------------------------------------------------
     | Timezone helpers
     |
     | Everything is stored in UTC. Reminders must be judged in the
     | business's own local time, otherwise the GMT/BST switch in March
     | and October pushes every reminder an hour out — which UK clients
     | will notice immediately.
     * ----------------------------------------------------------------- */

    public function now(): Carbon
    {
        return Carbon::now($this->timezone ?: 'Europe/London');
    }

    public function toLocal(Carbon|string|null $utc): ?Carbon
    {
        if ($utc === null) {
            return null;
        }

        return Carbon::parse($utc)->setTimezone($this->timezone ?: 'Europe/London');
    }

    /* -----------------------------------------------------------------
     | Settings accessors — settings is a free-form JSON bag so we can add
     | preferences without a migration every time.
     * ----------------------------------------------------------------- */

    public function setting(string $key, mixed $default = null): mixed
    {
        return data_get($this->settings, $key, $default);
    }

    /**
     * Quiet hours, as ['from' => 'HH:MM', 'to' => 'HH:MM'] in business local time.
     * Nobody wants a reminder at 2am — messages:dispatch checks this before sending.
     */
    public function quietHours(): array
    {
        return [
            'from' => $this->setting('quiet_hours.from', '21:00'),
            'to' => $this->setting('quiet_hours.to', '08:00'),
        ];
    }

    public function defaultChannel(): string
    {
        return $this->setting('default_channel', 'telegram');
    }

    public function invoicePrefix(): string
    {
        return $this->setting('invoice_prefix', 'INV');
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }
}
