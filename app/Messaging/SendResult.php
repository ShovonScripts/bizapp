<?php

namespace App\Messaging;

/**
 * What happened when a driver tried to send.
 *
 * Three outcomes, not two, and the third is the one that matters. A driver that
 * can only say "worked" or "broke" forces the dispatcher to retry things that
 * will never succeed: a customer who blocked the bot, a number that is not on
 * WhatsApp, an unsubscribed recipient. Those are permanent, and retrying them
 * three times with backoff just delays the honest answer to the owner.
 *
 * So: retryable failures get retried, permanent ones fail immediately with a
 * reason a salon owner can act on.
 */
class SendResult
{
    protected function __construct(
        public readonly bool $sent,
        public readonly bool $retryable,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $error = null,
    ) {}

    public static function sent(?string $providerMessageId = null): static
    {
        return new static(sent: true, retryable: false, providerMessageId: $providerMessageId);
    }

    /**
     * Something transient: a timeout, a 500 from the provider, a rate limit.
     * Worth trying again in a few minutes.
     */
    public static function retryableFailure(string $error): static
    {
        return new static(sent: false, retryable: true, error: $error);
    }

    /**
     * Something that will never work: the customer blocked the bot, the token is
     * wrong, the recipient has no route on this channel. Retrying is dishonest —
     * it hides a problem the owner needs to see.
     */
    public static function permanentFailure(string $error): static
    {
        return new static(sent: false, retryable: false, error: $error);
    }

    public function failed(): bool
    {
        return ! $this->sent;
    }
}
