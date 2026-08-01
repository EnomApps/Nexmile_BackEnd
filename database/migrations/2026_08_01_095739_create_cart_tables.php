<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Server-side cart (EP5). One open cart per customer per merchant — mixing
 * merchants in a single cart would break the 1 km single-pickup model.
 *
 * Carts hold live references to menu items so prices stay current while the
 * customer is still shopping; orders snapshot instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'merchant_id']);
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('quantity')->default(1);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('cart_id');
        });

        Schema::create('cart_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_option_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['cart_item_id', 'item_option_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cart_item_options');
        Schema::dropIfExists('cart_items');
        Schema::dropIfExists('carts');
    }
};
