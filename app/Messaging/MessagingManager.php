<?php

namespace App\Messaging;

use App\Messaging\Contracts\MessageDriver;
use App\Messaging\Drivers\LogDriver;
use App\Messaging\Drivers\TelegramBotDriver;
use App\Models\Business;
use App\Models\ChannelConnection;
use App\Support\Channel;

/**
 * Turns (business, channel) into something that can send.
 *
 * Deliberately stateless. An earlier sketch cached resolved connections by
 * business id, which is a real saving in a cron run and a real bug in a test
 * suite: RefreshDatabase wipes the rows but not the object holding them, so test
 * seven sends with test three's credentials. Cross-tenant credential reuse is the
 * single worst failure this system can have, so it does not get to depend on
 * whether someone remembered to flush a cache.
 *
 * The planner resolves once per business per run, which recovers the saving
 * without the shared state.
 */
class MessagingManager
{
    /**
     * Channels with a real driver behind them.
     *
     * whatsapp, sms and email are named in the customer table and on the settings
     * screen, but nothing here can send them yet. They are absent on purpose: the
     * planner asks this before queueing anything, so an unsupported channel
     * produces a visible "not connected yet" row rather than a message that sits
     * pending forever.
     */
    protected const DRIVERS = [
        'telegram' => TelegramBotDriver::class,
    ];

    public function supports(string $channel): bool
    {
        return array_key_exists($channel, static::DRIVERS);
    }

    /** @return list<string> */
    public function supportedChannels(): array
    {
        return array_keys(static::DRIVERS);
    }

    /**
     * Null when nothing can send on this channel at all.
     *
     * Distinct from a driver that exists but is not configured — the caller needs
     * to tell those apart to explain itself: "we don't do WhatsApp yet" and "your
     * Telegram bot isn't set up" ask different things of the owner.
     */
    public function driver(Business $business, string $channel): ?MessageDriver
    {
        if (! $this->supports($channel)) {
            return null;
        }

        // The safety switch. Set MESSAGING_DRIVER=log and every supported channel
        // resolves here instead, so nothing leaves the building — but only for
        // channels that would genuinely work in production, otherwise development
        // would happily exercise paths that do not exist yet.
        if (config('messaging.driver') === 'log') {
            return new LogDriver($business, $channel);
        }

        return match ($channel) {
            'telegram' => new TelegramBotDriver($business, $this->connection($business, $channel)),
        };
    }

    /** Is there a driver AND are its credentials in place? */
    public function canSend(Business $business, string $channel): bool
    {
        return $this->driver($business, $channel)?->isConfigured() ?? false;
    }

    /**
     * Why can't this business send on this channel? Null means it can.
     *
     * Wording is owner-facing and ends up in scheduled_messages.error, so it says
     * what to do rather than what went wrong.
     */
    public function unavailableReason(Business $business, string $channel): ?string
    {
        $label = Channel::label($channel);

        if (! $this->supports($channel)) {
            return $label.' messages are not available yet.';
        }

        if (! $this->canSend($business, $channel)) {
            return $label.' is not connected for this business yet.';
        }

        return null;
    }

    /**
     * The business's own credentials for a channel, if it has any.
     *
     * business_id is matched explicitly rather than leaning on the global scope,
     * because this runs from a console command where there is no current tenant
     * and the scope therefore adds no WHERE clause at all.
     */
    protected function connection(Business $business, string $channel): ?ChannelConnection
    {
        return ChannelConnection::query()
            ->where('business_id', $business->id)
            ->forChannel($channel)
            ->active()
            ->first();
    }
}
