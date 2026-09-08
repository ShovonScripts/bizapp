<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Nullable on purpose: business_id = null means super-admin (you / the boss),
            // who can see across all businesses.
            $table->foreignId('business_id')
                ->nullable()
                ->after('id')
                ->constrained()
                ->nullOnDelete();

            $table->string('role')->default('owner')->after('password'); // owner, staff, admin
            $table->timestamp('last_login_at')->nullable()->after('role');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['business_id']);
            $table->dropColumn(['business_id', 'role', 'last_login_at']);
        });
    }
};
