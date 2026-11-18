<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A photo of the door, so a rider stops ringing the wrong bell.
 *
 * In Tier-2 and Tier-3 towns a plot number is frequently the only address a
 * building has, and often it is painted on a wall somewhere other than the
 * gate. "Second floor, green door, past the water tank" is a sentence nobody
 * types but a photo says in one look.
 *
 * Stored on the private media disk and read through a signed URL, like KYC
 * documents — see config/media.php. It is a picture of where somebody lives,
 * so it never becomes a public object.
 *
 * The order carries its own copy of the path because orders snapshot the
 * address rather than referencing it: editing or deleting an address must
 * never rewrite order history, and that includes the photo a rider was shown.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->string('photo_path')->nullable()->after('landmark');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('delivery_photo_path')->nullable()->after('delivery_landmark');
        });
    }

    public function down(): void
    {
        Schema::table('addresses', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn('delivery_photo_path');
        });
    }
};
