<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Merchandising content for the home screen.
 *
 * The point of these tables is that the home screen can be re-ordered, added
 * to or emptied from the server. Hard-coding the sections in the app makes
 * every seasonal banner a Play Store submission and a week of waiting.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('image_path');
            // Not decoration: a screen reader has nothing else to announce.
            $table->string('alt_text');

            /*
             * Where a tap goes. Kept as a type and a value rather than a URL
             * so the app routes internally — a deep link into the app is not
             * the same as opening a browser on top of it.
             */
            $table->string('action_type', 20)->default('none');
            $table->string('action_value')->nullable();

            // A campaign that ends on its own needs nobody awake at midnight.
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();

            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'position']);
        });

        Schema::create('cuisines', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name');
            $table->string('image_path')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('collections', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('title');
            $table->string('subtitle')->nullable();
            $table->string('banner_path')->nullable();

            /*
             * A collection is curated, not computed. "Meals under ₹250" sounds
             * like a query, but the ones that work are chosen — a query would
             * fill it with the cheapest thing every kitchen sells.
             */
            $table->unsignedInteger('position')->default(0);
            $table->boolean('is_active')->default(true);
            $table->boolean('show_on_home')->default(true);
            $table->timestamps();
        });

        Schema::create('collection_merchant', function (Blueprint $table) {
            $table->id();
            $table->foreignId('collection_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);

            $table->unique(['collection_id', 'merchant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('collection_merchant');
        Schema::dropIfExists('collections');
        Schema::dropIfExists('cuisines');
        Schema::dropIfExists('banners');
    }
};
