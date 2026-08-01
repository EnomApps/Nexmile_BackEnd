<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Menu module (EP3): categories, items, and the option groups that drive
 * customisation such as spice level and add-ons (EP5).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('description')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id', 'is_active', 'sort_order']);
        });

        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->text('description')->nullable();
            $table->string('image_path')->nullable();

            $table->decimal('price', 8, 2);
            // Struck-through "was" price; null when the item is not discounted.
            $table->decimal('compare_at_price', 8, 2)->nullable();

            // 5% for most prepared food, 18% for some categories (EP11).
            $table->decimal('gst_rate', 5, 2)->default(5.00);

            $table->boolean('is_veg')->default(true);
            $table->boolean('contains_egg')->default(false);
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('prep_time_minutes')->default(10);
            $table->unsignedSmallInteger('sort_order')->default(0);

            // Food Rescue / surplus deals (EP14).
            $table->boolean('is_surplus_deal')->default(false);
            $table->timestamp('surplus_available_from')->nullable();
            $table->timestamp('surplus_available_until')->nullable();
            $table->unsignedSmallInteger('surplus_quantity')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['merchant_id', 'is_available']);
            $table->index(['merchant_id', 'is_surplus_deal']);
        });

        Schema::create('item_option_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('menu_item_id')->constrained()->cascadeOnDelete();
            $table->string('name');                                   // e.g. "Spice level"
            $table->enum('selection', ['single', 'multiple'])->default('single');
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('min_selections')->default(0);
            $table->unsignedSmallInteger('max_selections')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['menu_item_id', 'sort_order']);
        });

        Schema::create('item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('item_option_group_id')->constrained()->cascadeOnDelete();
            $table->string('name');                                   // e.g. "Extra spicy"
            $table->decimal('price_delta', 8, 2)->default(0);
            $table->boolean('is_available')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['item_option_group_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('item_options');
        Schema::dropIfExists('item_option_groups');
        Schema::dropIfExists('menu_items');
        Schema::dropIfExists('categories');
    }
};
