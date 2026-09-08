<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('businesses', function (Blueprint $table) {
            $table->id();

            $table->string('name');
            $table->string('slug')->unique();

            // Deliberately string, not enum: MySQL enums are painful to ALTER
            // later, and we will certainly add niches and statuses over time.
            $table->string('niche')->default('salon');       // salon, gym, clinic, tuition, other
            $table->string('subscription_status')->default('trialing'); // trialing, active, past_due, cancelled

            $table->string('timezone')->default('Europe/London');
            $table->char('currency', 3)->default('GBP');

            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->text('address')->nullable();
            $table->string('logo_path')->nullable();

            // Free-form preference bag: quiet_hours, default_channel, invoice_prefix...
            $table->json('settings')->nullable();

            $table->timestamp('trial_ends_at')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('businesses');
    }
};
