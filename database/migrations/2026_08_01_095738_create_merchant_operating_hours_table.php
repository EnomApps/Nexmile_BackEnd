<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opening hours per weekday (EP3). Multiple rows per day are allowed so a
 * kitchen can close between lunch and dinner service.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_operating_hours', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // 0 = Sunday through 6 = Saturday, matching Carbon's dayOfWeek.
            $table->unsignedTinyInteger('day_of_week');
            $table->time('opens_at')->nullable();
            $table->time('closes_at')->nullable();
            $table->boolean('is_closed')->default(false);
            $table->timestamps();

            $table->index(['merchant_id', 'day_of_week']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_operating_hours');
    }
};
