<?php

namespace App\Console\Commands;

use App\Messaging\Telegram\TelegramApi;
use App\Messaging\Telegram\UpdateHandler;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * Reads incoming Telegram messages by polling, so linking can be tested on XAMPP.
 *
 * Telegram will only push updates to an HTTPS URL it can reach, which localhost
 * is not. The usual answer is a tunnel like ngrok; this is the answer that needs
 * no extra account, no expiring URL and no firewall conversation — you run one
 * command in a second terminal and press Start on your phone.
 *
 * It shares UpdateHandler with the production webhook, so what you test here is
 * the code that will run on the server. Only the transport differs.
 *
 *   php artisan telegram:poll          # keep running, Ctrl+C to stop
 *   php artisan telegram:poll --once   # drain what's waiting and exit
 */
class PollTelegram extends Command
{
    protected $signature = 'telegram:poll
                            {--once : Process whatever is waiting, then stop}
                            {--seconds=20 : How long each long-poll waits}';

    protected $description = 'Poll Telegram for incoming messages (local development)';

    /**
     * Where the read position lives between runs.
     *
     * Telegram replays any update that has not been acknowledged, so without this
     * every restart would re-process the same /start and the "already used" guard
     * would start refusing the customer their own link.
     */
    protected const OFFSET_KEY = 'telegram.poll.offset';

    public function handle(TelegramApi $api, UpdateHandler $handler): int
    {
        if (! $api->isConfigured()) {
            $this->error('TELEGRAM_BOT_TOKEN is not set in .env. Get one from @BotFather first.');

            return self::FAILURE;
        }

        $this->info('Listening for Telegram messages. Press Ctrl+C to stop.');

        do {
            $count = $this->drain($api, $handler);

            if ($count === null) {
                return self::FAILURE;
            }
        } while (! $this->option('once'));

        return self::SUCCESS;
    }

    /** @return int|null Null means stop — something is wrong that waiting will not fix. */
    protected function drain(TelegramApi $api, UpdateHandler $handler): ?int
    {
        $offset = (int) Cache::get(static::OFFSET_KEY, 0);

        try {
            $response = $api->getUpdates($offset, (int) $this->option('seconds'));
        } catch (ConnectionException $e) {
            // A dropped wifi connection should not end the session — that is the
            // one thing guaranteed to happen while you are waiting on your phone.
            $this->warn('Could not reach Telegram, retrying: '.$e->getMessage());
            sleep(3);

            return 0;
        }

        $payload = $response->json() ?? [];

        if (data_get($payload, 'ok') !== true) {
            // 409 means a webhook is registered for this bot. Telegram refuses to
            // serve both, and silently deleting the webhook here could switch off
            // a live deployment from a laptop.
            if ($response->status() === 409) {
                $this->error('This bot has a webhook registered, so polling is refused by Telegram.');
                $this->line('Run `php artisan telegram:webhook --delete` first if this bot is not live yet.');

                return null;
            }

            $this->error('Telegram said: '.data_get($payload, 'description', 'unknown error'));

            return null;
        }

        $updates = data_get($payload, 'result', []);

        foreach ($updates as $update) {
            $this->process($api, $handler, $update);

            // Advanced per update, not once at the end: if the handler throws
            // halfway through a batch, the ones already dealt with must not come
            // back around on the next poll.
            Cache::forever(static::OFFSET_KEY, ((int) data_get($update, 'update_id')) + 1);
        }

        return count($updates);
    }

    protected function process(TelegramApi $api, UpdateHandler $handler, array $update): void
    {
        $from = data_get($update, 'message.from.first_name', 'someone');
        $text = data_get($update, 'message.text', '');

        $this->line("  <fg=gray>←</> {$from}: {$text}");

        $reply = $handler->handle($update);

        if (! $reply) {
            return;
        }

        try {
            $api->sendMessage($reply['chat_id'], $reply['text']);
            $this->line('  <fg=gray>→</> '.str_replace("\n", ' ', $reply['text']));
        } catch (ConnectionException $e) {
            $this->warn('  Could not send the reply: '.$e->getMessage());
        }
    }
}
