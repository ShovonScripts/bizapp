<?php

namespace App\Http\Controllers;

use App\Messaging\Telegram\TelegramApi;
use App\Messaging\Telegram\UpdateHandler;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Where Telegram delivers incoming messages in production.
 *
 * ─── Why this route is safe to expose ───────────────────────────────────────
 * It has to be public: Telegram posts to it from its own servers with no session,
 * no user and no CSRF token. What stands in for authentication is a secret we gave
 * Telegram when registering the webhook, which it returns in a header on every
 * delivery.
 *
 * That matters more than it looks. An unprotected endpoint would let anyone post
 * `{"message": {"text": "/start SOMEONE_ELSES_TOKEN", "chat": {"id": 123}}}` and
 * attach their own Telegram account to another salon's customer — after which
 * every reminder for that person, with their name and appointment times, arrives
 * on a stranger's phone. In the UK that is a reportable data breach, so the secret
 * check happens before anything else in this class.
 *
 * ─── Why it almost always returns 200 ───────────────────────────────────────
 * Telegram retries non-200 responses and eventually disables a webhook that keeps
 * failing. A single malformed update must not be able to switch off reminders for
 * every business on the platform, so problems are logged and swallowed. The one
 * exception is a bad secret, where a refusal is the correct answer.
 *
 * Extends nothing: this install has no base Controller class, because Breeze with
 * Volt puts its logic in components instead. A plain invokable class is a valid
 * route action and adding an empty parent just to look conventional would be
 * cargo cult.
 */
class TelegramWebhookController
{
    public function __invoke(Request $request, UpdateHandler $handler, TelegramApi $api): Response
    {
        $expected = (string) config('messaging.telegram.webhook_secret');

        // No secret configured means the webhook was never meant to be live.
        // 404 rather than 500: an endpoint that is not in use should not confirm
        // that it exists.
        if ($expected === '') {
            abort(404);
        }

        $provided = (string) $request->header('X-Telegram-Bot-Api-Secret-Token', '');

        // hash_equals, not ===. String comparison short-circuits on the first
        // differing byte, which leaks the secret one character at a time to
        // anyone patient enough to measure.
        if (! hash_equals($expected, $provided)) {
            Log::warning('[telegram] webhook called with a bad secret', [
                'ip' => $request->ip(),
            ]);

            abort(403);
        }

        try {
            $reply = $handler->handle($request->all());

            if ($reply) {
                $api->sendMessage($reply['chat_id'], $reply['text']);
            }
        } catch (ConnectionException $e) {
            // We could not reply. The update itself was still processed — the
            // customer is linked, they just did not get the confirmation.
            Log::warning('[telegram] could not send webhook reply', ['error' => $e->getMessage()]);
        } catch (\Throwable $e) {
            Log::error('[telegram] webhook handler failed', [
                'error' => $e->getMessage(),

                // The update body is NOT logged. It contains a customer's name,
                // their Telegram handle and their link token — everything needed
                // to impersonate them — and application logs are the least
                // protected thing on a shared host.
            ]);
        }

        return response('', 200);
    }
}
