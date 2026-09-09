<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per (business, channel): how THIS business sends on THAT channel.
 *
 * Telegram does not strictly need this — one platform-wide bot can serve every
 * salon, and that is what we ship first. WhatsApp does: Meta issues a
 * phone_number_id and token per business, so credentials cannot live in .env.
 *
 * Building the table now rather than later is deliberate. The alternative is a
 * driver that reads config() today and a per-business row tomorrow, which means
 * rewriting the resolution path once real clients are on it. This way the shape
 * is right from the start and the Telegram driver simply falls back to config
 * when no row exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // whatsapp | telegram | email | sms
            // String, not enum: adding a channel must not need an ALTER.
            $table->string('channel', 20);

            /*
             * Cast to `encrypted:json` on the model. Bot tokens and Meta access
             * tokens are live credentials — a leaked DB dump must not hand
             * someone the ability to message every client's customers.
             *
             * Nullable because a connection can exist in `pending` before its
             * credentials arrive (Meta verification takes days).
             *
             * Consequence to remember: encrypted columns cannot be searched or
             * indexed. Anything needed in a WHERE clause belongs in `meta`.
             */
            $table->text('credentials')->nullable();

            // pending | active | failed
            $table->string('status', 20)->default('pending');

            // Last time we confirmed the credentials actually work (getMe, etc.).
            $table->timestamp('verified_at')->nullable();

            // Last failure, so an owner can be told WHY their channel is dead
            // instead of just seeing "failed".
            $table->text('error')->nullable();

            /*
             * Non-secret, queryable settings: bot_username, phone_number_id,
             * waba_id. Kept out of `credentials` precisely because these are the
             * values we need to read and search.
             */
            $table->json('meta')->nullable();

            $table->timestamps();

            // A business has at most one connection per channel. Unlike the
            // scheduled_messages case, this really is a hard rule: two Telegram
            // bots for one salon has no meaning, and the ambiguity would show up
            // as reminders going out from whichever row was found first.
            $table->unique(['business_id', 'channel']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_connections');
    }
};
