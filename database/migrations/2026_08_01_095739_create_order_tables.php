<?php

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Order module (EP5, EP8, EP10).
 *
 * Orders snapshot everything that affects money or delivery — item names,
 * prices, tax rates and the delivery address. A merchant renaming a dish or a
 * customer deleting an address must never alter a historical order or the
 * invoice generated from it (EP11).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            // Human-facing reference shown to customer, merchant and rider.
            $table->string('order_number', 20)->unique();

            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('rider_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('zone_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('status', OrderStatus::values())
                ->default(OrderStatus::PendingPayment->value)->index();
            $table->enum('fulfilment_type', FulfilmentType::values())
                ->default(FulfilmentType::Delivery->value);

            // Delivery address snapshot — null for self-pickup.
            $table->string('delivery_contact_name')->nullable();
            $table->string('delivery_contact_phone', 15)->nullable();
            $table->string('delivery_line1')->nullable();
            $table->string('delivery_line2')->nullable();
            $table->string('delivery_landmark')->nullable();
            $table->string('delivery_city')->nullable();
            $table->string('delivery_pincode', 10)->nullable();
            $table->decimal('delivery_latitude', 10, 7)->nullable();
            $table->decimal('delivery_longitude', 10, 7)->nullable();
            $table->unsignedInteger('distance_metres')->nullable();

            // Money. Every component is stored so an invoice can be rebuilt
            // exactly, without recomputing from current fee configuration.
            $table->decimal('items_total', 10, 2)->default(0);
            $table->decimal('packaging_fee', 8, 2)->default(0);
            $table->decimal('delivery_fee', 8, 2)->default(0);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('tax_total', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2)->default(0);
            $table->decimal('commission_amount', 10, 2)->default(0);
            $table->decimal('merchant_payout', 10, 2)->default(0);

            $table->string('pickup_code', 8)->nullable();
            $table->text('customer_note')->nullable();

            // Lifecycle timestamps — the source data for delivery-time analytics.
            $table->timestamp('placed_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->timestamp('assigned_at')->nullable();
            $table->timestamp('picked_up_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedSmallInteger('estimated_prep_minutes')->nullable();
            $table->timestamp('estimated_delivery_at')->nullable();

            $table->enum('cancelled_by', ['customer', 'merchant', 'rider', 'admin', 'system'])->nullable();
            $table->string('cancellation_reason')->nullable();
            $table->decimal('cancellation_fee', 8, 2)->default(0);

            $table->timestamps();
            $table->softDeletes();

            // Merchant order list, customer history, and dispatch queue reads.
            $table->index(['merchant_id', 'status']);
            $table->index(['user_id', 'created_at']);
            $table->index(['rider_id', 'status']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            // Kept for reporting; nulled rather than blocking a menu cleanup.
            $table->foreignId('menu_item_id')->nullable()->constrained()->nullOnDelete();

            $table->string('name');
            $table->decimal('unit_price', 8, 2);
            $table->unsignedSmallInteger('quantity');
            $table->decimal('options_total', 8, 2)->default(0);
            $table->decimal('line_total', 10, 2);
            $table->decimal('gst_rate', 5, 2)->default(5.00);
            $table->decimal('tax_amount', 8, 2)->default(0);
            $table->boolean('is_veg')->default(true);
            $table->string('notes')->nullable();
            $table->timestamps();

            $table->index('order_id');
        });

        Schema::create('order_item_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('item_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name');
            $table->string('name');
            $table->decimal('price_delta', 8, 2)->default(0);
            $table->timestamps();

            $table->index('order_item_id');
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->enum('from_status', OrderStatus::values())->nullable();
            $table->enum('to_status', OrderStatus::values());
            $table->foreignId('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('note')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');
        Schema::dropIfExists('order_item_options');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
    }
};
