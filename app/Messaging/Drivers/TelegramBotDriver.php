<?php

namespace App\Messaging\Drivers;

use App\Messaging\Contracts\MessageDriver;
use App\Messaging\SendResult;
use App\Messaging\Telegram\TelegramApi;
use App\Models\Business;
use App\Models\ChannelConnection;
use App\Models\Customer;
use Illuminate\Http\Client\ConnectionException;

/**
 * Sends through the Telegram Bot API.
 *
 * ─── The constraint that shapes everything ──────────────────────────────────
 * A bot cannot message anyone who has not first messaged it. There is no
 * workaround, no paid tier, no verification that lifts it. So every customer has
 * to open https://t.me/<bot>?start=<their token> once, and until they do,
 * telegram_chat_id is null and this driver has nowhere to send.
 *
 * That is why hasContactRoute() treats an unlinked Telegram customer as
 * unreachable even when we have their mobile number, and why the customers screen
 * has a "copy link" button. The alternative — discovering it at send time — means
 * the owner finds out their reminders never worked from a customer who missed an
 * appointment.
 *
 * ─── Plain text, no parse_mode ──────────────────────────────────────────────
 * Telegram's Markdown and HTML modes reject unescaped `_`, `*`, `[`, `<`. A salon
 * called "Nails & Co" or a customer called "O'Brien-Smith" would fail with a 400
 * that looks like a bug in our code. Reminders do not need bold text; they need to
 * arrive.
 */
class TelegramBotDriver implements MessageDriver
{
    public function __construct(
        protected Business $business,
        protected ?ChannelConnection $connection = null,
    ) {}

    public function channel(): string
    {
        return 'telegram';
    }

    public function isConfigured(): bool
    {
        return filled($this->token());
    }

    /**
     * The business's own bot if it has one, otherwise the platform bot.
     *
     * Almost every client will use the platform bot — one bot can serve any number
     * of businesses, because the chat id tells us who is being messaged and the
     * customer record tells us on whose behalf. A business only needs its own bot
     * when it wants its own name on it.
     */
    protected function token(): ?string
    {
        $own = data_get($this->connection?->credentials, 'bot_token');

        return filled($own) ? $own : config('messaging.telegram.bot_token');
    }

    public function send(Customer $customer, string $body): SendResult
    {
        if (! $this->isConfigured()) {
            return SendResult::permanentFailure(
                'No Telegram bot token is configured. Add TELEGRAM_BOT_TOKEN to .env.'
            );
        }

        if (blank($customer->telegram_chat_id)) {
            return SendResult::permanentFailure(
                'This customer has not linked Telegram yet, so the bot cannot message them.'
            );
        }

        try {
            $response = (new TelegramApi($this->token()))
                ->sendMessage($customer->telegram_chat_id, $body);
        } catch (ConnectionException $e) {
            // DNS, TLS, timeout. Almost always the host, not us — worth retrying.
            return SendResult::retryableFailure('Could not reach Telegram: '.$e->getMessage());
        }

        $payload = $response->json() ?? [];

        if ($response->successful() && data_get($payload, 'ok') === true) {
            return SendResult::sent((string) data_get($payload, 'result.message_id'));
        }

        return $this->classify(
            (int) (data_get($payload, 'error_code') ?: $response->status()),
            (string) (data_get($payload, 'description') ?: 'Telegram returned HTTP '.$response->status()),
        );
    }

    /**
     * Turn Telegram's error into "try again" or "stop trying", with wording an
     * owner can act on.
     *
     * The distinction is the whole point of SendResult having three states. A
     * blocked bot retried three times is three wasted runs and a reminder that is
     * still never going to arrive; a 429 treated as permanent throws away a
     * message that would have gone fine two minutes later.
     */
    protected function classify(int $code, string $description): SendResult
    {
        // Rate limited. Telegram tells us how long to wait; the dispatcher's own
        // backoff is close enough that we do not need to honour it exactly.
        if ($code === 429) {
            return SendResult::retryableFailure('Telegram rate limit: '.$description);
        }

        // Their side is unwell. Ours is fine.
        if ($code >= 500) {
            return SendResult::retryableFailure('Telegram server error: '.$description);
        }

        if ($code === 401) {
            return SendResult::permanentFailure(
                'The Telegram bot token is wrong or has been revoked. Get a new one from @BotFather.'
            );
        }

        // 403 is nearly always the customer, and it is not a fault to fix — it is
        // an answer. Say so plainly rather than logging "Forbidden".
        if ($code === 403) {
            return SendResult::permanentFailure(
                'This customer has blocked the bot on Telegram, so we cannot message them there.'
            );
        }

        if (str_contains(strtolower($description), 'chat not found')) {
            return SendResult::permanentFailure(
                'Telegram no longer recognises this chat. The customer needs to open the link again.'
            );
        }

        // Anything else in the 4xx range is a request WE built wrongly. Retrying
        // an identical bad request just fails identically three times.
        return SendResult::permanentFailure('Telegram rejected the message: '.$description);
    }
}
