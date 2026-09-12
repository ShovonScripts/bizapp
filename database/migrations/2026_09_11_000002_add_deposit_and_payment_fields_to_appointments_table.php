<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->boolean('deposit_required')->default(false)->after('price');
            $table->decimal('deposit_amount', 10, 2)->default(0.00)->after('deposit_required');
            $table->string('deposit_status', 32)->default('unpaid')->after('deposit_amount'); // unpaid, paid, waived, refunded
            $table->decimal('paid_amount', 10, 2)->default(0.00)->after('deposit_status');
            $table->string('stripe_payment_intent_id')->nullable()->after('paid_amount');
            $table->string('stripe_session_id')->nullable()->after('stripe_payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn([
                'deposit_required',
                'deposit_amount',
                'deposit_status',
                'paid_amount',
                'stripe_payment_intent_id',
                'stripe_session_id',
            ]);
        });
    }
};
