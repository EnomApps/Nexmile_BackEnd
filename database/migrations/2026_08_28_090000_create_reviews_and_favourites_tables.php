<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ratings (EP12) and favourites.
 *
 * The customer app has been showing a rating badge on every restaurant card
 * with nothing behind it. A review belongs to an order, not to a customer and
 * a restaurant: it is the only proof the person actually ate there, and it is
 * what stops the same account rating a shop fifty times.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();

            // One review per order, enforced by the database rather than by
            // remembering to check.
            $table->foreignId('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();

            // Nullable: an order may have been collected by the customer, and
            // a rider is rated separately from the food.
            $table->foreignId('rider_id')->nullable()->constrained()->nullOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->unsignedTinyInteger('rider_rating')->nullable();
            $table->text('comment')->nullable();

            $table->timestamps();

            // The merchant's own reviews list, newest first.
            $table->index(['merchant_id', 'created_at']);
        });

        Schema::table('merchants', function (Blueprint $table) {
            /*
             * Denormalised on purpose. Every restaurant card shows a rating,
             * so a nearby search would otherwise aggregate the reviews table
             * once per result on the hottest query in the product.
             *
             * Null, never 0.0, until there are enough ratings to mean
             * anything — the app hides the badge rather than showing "0.0",
             * which reads as "bad" instead of "new".
             */
            $table->decimal('rating', 3, 2)->nullable()->after('supports_pickup');
            $table->unsignedInteger('rating_count')->default(0)->after('rating');

            // Card and filter fields the app asked for.
            $table->boolean('is_pure_veg')->default(false)->after('rating_count');
            $table->unsignedInteger('cost_for_two')->nullable()->after('is_pure_veg');
            $table->json('cuisines')->nullable()->after('cost_for_two');
        });

        Schema::create('favourites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            // Tapping the bookmark twice must not create two rows.
            $table->unique(['user_id', 'merchant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('favourites');
        Schema::dropIfExists('reviews');

        Schema::table('merchants', function (Blueprint $table) {
            $table->dropColumn(['rating', 'rating_count', 'is_pure_veg', 'cost_for_two', 'cuisines']);
        });
    }
};
