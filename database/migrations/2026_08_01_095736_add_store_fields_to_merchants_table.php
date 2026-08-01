<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A merchant is also the storefront customers browse — one merchant, one
 * outlet. If multi-outlet is ever needed, the outlet-level columns here move
 * to a `stores` table and menus/orders repoint at store_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->foreignId('zone_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();

            $table->enum('service_category', [
                'food', 'grocery', 'pharmacy', 'fruits_vegetables', 'bakery',
                'flowers', 'pet_supplies', 'stationery', 'electronics',
                'daily_essentials', 'courier',
            ])->default('food')->after('business_name')->index();

            $table->text('description')->nullable()->after('service_category');
            $table->string('logo_path')->nullable()->after('description');
            $table->string('banner_path')->nullable()->after('logo_path');

            // Averaged prep time; the dispatch estimator refines this per order.
            $table->unsignedSmallInteger('avg_prep_time_minutes')->default(15)
                ->after('is_accepting_orders');
            $table->decimal('packaging_fee', 8, 2)->default(0)->after('avg_prep_time_minutes');
            $table->decimal('min_order_value', 8, 2)->default(0)->after('packaging_fee');
            $table->decimal('commission_rate', 5, 2)->default(0)->after('min_order_value');
            $table->boolean('supports_pickup')->default(true)->after('commission_rate');
        });
    }

    public function down(): void
    {
        Schema::table('merchants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('zone_id');
            $table->dropIndex(['service_category']);
            $table->dropColumn([
                'service_category', 'description', 'logo_path', 'banner_path',
                'avg_prep_time_minutes', 'packaging_fee', 'min_order_value',
                'commission_rate', 'supports_pickup',
            ]);
        });
    }
};
