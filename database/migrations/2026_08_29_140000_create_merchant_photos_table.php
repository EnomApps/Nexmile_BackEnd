<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photo gallery per restaurant.
 *
 * One banner was enough to head a page and not enough to sell a place. A
 * customer deciding between two kitchens they have never visited wants to see
 * the room, the counter, the food going out — which is a carousel, not a
 * single hero image.
 *
 * Separate from merchants.banner_path rather than replacing it: the banner is
 * the one image guaranteed to exist and the apps already render it. This adds
 * to that instead of breaking it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchant_photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            $table->string('image_path');

            /*
             * Optional, and shown as the slide's alt text. A photo of the
             * dining room and a photo of the kitchen are different promises,
             * and a screen reader has nothing else to announce.
             */
            $table->string('caption', 120)->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            // The gallery is fetched in order, per restaurant, on every
            // storefront view.
            $table->index(['merchant_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('merchant_photos');
    }
};
