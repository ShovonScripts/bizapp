<?php

namespace App\Messaging\Telegram;

use App\Models\Customer;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handles one incoming Telegram update.
 *
 * Shared by BOTH ways updates arrive: telegram:poll locally (no HTTPS needed) and
 * the webhook route in production. One class, so the thing tested on XAMPP is
 * literally the thing that runs on the server — a second implementation for
 * webhooks would be untested until the day it matters.
 *
 * ─── No tenant here ─────────────────────────────────────────────────────────
 * An update arrives with a chat id and nothing else. There is no logged-in user
 * and no current business, so every lookup below is deliberately unscoped and
 * keyed on a value only the right customer could have: their link token, or a
 * chat id we previously recorded. Nothing in this class may ever take a business
 * id from the update itself.
 */
class UpdateHandler
{
    /**
     * @param  array  $update  The raw decoded update from Telegram.
     * @return array{chat_id: int|string, text: string}|null  A reply to send, if any.
     */
    public function handle(array $update): ?array
    {
        $chatId = data_get($update, 'message.chat.id');
        $text = trim((string) data_get($update, 'message.text', ''));

        if (blank($chatId) || $text === '') {
            return null;
        }

        if (Str::startsWith($text, '/start')) {
            return $this->reply($chatId, $this->start($chatId, trim(Str::after($text, '/start'))));
        }

        if (Str::startsWith($text, '/stop')) {
            return $this->reply($chatId, $this->stop($chatId));
        }

        return $this->reply(
            $chatId,
            "I only send appointment reminders. Send /stop at any time to turn them off."
        );
    }

    /**
     * /start <token> — the one moment a customer becomes reachable.
     */
    protected function start(int|string $chatId, string $token): string
    {
        if ($token === '') {
            return "Hello! To get your appointment reminders here, please use the personal link your salon sent you.";
        }

        $customer = Customer::withoutGlobalScope('business')
            ->where('telegram_link_token', $token)
            ->first();

        if (! $customer) {
            // Could be a stale link, a typo, or a customer who was deleted. All
            // three get the same wording on purpose: telling a stranger which one
            // it was leaks whether that token ever existed.
            return "That link isn't valid any more. Please ask for a new one.";
        }

        /*
         * A link that has already been used stays with the chat that used it.
         *
         * These links get forwarded — the owner sends one by WhatsApp and it ends
         * up in a family group. Without this check, whoever tapped it last would
         * start receiving someone else's name, appointment times and salon.
         *
         * The same chat tapping twice is the common case, not an attack, so it
         * gets a friendly answer rather than the refusal.
         */
        if (filled($customer->telegram_chat_id)) {
            if ((string) $customer->telegram_chat_id === (string) $chatId) {
                return "You're already set up — I'll send your reminders here.";
            }

            Log::warning('[telegram] link token reused from a different chat', [
                'customer_id' => $customer->id,
            ]);

            return "That link has already been used. Please ask for your own.";
        }

        $customer->forceFill(['telegram_chat_id' => (string) $chatId])->save();

        $business = $customer->business;
        $name = $business?->name ?? 'your salon';

        /*
         * Starting the bot does NOT undo an opt-out.
         *
         * Someone who unsubscribed, or whose reminders were switched off, has
         * made an explicit decision; tapping a link is not a withdrawal of it.
         * Treating it as re-consent is exactly the kind of inferred permission
         * PECR does not accept — so we link the chat, say so plainly, and leave
         * the switch where they left it.
         */
        if (! $customer->canReceiveTransactional()) {
            return "You're connected, but reminders are currently switched off for you. ".
                "Let {$name} know if you'd like them back on.";
        }

        // They came here through a Telegram link, so Telegram is where they want
        // their reminders. Safe to set now: the opt-out case returned above.
        if ($customer->preferred_channel !== 'telegram') {
            $customer->forceFill(['preferred_channel' => 'telegram'])->save();
        }

        return "You're all set — {$name} will send your appointment reminders here. ".
            "Send /stop at any time to turn them off.";
    }

    /**
     * /stop — the opt-out. Must work, first time, no questions.
     *
     * Applied to EVERY customer record on this chat id. One phone can belong to a
     * customer of two different businesses on the platform bot, and "stop" from a
     * person means stop, not stop-from-one-of-us. Over-honouring an opt-out costs
     * a reminder; under-honouring it is a PECR breach.
     */
    protected function stop(int|string $chatId): string
    {
        $customers = Customer::withoutGlobalScope('business')
            ->where('telegram_chat_id', (string) $chatId)
            ->get();

        if ($customers->isEmpty()) {
            return "You're not receiving any reminders from me.";
        }

        foreach ($customers as $customer) {
            $customer->unsubscribe();
        }

        return "Done — you won't get any more messages from me. ".
            "If you change your mind, just ask your salon to switch them back on.";
    }

    protected function reply(int|string $chatId, string $text): array
    {
        return ['chat_id' => $chatId, 'text' => $text];
    }
}
