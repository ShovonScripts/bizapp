<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('appointments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();

            // nullOnDelete, not cascade: deleting a service must not erase the
            // history of appointments that used it. Same for staff who leave.
            $table->foreignId('service_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('staff_member_id')->nullable()->constrained()->nullOnDelete();

            // Stored UTC. Converted to the business timezone for display AND for
            // deciding reminder timing — see Business::toLocal() / Business::now().
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');

            // String, not enum — see customers migration for why.
            // pending | confirmed | completed | cancelled | no_show
            $table->string('status', 20)->default('confirmed');

            // The price AT BOOKING TIME. Copied from the service, then frozen.
            // If the salon raises prices next month, last month's revenue figures
            // must not silently change.
            $table->decimal('price', 10, 2)->default(0);

            $table->text('notes')->nullable();

            // manual | inquiry | web_form | import
            $table->string('source', 20)->default('manual');

            // Set when the 24h reminder actually goes out. Purely for debugging
            // "why didn't my customer get a reminder" — the real dedupe lives in
            // scheduled_messages.
            $table->timestamp('reminded_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Calendar / day view / "tomorrow's appointments" for the planner.
            $table->index(['business_id', 'starts_at']);
            $table->index(['business_id', 'status']);

            // The reminder planner scans forward across ALL businesses:
            //   where status in (pending, confirmed) and starts_at between ? and ?
            // so it needs an index that does not start with business_id.
            $table->index(['status', 'starts_at']);

            $table->index(['customer_id', 'starts_at']);       // customer history
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('appointments');
    }
};
