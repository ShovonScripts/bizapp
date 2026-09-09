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
     * Is there anywhere to actually send a message?
     *
     * A separate question from consent: someone can be perfectly willing to hear
     * from us and still be unreachable because nobody took their number, or
     * because they never started the Telegram bot. Both have to be true before a
     * reminder can be counted as one that will arrive — and the dashboard must not
     * promise "5 reminders tomorrow" when two of them have nowhere to go.
     *
     * Deliberately channel-aware rather than "has a phone": a telegram-preferring
     * customer with a mobile number is still unreachable until they link.
     */
    public function hasContactRoute(): bool
    {
        return match ($this->preferred_channel) {
            'telegram' => $this->telegramLinked(),
            'whatsapp' => filled($this->whatsappTarget()),
            'sms' => filled($this->phone),
            'email' => filled($this->email),
            // 'none', plus any channel added to the column but not yet taught here.
            default => false,
        };
    }

    /**
     * The SQL half of hasContactRoute(), so a count does not have to load every
     * row into PHP. CustomerTest asserts the two agree — they will drift otherwise,
     * and a dashboard number that disagrees with what actually sends is worse than
     * no number at all.
     */
    public function scopeWithContactRoute(Builder $query): Builder
    {
        return $query->where(function (Builder $outer) {
            $outer
                ->where(fn (Builder $q) => $q->where('preferred_channel', 'telegram')
                    ->whereNotNull('telegram_chat_id'))
                ->orWhere(fn (Builder $q) => $q->where('preferred_channel', 'whatsapp')
                    ->where(fn (Builder $w) => $w->whereNotNull('whatsapp_number')->orWhereNotNull('phone')))
                ->orWhere(fn (Builder $q) => $q->where('preferred_channel', 'sms')
                    ->whereNotNull('phone'))
                ->orWhere(fn (Builder $q) => $q->where('preferred_channel', 'email')
                    ->whereNotNull('email'));
        });
    }

    /**
     * Why can't we message this customer? Null means we can.
     *
     * Exists so the reason is written once and shown identically wherever it
     * matters — the dashboard's "needs a phone call" list today, and the reminder
     * engine's skip log tomorrow. Two separate wordings of the same fact would
     * eventually disagree, and "why didn't my customer get their reminder" is a
     * question the owner will ask us at some point.
     *
     * Order matters. Unsubscribed comes first because it is the one reason we must
     * not work around: everything below it is a gap to fill in, that one is a
     * decision the customer made.
     */
    public function unreachableReason(): ?string
    {
        if ($this->unsubscribed_at !== null) {
            return 'asked us to stop';
        }

        if ($this->preferred_channel === 'none') {
            return 'reminders turned off';
        }

        if (! $this->hasContactRoute()) {
            return match ($this->preferred_channel) {
                'telegram' => 'Telegram not linked yet',
                'whatsapp' => 'no WhatsApp number',
                'sms' => 'no mobile number',
                'email' => 'no email address',
                default => 'no way to reach them',
            };
        }

        return null;
    }

    /**
     * The same answer as a finished sentence.
     *
     * unreachableReason() returns a fragment because the dashboard prints it
     * inline ("Sarah Khan — Telegram not linked yet"). The messaging layer needs
     * it standing alone in scheduled_messages.error, where a lowercase fragment
     * with no full stop reads like truncated output.
     *
     * Both forms live here so they cannot drift. Two call sites doing their own
     * ucfirst() is exactly how the dashboard's count and its explanation once
     * ended up disagreeing.
     */
    public function unreachableSentence(): ?string
    {
        $reason = $this->unreachableReason();

        return $reason === null ? null : ucfirst($reason).'.';
    }

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

    /* ---------------------------- Visit rollup --------------------------- */

    /**
     * Rebuild last_visit_at and total_spend from the appointments table.
     *
     * Recomputed, never incremented. An increment/decrement pair drifts the first
     * time a status is changed twice, a booking is edited, or an appointment is
     * deleted — and once total_spend is wrong it stays wrong, quietly changing who
     * counts as a lapsed customer and what the earnings figure says. Two cheap
     * aggregates buy certainty.
     *
     * Only COMPLETED counts: a cancellation or a no-show is not a visit, and
     * nobody paid for it. Soft-deleted appointments are excluded by the model's
     * own scope, which is correct — a removed booking never happened.
     */
    public function recomputeVisitStats(): void
    {
        // A fresh relation per aggregate on purpose: clone() on a Relation is a
        // shallow copy, so both calls would share — and mutate — one query builder.
        $completed = fn () => $this->appointments()->where('status', Appointment::COMPLETED);

        $this->forceFill([
            'last_visit_at' => $completed()->max('starts_at'),
            'total_spend' => $completed()->sum('price'),
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
