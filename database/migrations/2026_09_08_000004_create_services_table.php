<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();

            $table->string('name');                          // Ladies Cut & Blow Dry
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('duration_minutes')->default(30);
            $table->decimal('price', 10, 2)->default(0);     // decimal, NEVER float

            $table->boolean('active')->default(true);        // retire a service without deleting history
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['business_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('services');
    }
};
