<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery micro-zones. Discovery and dispatch are both zone-locked, and the
 * radius is configurable per zone so ops can widen it during rider shortage
 * without a deploy (EP4, EP7, EP13).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('city');
            $table->string('state')->default('Tamil Nadu');

            $table->decimal('centre_latitude', 10, 7);
            $table->decimal('centre_longitude', 10, 7);

            // Normal operating radius, and the ceiling ops may escalate to.
            $table->unsignedInteger('radius_metres')->default(1000);
            $table->unsignedInteger('max_radius_metres')->default(3000);

            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['centre_latitude', 'centre_longitude']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zones');
    }
};
