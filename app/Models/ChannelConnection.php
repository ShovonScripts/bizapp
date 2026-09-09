<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * How one business sends on one channel.
 *
 * Most businesses will never have a row here: Telegram falls back to the platform
 * bot in config, and that is the right default. Rows appear when a business brings
 * its own credentials — a branded Telegram bot, or a WhatsApp Business number,
 * which Meta issues per business and which therefore cannot live in .env.
 */
class ChannelConnection extends Model
{
    use BelongsToBusiness;
    use HasFactory;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const FAILED = 'failed';

    protected $fillable = [
        'business_id',
        'channel',
        'credentials',
        'status',
        'verified_at',
        'error',
        'meta',
    ];

    protected $casts = [
        /*
         * encrypted:json, not json.
         *
         * These are live credentials: a bot token is enough to read every message
         * sent to that bot and to write messages as it. A database backup on a
         * shared host is not a secret, so the token must not be readable in one.
         *
         * The cost is that this column can never appear in a WHERE clause — the
         * ciphertext differs every time the same value is encrypted. Anything we
         * need to search on goes in `meta` instead, which is why bot_username
         * lives there and bot_token lives here.
         */
        'credentials' => 'encrypted:json',
        'meta' => 'array',
        'verified_at' => 'datetime',
    ];

    /**
     * Hidden from array/JSON output.
     *
     * The cast decrypts on access, so a stray toArray() in a Livewire component,
     * an API response or a log line would print the token in clear. This makes
     * that mistake impossible to make by accident.
     */
    protected $hidden = [
        'credentials',
    ];

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', static::ACTIVE);
    }

    public function scopeForChannel(Builder $query, string $channel): Builder
    {
        return $query->where('channel', $channel);
    }

    public function isActive(): bool
    {
        return $this->status === static::ACTIVE;
    }

    public function credential(string $key, mixed $default = null): mixed
    {
        return data_get($this->credentials, $key, $default);
    }

    /** Record that the credentials were just proven to work. */
    public function markVerified(array $meta = []): void
    {
        $this->forceFill([
            'status' => static::ACTIVE,
            'verified_at' => now(),
            'error' => null,
            'meta' => array_merge($this->meta ?? [], $meta),
        ])->save();
    }

    /**
     * Record that they did not.
     *
     * The reason is kept because "your Telegram channel is broken" is useless to
     * an owner and "your bot token was revoked" tells them exactly what to do.
     */
    public function markFailed(string $reason): void
    {
        $this->forceFill([
            'status' => static::FAILED,
            'error' => $reason,
        ])->save();
    }
}
