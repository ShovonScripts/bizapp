<?php

namespace App\Console\Commands;

use App\Messaging\Telegram\TelegramApi;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;

/**
 * Check the bot, and point Telegram at this installation (or stop it).
 *
 *   php artisan telegram:webhook            # who am I, and where are updates going?
 *   php artisan telegram:webhook --set      # start delivering updates to this app
 *   php artisan telegram:webhook --delete   # stop, so telegram:poll can be used
 *
 * Run with no options first. It answers the two questions that account for most
 * "the bot isn't working" time: is this token real, and is something else already
 * receiving this bot's messages.
 */
class TelegramWebhook extends Command
{
    protected $signature = 'telegram:webhook
                            {--set : Register this app as the bot webhook}
                            {--delete : Remove the webhook so polling can be used}';

    protected $description = 'Inspect or configure the Telegram webhook';

    public function handle(TelegramApi $api): int
    {
        if (! $api->isConfigured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not set in .env.');

            return self::FAILURE;
        }

        try {
            return match (true) {
                (bool) $this->option('delete') => $this->delete($api),
                (bool) $this->option('set') => $this->set($api),
                default => $this->show($api),
            };
        } catch (ConnectionException $e) {
            $this->error('Could not reach Telegram: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    protected function show(TelegramApi $api): int
    {
        $me = $api->getMe()->json();

        if (data_get($me, 'ok') !== true) {
            $this->error('Telegram rejected the token: '.data_get($me, 'description', 'unknown error'));
            $this->line('If it was revoked, get a new one from @BotFather and update .env.');

            return self::FAILURE;
        }

        $username = data_get($me, 'result.username');

        $this->info('Bot: @'.$username);

        // The one setting people forget. Every customer link is built from it, so
        // if it is missing or stale the links all point at the wrong bot.
        //
        // The leading @ is stripped before comparing, the same way the Customers
        // screen strips it when building a link — otherwise pasting the handle in
        // exactly as BotFather displays it triggers a warning about nothing.
        $configured = ltrim(trim((string) config('messaging.telegram.bot_username')), '@');

        if ($configured === '') {
            $this->warn("TELEGRAM_BOT_USERNAME is not set — add TELEGRAM_BOT_USERNAME={$username} to .env, or the Invite button on the Customers screen has no link to build.");
        } elseif (strcasecmp($configured, (string) $username) !== 0) {
            $this->warn("TELEGRAM_BOT_USERNAME is '{$configured}' — it should be '{$username}'.");
        }

        $info = $api->call('getWebhookInfo')->json();
        $url = data_get($info, 'result.url');

        $this->line($url
            ? "Webhook: {$url}"
            : 'Webhook: not set (telegram:poll can be used)');

        if ($pending = data_get($info, 'result.pending_update_count')) {
            $this->line("Waiting updates: {$pending}");
        }

        if ($error = data_get($info, 'result.last_error_message')) {
            $this->warn('Last delivery error: '.$error);
        }

        return self::SUCCESS;
    }

    protected function set(TelegramApi $api): int
    {
        $secret = config('messaging.telegram.webhook_secret');

        if (blank($secret)) {
            // Without it the webhook URL is the only thing standing between a
            // stranger and the ability to post fake /start updates — which would
            // let them link their own chat to another person's customer record.
            $this->error('TELEGRAM_WEBHOOK_SECRET is empty. Set one before exposing a webhook.');
            $this->line('Generate one with: php artisan telegram:webhook --set after adding a random 32+ character value to .env');

            return self::FAILURE;
        }

        $url = route('telegram.webhook');

        if (! str_starts_with($url, 'https://')) {
            // Telegram will not deliver to plain HTTP, and it will not tell you
            // why in any way you would notice.
            $this->error("Telegram only delivers to HTTPS. APP_URL is currently producing: {$url}");

            return self::FAILURE;
        }

        $response = $api->setWebhook($url, $secret)->json();

        if (data_get($response, 'ok') !== true) {
            $this->error('Telegram refused: '.data_get($response, 'description', 'unknown error'));

            return self::FAILURE;
        }

        $this->info("Webhook set to {$url}");

        return self::SUCCESS;
    }

    protected function delete(TelegramApi $api): int
    {
        $response = $api->deleteWebhook()->json();

        if (data_get($response, 'ok') !== true) {
            $this->error('Telegram refused: '.data_get($response, 'description', 'unknown error'));

            return self::FAILURE;
        }

        $this->info('Webhook removed. telegram:poll can now be used.');

        return self::SUCCESS;
    }
}
