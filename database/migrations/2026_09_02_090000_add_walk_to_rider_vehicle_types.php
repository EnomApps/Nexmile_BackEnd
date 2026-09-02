<?php

use App\Models\Rider;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Delivery on foot, and no more migrations to add a vehicle.
 *
 * Within a 1 km radius walking is a real way to work — a student between
 * classes, someone without a licence, a rider whose bike is being repaired.
 * Excluding them narrows the pool for no reason in exactly the market where
 * the distances are short enough to walk.
 *
 * The column becomes a plain string rather than gaining one more enum member.
 * Which vehicles Nexmile accepts is a product decision that will change again,
 * and an enum makes each change a schema migration on a live table. The list
 * now lives in config/kyc.php, enforced by validation on the way in — which is
 * where the useful error message is anyway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('riders', function (Blueprint $table) {
            $table->string('vehicle_type', 20)->default('motorcycle')->change();
        });
    }

    public function down(): void
    {
        // Anyone already walking becomes a cyclist rather than blocking the
        // rollback — both are unmotorised, so nothing about their paperwork
        // changes.
        Rider::where('vehicle_type', 'walk')->update(['vehicle_type' => 'bicycle']);

        Schema::table('riders', function (Blueprint $table) {
            $table->enum('vehicle_type', ['bicycle', 'motorcycle', 'scooter', 'ev'])
                ->default('motorcycle')
                ->change();
        });
    }
};
