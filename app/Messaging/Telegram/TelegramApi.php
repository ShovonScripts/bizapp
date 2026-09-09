<?php

namespace App\Messaging\Telegram;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * The only place that knows how to talk to api.telegram.org.
 *
 * It exists because three callers need it — the driver that sends reminders, the
 * poller that reads updates locally, and the webhook that replies in production —
 * and each of them building its own URL would mean three places to get the bot
 * token, the timeout or the path wrong. That kind of duplication is how a fix
 * lands in two of the three and nobody notices which one was missed.
 *
 * It returns raw responses and classifies nothing. Deciding whether a failure is
 * worth retrying is the driver's job, because only the driver knows what the
 * dispatcher will do with the answer.
 */
class TelegramApi
{
    public function __construct(protected ?string $token = null)
    {
        $this->token ??= config('messaging.telegram.bot_token');
    }

    public function isConfigured(): bool
    {
        return filled($this->token);
    }

    /**
     * @throws \Illuminate\Http\Client\ConnectionException
     */
    public function call(string $method, array $params = [], ?int $timeout = null): Response
    {
        return Http::timeout($timeout ?? (int) config('messaging.telegram.timeout', 10))
            ->asJson()
            ->post($this->endpoint($method), $params);
    }

    public function sendMessage(int|string $chatId, string $text): Response
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,

            // No parse_mode anywhere in this app. Telegram's Markdown and HTML
            // modes reject unescaped _ * [ <, so a salon called "Nails & Co" or a
            // customer called "O'Brien-Smith" would fail with a 400 that looks
            // like our bug. Reminders do not need bold text; they need to arrive.
            'disable_web_page_preview' => true,
        ]);
    }

    /**
     * Long-polling read. Only used by telegram:poll for local development —
     * production uses the webhook, and the two cannot both be active.
     *
     * The HTTP timeout is deliberately longer than Telegram's own long-poll
     * timeout, or the client would hang up on a quiet minute and look like a
     * network fault every single time.
     */
    public function getUpdates(int $offset = 0, int $longPollSeconds = 20): Response
    {
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $longPollSeconds,
            'allowed_updates' => ['message'],
        ], timeout: $longPollSeconds + 10);
    }

    /** Cheap "are these credentials real" check. Used by telegram:webhook. */
    public function getMe(): Response
    {
        return $this->call('getMe');
    }

    /**
     * Point Telegram at our webhook. Run once per deployment, not per request.
     *
     * The secret token comes back on every update in a header, which is what makes
     * a public URL safe to expose — see the webhook route for the comparison.
     */
    public function setWebhook(string $url, ?string $secret = null): Response
    {
        return $this->call('setWebhook', array_filter([
            'url' => $url,
            'secret_token' => $secret,
            'allowed_updates' => ['message'],

            // Anything queued while the webhook was off is stale by definition —
            // a /start from three weeks ago should not link a chat today.
            'drop_pending_updates' => true,
        ]));
    }

    public function deleteWebhook(): Response
    {
        return $this->call('deleteWebhook');
    }

    /**
     * The token is in the URL path, which is how Telegram's API works and why a
     * bot token must never be logged: Laravel's HTTP client logs full URLs when
     * request logging is on, and an exception trace can include them too.
     */
    protected function endpoint(string $method): string
    {
        return rtrim((string) config('messaging.telegram.api_url'), '/')
            .'/bot'.$this->token.'/'.$method;
    }
}
