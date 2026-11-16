<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where a rider actually went, kept long enough to settle an argument.
 *
 * Live positions live in Redis, latest only, because dispatch asks "who is
 * near this restaurant now" and never "where was this rider at 14:30". That
 * answered every question the platform had until somebody disputed a
 * delivery — and then there was nothing to look at, because the only stored
 * fix was the one that had overwritten all the others.
 *
 * This is the trail, and it is deliberately not the ping stream. The rider
 * app pings every ten seconds while carrying; a parked rider would write 360
 * identical rows an hour, which is the write rate `config/tracking.php` warns
 * about and the reason none of this was stored before. Only pings that moved
 * the rider more than `tracking.history.min_metres` are kept, so a stationary
 * rider costs one row and the trail still reads continuously on a map.
 *
 * Rows are written once and never updated — so there is no `updated_at`, and
 * no model event to fire on a path that runs on every ping.
 *
 * **Retention is not optional.** Continuous location history of a worker is
 * personal data, so this table is swept by `nexmile:prune-location-history`
 * on a schedule and `tracking.history.retention_days` is the stated period.
 * Turning the sweep off is a decision about the law, not about disk.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rider_location_pings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();

            /*
             * The order being carried, when there was one. The whole reason
             * this table exists is "show me where the rider was on order
             * 1482", and deriving that from timestamps afterwards means
             * guessing which side of a handover a fix fell on.
             *
             * Nulled rather than deleted with the order: the trail is still
             * the rider's working history even once the order is gone.
             */
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();

            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);

            // Which way the bike was pointing, and how fast, when the phone
            // reported them. Both optional — not every fix carries either.
            $table->decimal('heading', 5, 2)->nullable();
            $table->decimal('speed', 6, 2)->nullable();

            /*
             * How much the phone trusted the fix. A trail that wanders into
             * the next street is usually a 200-metre accuracy reading indoors
             * rather than a rider who went there, and without this column
             * there is no way to tell those apart after the fact.
             */
            $table->unsignedSmallInteger('accuracy_metres')->nullable();

            // When the phone took the fix, not when it reached us. The gap
            // between this and created_at is the only evidence of lost signal.
            $table->timestamp('recorded_at');

            $table->timestamp('created_at')->nullable();

            // Reading a rider's day, and reading one delivery. Both are
            // always ordered by time, so both indexes carry it.
            $table->index(['rider_id', 'recorded_at']);
            $table->index(['order_id', 'recorded_at']);

            // The pruner deletes by age across every rider at once.
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_location_pings');
    }
};
