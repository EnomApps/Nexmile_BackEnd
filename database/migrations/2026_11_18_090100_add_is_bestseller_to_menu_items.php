<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which dishes a restaurant is actually known for.
 *
 * A stored flag rather than a live aggregate. Working it out per request means
 * a GROUP BY over order_items on every menu open, for an answer that changes
 * once a day at most. `nexmile:mark-bestsellers` recomputes it overnight.
 *
 * Indexed with merchant_id because the menu is always read a restaurant at a
 * time, never across the platform.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->boolean('is_bestseller')->default(false)->after('is_available');
            $table->index(['merchant_id', 'is_bestseller']);
        });
    }

    public function down(): void
    {
        Schema::table('menu_items', function (Blueprint $table) {
            $table->dropIndex(['merchant_id', 'is_bestseller']);
            $table->dropColumn('is_bestseller');
        });
    }
};
