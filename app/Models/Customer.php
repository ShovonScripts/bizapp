<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Customer extends Model
{
    use BelongsToBusiness;
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'business_id',
        'name',
        'phone',
        'email',
        'whatsapp_number',
        'telegram_chat_id',
        'telegram_link_token',
        'preferred_channel',
        'marketing_consent',
        'consent_at',
        'consent_source',
        'unsubscribed_at',
        'notes',
        'tags',
        'last_visit_at',
        'total_spend',
    ];

    protected $casts = [
        'tags' => 'array',
        'marketing_consent' => 'boolean',
        'consent_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'last_visit_at' => 'datetime',
        'total_spend' => 'decimal:2',
    ];

    /* -----------------------------------------------------------------
     | Phone normalisation happens HERE, at the model boundary.
     |
     | Every write path — Livewire form, seeder, CSV import, API — goes through
     | Eloquent, so normalising here means the (business_id, phone) unique index
     | is actually enforceable. Doing it in the form instead would leave the
     | importer free to create duplicates.
     * ----------------------------------------------------------------- */

    protected function phone(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => Phone::normalise($value),
        );
    }

    protected function whatsappNumber(): Attribute
    {
        return Attribute::make(
            set: fn (?string $value) => Phone::normalise($value),
        );
    }

    /* ----------------------------- Relations ----------------------------- */

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class);
    }

    /* ------------------------------ Channels ----------------------------- */

    /** Number to use for WhatsApp — the dedicated one if set, otherwise the main phone. */
    public function whatsappTarget(): ?string
    {
        return $this->whatsapp_number ?: $this->phone;
    }

    /** Has this customer started the Telegram bot? Until they do, we cannot message them. */
    public function telegramLinked(): bool
    {
        return filled($this->telegram_chat_id);
    }

    /**
     * Generate (once) the token used in https://t.me/<bot>?start=<token>.
     *
     * Telegram will not let a bot message someone who has not started a chat,
     * so this token + a QR code is how a salon gets a customer connected.
     * The token is globally unique because the webhook arrives with no tenant context.
     */
    public function ensureTelegramLinkToken(): string
    {
        if (blank($this->telegram_link_token)) {
            $this->forceFill(['telegram_link_token' => Str::random(24)])->save();
        }

        return $this->telegram_link_token;
    }

    /* ------------------------------- Consent ----------------------------- */

    /**
     * Transactional = "your appointment is tomorrow". Lawful basis is contract /
     * legitimate interest, so consent is NOT required — but an explicit
     * unsubscribe still wins, because ignoring it is how you get complaints.
     */
    public function canReceiveTransactional(): bool
    {
        return $this->preferred_channel !== 'none' && $this->unsubscribed_at === null;
    }

    /**
     * Marketing = "we miss you, 20% off". Needs actual consent under UK GDPR/PECR.
     * Both conditions must hold. Never collapse these two methods into one.
     */
    public function canReceiveMarketing(): bool
    {
        return $this->marketing_consent
            && $this->unsubscribed_at === null
            && $this->preferred_channel !== 'none';
    }

    public function recordConsent(string $source): void
    {
        $this->forceFill([
            'marketing_consent' => true,
            'consent_at' => now(),
            'consent_source' => $source,
            'unsubscribed_at' => null,
        ])->save();
    }

    public function unsubscribe(): void
    {
        $this->forceFill([
            'marketing_consent' => false,
            'unsubscribed_at' => now(),
        ])->save();
    }

    /* -------------------------------- Scopes ----------------------------- */

    /** Win-back candidates: last visit older than N days (or never). */
    public function scopeLapsed(Builder $query, int $days = 90): Builder
    {
        return $query->where(function (Builder $q) use ($days) {
            $q->whereNull('last_visit_at')
                ->orWhere('last_visit_at', '<', now()->subDays($days));
        });
    }

    public function scopeMarketable(Builder $query): Builder
    {
        return $query->where('marketing_consent', true)
            ->whereNull('unsubscribed_at')
            ->where('preferred_channel', '!=', 'none');
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        if (blank($term)) {
            return $query;
        }

        // Normalise the term too, so searching "07700 900123" finds "+447700900123".
        // Returns null for a name like "Sarah" — and we must NOT build a phone
        // clause in that case, or we get `phone like '%%'`, which matches everyone.
        $phone = Phone::normalise($term);

        return $query->where(function (Builder $q) use ($term, $phone) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%");

            if ($phone !== null) {
                $q->orWhere('phone', 'like', '%'.ltrim($phone, '+').'%');
            }
        });
    }
}
