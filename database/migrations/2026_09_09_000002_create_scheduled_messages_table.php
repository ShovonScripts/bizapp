<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The outbox. `messages:plan` writes rows here, `messages:dispatch` sends them.
 *
 * Two commands and a table in between, rather than one command that finds and
 * sends, for three reasons that all matter on shared hosting:
 *
 *   1. A row that exists before anything is sent is a row you can inspect. "Why
 *      didn't my customer get a reminder" is answerable — the row is there with
 *      status `skipped` and a reason, or it was never planned at all.
 *   2. Sending is the part that fails (network, rate limits, an expired token).
 *      Retries need somewhere to count attempts.
 *   3. The planner can run every five minutes while the sender runs every
 *      minute, so a message goes out close to its due time without re-scanning
 *      every appointment sixty times an hour.
 *
 * ─── NO UNIQUE INDEX ON (related, template_key) ─────────────────────────────
 * It looks like the obvious way to stop duplicates and it is wrong, because the
 * right rule is not the same for every template. An appointment reminder goes out
 * once and only once. Chasing an unpaid invoice goes out weekly until it is paid,
 * and a customer who lapses a second time a year later should get a second
 * win-back. A unique index blocks the last two forever.
 *
 * So dedupe lives in code, per template, where the rule can differ:
 *
 *   appointment_reminder_24h — any status EXCEPT `cancelled` blocks a new row.
 *                              `cancelled` means the booking moved, so a fresh
 *                              reminder for the new time SHOULD be planned. See
 *                              ReminderPlanner::alreadyPlanned(), which is the
 *                              one place that rule is written down.
 *
 * MySQL has no partial unique index, so none of this can be expressed in the
 * schema at all — which is why the indexes below are plain, non-unique, and exist
 * only for speed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            /*
             * cascadeOnDelete, unlike appointments.service_id.
             *
             * A pending message to a hard-deleted customer has nobody to go to.
             * Customer deletion is a soft delete in this app, so this only fires
             * on a real GDPR erasure — at which point removing the queued
             * message is exactly right.
             */
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // Resolved at PLAN time and frozen, not decided during sending.
            // whatsapp | telegram | email | sms
            $table->string('channel', 20);

            // Which message this is: appointment_reminder_24h, win_back, etc.
            // Also the dedupe key, together with the related record.
            $table->string('template_key', 60);

            /*
             * The rendered text plus the variables it was built from.
             *
             * Rendering at plan time rather than send time is deliberate: the
             * message an owner can preview in the queue is byte-for-byte the one
             * that goes out. It also means a service being renamed after
             * planning cannot silently change a message already approved.
             */
            $table->json('payload');

            // UTC, like every timestamp here. Quiet hours are applied by the
            // planner in business-local time and the result stored back as UTC.
            $table->dateTime('send_at');

            /*
             * pending   — waiting for its send_at
             * sent      — handed to the provider successfully
             * failed    — gave up after retries
             * cancelled — the underlying appointment was cancelled or moved
             * skipped   — deliberately not sent (no consent, no contact route,
             *             quiet hours pushed it past the appointment itself)
             *
             * `skipped` earns its place: without it, "not sent" and "never
             * planned" are indistinguishable, and the honest answer to an owner
             * asking why is the difference between the two.
             */
            $table->string('status', 20)->default('pending');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('sent_at')->nullable();

            // Why it failed or was skipped. Shown to the owner, so it has to be
            // a sentence, not an exception dump.
            $table->text('error')->nullable();

            /*
             * What this message is about — appointment today, invoice later.
             * Morph rather than a nullable FK per type, because otherwise every
             * new automation adds a column.
             *
             * Nullable: a broadcast to a customer relates to no single record.
             */
            $table->string('related_type')->nullable();
            $table->unsignedBigInteger('related_id')->nullable();

            $table->timestamps();

            // The dedupe lookup in messages:plan.
            $table->index(['related_type', 'related_id', 'status']);

            /*
             * The dispatcher's only query: status = 'pending' and send_at <= now.
             * It runs every minute across ALL businesses, so this index must not
             * start with business_id.
             */
            $table->index(['status', 'send_at']);

            // "What is queued for this business" — the owner-facing view.
            $table->index(['business_id', 'status']);

            // Cancelling every pending message for one customer on erasure.
            $table->index(['customer_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_messages');
    }
};
