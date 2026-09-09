<?php

namespace App\Console\Commands;

use App\Models\ScheduledMessage;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Reads the outbox out loud.
 *
 * The whole reason `messages:plan` and `messages:dispatch` are two commands with a
 * table in between is that the table can be inspected — but until there is a
 * queue screen in the app, nothing actually inspects it, and "why didn't Sarah get
 * her reminder?" gets answered by opening a database client. This is the stopgap,
 * and it is the same question support will ask a year from now.
 *
 * ─── What is deliberately NOT printed ───────────────────────────────────────
 * No phone numbers, no Telegram chat ids, no bot tokens. Console output gets
 * pasted into chat threads and pull requests, and a chat id is enough to message
 * someone. Names are shown because an owner needs to recognise the row.
 */
class ShowMessageQueue extends Command
{
    protected $signature = 'messages:queue
                            {--status= : Only this status (pending, sent, failed, skipped, cancelled)}
                            {--limit=20 : How many rows}
                            {--body= : Print the full text of one message, by id}';

    protected $description = 'Show what is in the message outbox and why';

    public function handle(): int
    {
        if ($id = $this->option('body')) {
            return $this->showBody((int) $id);
        }

        /*
         * No tenant is set in a console run, so BelongsToBusiness adds no WHERE
         * clause and this covers every business — which is what someone debugging
         * the platform wants. Run it through `php artisan tinker` with a tenant
         * set if you ever need one business only.
         */
        $messages = ScheduledMessage::query()
            ->with(['customer', 'business'])
            ->when($this->option('status'), fn ($q, $status) => $q->where('status', $status))
            // Newest last: the terminal scrolls, so the row you care about should
            // end up nearest the prompt rather than off the top of the screen.
            ->orderByDesc('send_at')
            ->limit(max(1, (int) $this->option('limit')))
            ->get()
            ->reverse()
            ->values();

        if ($messages->isEmpty()) {
            $this->line($this->option('status')
                ? 'Nothing in the outbox with that status.'
                : 'The outbox is empty. Run messages:plan first.');

            return self::SUCCESS;
        }

        $this->table(
            ['#', 'Send at (local)', 'Business', 'Customer', 'Via', 'Status', 'Why / preview'],
            $messages->map(fn (ScheduledMessage $m) => [
                $m->id,
                $m->business
                    ? $m->business->toLocal($m->send_at)->format('D j M, H:i')
                    : $m->send_at->format('D j M, H:i').' UTC',
                Str::limit($m->business?->name ?? '(gone)', 18),
                // Soft-deleted by a GDPR erasure: the row survives, the person does
                // not. Saying so is more useful than a blank cell.
                Str::limit($m->customer?->name ?? '(removed)', 18),
                $m->channel,
                $this->badge($m->status),
                Str::limit($m->error ?? $this->firstLine($m->body()), 46),
            ])->all(),
        );

        $this->newLine();
        $this->line('Full text of one row:  php artisan messages:queue --body='.$messages->last()->id);

        return self::SUCCESS;
    }

    protected function showBody(int $id): int
    {
        $message = ScheduledMessage::query()->with(['customer', 'business'])->find($id);

        if (! $message) {
            $this->error("No message with id {$id}.");

            return self::FAILURE;
        }

        $this->line('To:      '.($message->customer?->name ?? '(removed)').' via '.$message->channel);
        $this->line('From:    '.($message->business?->name ?? '(gone)'));
        $this->line('Send at: '.($message->business
            ? $message->business->toLocal($message->send_at)->format('D j M Y, H:i').' local'
            : $message->send_at->format('D j M Y, H:i').' UTC'));
        $this->line('Status:  '.$this->badge($message->status).($message->attempts ? " (attempts: {$message->attempts})" : ''));

        if ($message->error) {
            $this->warn('Why:     '.$message->error);
        }

        $this->newLine();

        // Rendered at plan time, so this is byte-for-byte what goes out — which is
        // the only reason showing it here is worth anything.
        $this->line($message->body());

        return self::SUCCESS;
    }

    protected function badge(string $status): string
    {
        return match ($status) {
            ScheduledMessage::SENT => '<fg=green>sent</>',
            ScheduledMessage::PENDING => '<fg=yellow>pending</>',
            ScheduledMessage::FAILED => '<fg=red>failed</>',
            default => $status,
        };
    }

    protected function firstLine(string $body): string
    {
        return trim(strtok($body, "\n") ?: '');
    }
}
