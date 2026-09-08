<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');

            // E.164 always: +447700900123. Never store "07700 900123".
            // Every channel (WhatsApp, SMS) needs E.164, and it is the only
            // format that lets us dedupe reliably.
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();

            // Null means "use phone". Some people use a different number for WhatsApp.
            $table->string('whatsapp_number', 32)->nullable();

            // Telegram chat IDs are int64 and can be negative (groups).
            // Stored as string to sidestep any overflow/precision surprises.
            $table->string('telegram_chat_id', 64)->nullable();

            // Deep-link token: https://t.me/<bot>?start=<token>
            // The webhook arrives with NO tenant context, so this must be
            // globally unique — that is how we find which customer started the bot.
            $table->string('telegram_link_token', 64)->nullable()->unique();

            // String, not enum: MySQL enums need an ALTER to add a value,
            // and we will add channels later.
            // whatsapp | telegram | email | sms | none
            $table->string('preferred_channel', 20)->default('telegram');

            /* ---------------- GDPR ----------------
             | Transactional messages (your appointment is tomorrow) rest on
             | legitimate interest / contract. Marketing (we miss you, 20% off)
             | needs consent. Two different things, so two different flags.
             | consent_at + consent_source are the evidence if anyone ever asks.
             */
            $table->boolean('marketing_consent')->default(false);
            $table->timestamp('consent_at')->nullable();
            $table->string('consent_source', 50)->nullable(); // booking_form, verbal, import, web_form
            $table->timestamp('unsubscribed_at')->nullable();  // set = no marketing, ever

            $table->text('notes')->nullable();
            $table->json('tags')->nullable();

            // Denormalised on purpose. The win-back rule ("no visit in 90 days")
            // and the dashboard would otherwise aggregate appointments on every
            // page load, which gets slow fast on shared hosting.
            // Kept fresh by customers:refresh-stats (nightly) + an observer.
            $table->timestamp('last_visit_at')->nullable();
            $table->decimal('total_spend', 10, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Same phone twice in one business = same person. Blocks the most
            // common data-entry mistake. MySQL allows many NULLs in a unique
            // index, so customers without a phone are still fine.
            $table->unique(['business_id', 'phone']);

            $table->index(['business_id', 'last_visit_at']);   // win-back queries
            $table->index('telegram_chat_id');                 // webhook lookup, unscoped
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
