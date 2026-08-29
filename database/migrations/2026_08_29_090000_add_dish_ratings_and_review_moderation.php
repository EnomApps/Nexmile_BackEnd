<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-dish ratings, and a way to take an abusive review down.
 *
 * A restaurant score answers "is this place any good". It cannot answer "is
 * the biryani any good", which is the question a customer actually has in
 * front of a menu — and the one a kitchen can act on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('review_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('review_id')->constrained()->cascadeOnDelete();

            /*
             * The dish as it exists now. A deleted dish takes its ratings with
             * it rather than leaving orphans propping up an average for
             * something nobody can order.
             */
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();

            $table->unsignedTinyInteger('rating');
            $table->timestamps();

            // One rating per dish per review: a customer cannot rate the same
            // biryani three times from one order.
            $table->unique(['review_id', 'menu_item_id']);
            $table->index(['menu_item_id', 'created_at']);
        });

        Schema::table('menu_items', function (Blueprint $table) {
            /*
             * Denormalised for the same reason the merchant's is: a menu
             * renders forty dishes, and aggregating per dish on every menu
             * load is forty queries for a number that changes hourly at most.
             */
            $table->decimal('rating', 3, 2)->nullable()->after('is_available');
            $table->unsignedInteger('rating_count')->default(0)->after('rating');
        });

        Schema::table('reviews', function (Blueprint $table) {
            /*
             * Hidden, not deleted. A removed review still counts as evidence
             * if the customer disputes the takedown, and a merchant accused of
             * buying good ratings is defended by the record of what was
             * removed and why.
             */
            $table->timestamp('hidden_at')->nullable()->after('comment');
            $table->foreignId('hidden_by_user_id')->nullable()->after('hidden_at')
                ->constrained('users')->nullOnDelete();
            $table->string('hidden_reason')->nullable()->after('hidden_by_user_id');

            $table->index(['merchant_id', 'hidden_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('review_items');

        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropColumn(['rating', 'rating_count']);
        });

        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hidden_by_user_id');
            $table->dropColumn(['hidden_at', 'hidden_reason']);
        });
    }
};
