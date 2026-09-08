<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('staff_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            // Optional login. Most small salons will have staff who never log in —
            // the owner books for everyone. So this stays nullable.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('color', 7)->default('#6366f1');  // calendar colour, #rrggbb
            $table->boolean('active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['business_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_members');
    }
};
