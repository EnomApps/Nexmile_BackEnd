<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a rider is owed for one delivery, and the facts it was worked out from.
 *
 * Snapshotted onto the order the same way commission is. A payout recalculated
 * later from current rates would quietly restate what someone was already
 * paid, and a rider who cannot check last week's number against last week's
 * rates has no way to trust any of them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            /*
             * Where the rider was when they took the job. The first mile is
             * measured from here, and it cannot be reconstructed afterwards —
             * a rider's position exists only in a Redis set that expires.
             */
            $table->decimal('accepted_latitude', 10, 7)->nullable()->after('assigned_at');
            $table->decimal('accepted_longitude', 10, 7)->nullable()->after('accepted_latitude');

            /*
             * Set by geofence when a rider's ping first lands near the
             * restaurant. Waiting is the gap between arriving and collecting;
             * without this the only measurable gap is travel plus waiting,
             * which pays a rider for their own journey twice.
             */
            $table->timestamp('arrived_at')->nullable()->after('accepted_longitude');

            $table->unsignedInteger('first_mile_metres')->nullable()->after('arrived_at');
            $table->unsignedInteger('last_mile_metres')->nullable()->after('first_mile_metres');
            $table->unsignedSmallInteger('waiting_minutes')->nullable()->after('last_mile_metres');

            // What was actually earned, and the arithmetic behind it, so a
            // rider disputing a figure can be shown the parts rather than told
            // the total again.
            $table->decimal('rider_payout', 8, 2)->nullable()->after('merchant_payout');
            $table->json('rider_payout_breakdown')->nullable()->after('rider_payout');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'accepted_latitude', 'accepted_longitude', 'arrived_at',
                'first_mile_metres', 'last_mile_metres', 'waiting_minutes',
                'rider_payout', 'rider_payout_breakdown',
            ]);
        });
    }
};
