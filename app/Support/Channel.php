<?php

namespace App\Support;

/**
 * How a channel is spelled when a human reads it.
 *
 * Small, but it exists because ucfirst() gets it wrong for two of the five —
 * "Whatsapp" and "Sms" both look like a bug to a UK salon owner, and once that
 * casing is inlined in a Blade file, a command and a log line, fixing it means
 * finding all three.
 */
class Channel
{
    public const LABELS = [
        'whatsapp' => 'WhatsApp',
        'telegram' => 'Telegram',
        'sms' => 'SMS',
        'email' => 'Email',
        'none' => 'No messages',
    ];

    public static function label(?string $channel): string
    {
        return static::LABELS[$channel] ?? ucfirst((string) $channel);
    }
}
