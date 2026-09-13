<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

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

    public function customers(): HasMany
    {
        return $this->hasMany(Customer::class);
    }

    public function services(): HasMany
    {
        return $this->hasMany(Service::class);
    }

    public function staffMembers(): HasMany
    {
        return $this->hasMany(StaffMember::class);
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    public function channelConnections(): HasMany
    {
        return $this->hasMany(ChannelConnection::class);
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

    /* -----------------------------------------------------------------
     | Deposit & Payment Policy Helpers
     * ----------------------------------------------------------------- */

    public function depositEnabled(): bool
    {
        return (bool) $this->setting('deposit.enabled', false);
    }

    public function depositType(): string
    {
        return $this->setting('deposit.type', 'percentage'); // percentage, fixed, full
    }

    public function depositValue(): float
    {
        return (float) $this->setting('deposit.value', 20.0);
    }

    public function calculateDepositFor(float $price): float
    {
        if (! $this->depositEnabled() || $price <= 0) {
            return 0.00;
        }

        $type = $this->depositType();
        $value = $this->depositValue();

        if ($type === 'full') {
            return round($price, 2);
        }

        if ($type === 'fixed') {
            return round(min($price, max(0.0, $value)), 2);
        }

        // Percentage (e.g. 20%)
        $pct = min(100.0, max(0.0, $value));
        return round(($price * $pct) / 100, 2);
    }

    public function stripeConfig(): array
    {
        $connection = $this->channelConnections()
            ->where('channel', 'stripe')
            ->active()
            ->first();

        if ($connection) {
            return [
                'secret_key' => $connection->credential('secret_key'),
                'publishable_key' => $connection->credential('publishable_key'),
                'webhook_secret' => $connection->credential('webhook_secret'),
                'test_mode' => (bool) ($connection->meta['test_mode'] ?? true),
            ];
        }

        return [
            'secret_key' => $this->setting('stripe.secret_key', config('services.stripe.secret')),
            'publishable_key' => $this->setting('stripe.publishable_key', config('services.stripe.key')),
            'webhook_secret' => $this->setting('stripe.webhook_secret', config('services.stripe.webhook_secret')),
            'test_mode' => (bool) $this->setting('stripe.test_mode', true),
        ];
    }

    public function onTrial(): bool
    {
        return $this->trial_ends_at !== null && $this->trial_ends_at->isFuture();
    }

    /**
     * Build a slug that is not already taken.
     *
     * Slugs are unique at the DB level, so without this a second "Hair Studio"
     * signing up would hit a raw SQL integrity error on the registration form.
     * Includes soft-deleted rows on purpose — the unique index does not care
     * that a row is soft-deleted.
     */
    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'business';
        $slug = $base;
        $i = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
