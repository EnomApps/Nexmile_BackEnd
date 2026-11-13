<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Offer-based dispatch: the ringing order card (EP7, EP8).
 *
 * Until now riders took work off an open board they polled. That is fine with
 * three riders staring at a screen and useless with thirty riding around town
 * — nobody reads a list while on a bike. This adds the other model: the order
 * is offered to the riders nearest the restaurant, their phones ring, and the
 * first to accept gets it.
 *
 * Four decisions in here are worth stating, because each one could have been
 * done the obvious way and should not be.
 *
 * **`orders.status` stops being an ENUM.** Two more lifecycle states arrive
 * with this ticket — reached the restaurant, reached the customer — and the
 * next product conversation will want another. An ENUM makes every one of
 * those an ALTER on the busiest table in the database. The list already lives
 * in App\Enums\OrderStatus and is already validated on the way in, which is
 * where the useful error message is. Same reasoning as the vehicle_type
 * migration, same one-time cost, and this is the last time it is paid.
 *
 * **Dispatch state is a separate column from order status.** The tempting
 * shape is one chain: confirmed → searching_rider → assigned. It loses the
 * kitchen. Dispatch now starts when the merchant accepts, so an order is
 * being cooked *and* being offered at the same moment, and a single column
 * cannot say both. `status` stays the kitchen's and the rider's; the hunt for
 * a rider runs alongside it in `dispatch_status`.
 *
 * **A rider holding an offer is not a duty status.** "Has a popup showing" is
 * derived from the offers table, not stored on the rider. A stored flag has
 * to be cleared by something, and whatever clears it will one day not run —
 * leaving a rider who looks busy forever and quietly stops earning. An offer
 * row has an `expires_at`; it stops counting on its own.
 *
 * **Every step is guarded.** This touches three tables and creates a fourth,
 * and the first run of it failed halfway on a MariaDB timestamp quirk: the
 * columns were added, the table was not, and no migration row existed to roll
 * back. The guards make a second attempt finish the job rather than refuse it.
 */
return new class extends Migration
{
    public function up(): void
    {
        /*
         * Widened before the new states are used anywhere, so a half-applied
         * deploy refuses a row rather than truncating one.
         */
        Schema::table('orders', function (Blueprint $table) {
            $table->string('status', 32)->default('pending_payment')->change();
        });

        Schema::table('order_status_history', function (Blueprint $table) {
            $table->string('from_status', 32)->nullable()->change();
            $table->string('to_status', 32)->change();
        });

        Schema::table('orders', function (Blueprint $table) {
            /*
             * How the search for a rider is going, independent of the food.
             * Null on self-pickup orders, which never want one.
             */
            if (! Schema::hasColumn('orders', 'dispatch_status')) {
                $table->string('dispatch_status', 20)->nullable()->after('rider_id');
            }

            // Which wave is out. Read by ops to see how hard this one was.
            if (! Schema::hasColumn('orders', 'dispatch_wave')) {
                $table->unsignedTinyInteger('dispatch_wave')->default(0)->after('dispatch_status');
            }

            if (! Schema::hasColumn('orders', 'dispatch_started_at')) {
                $table->timestamp('dispatch_started_at')->nullable()->after('dispatch_wave');
            }

            // Nobody took it. The one state that needs a human.
            if (! Schema::hasColumn('orders', 'dispatch_failed_at')) {
                $table->timestamp('dispatch_failed_at')->nullable()->after('dispatch_started_at');
            }

            /*
             * Arrival at each end. `arrived_at` already records reaching the
             * restaurant from GPS, which is what waiting pay is measured
             * from; these two are the rider's own confirmation, which is what
             * moves the order on. Kept apart deliberately — one is evidence,
             * the other is a button.
             */
            if (! Schema::hasColumn('orders', 'reached_restaurant_at')) {
                $table->timestamp('reached_restaurant_at')->nullable()->after('assigned_at');
            }

            if (! Schema::hasColumn('orders', 'reached_customer_at')) {
                $table->timestamp('reached_customer_at')->nullable()->after('picked_up_at');
            }
        });

        // "Which orders still need a rider" is a dashboard query and an
        // every-wave query, so it gets an index rather than a scan.
        if (! $this->hasIndex('orders', 'orders_dispatch_status_dispatch_wave_index')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->index(['dispatch_status', 'dispatch_wave']);
            });
        }

        /*
         * Acceptance behaviour, so a rider who leaves their phone ringing in
         * a pocket stops being offered work ahead of a rider who answers.
         *
         * Counters rather than a computed rate: the rate is two divisions and
         * the counters survive the offers table being pruned.
         */
        Schema::table('riders', function (Blueprint $table) {
            if (! Schema::hasColumn('riders', 'offers_received')) {
                $table->unsignedInteger('offers_received')->default(0)->after('completed_deliveries');
            }

            if (! Schema::hasColumn('riders', 'offers_accepted')) {
                $table->unsignedInteger('offers_accepted')->default(0)->after('offers_received');
            }

            if (! Schema::hasColumn('riders', 'consecutive_misses')) {
                $table->unsignedSmallInteger('consecutive_misses')->default(0)->after('offers_accepted');
            }
        });

        if (Schema::hasTable('rider_offers')) {
            return;
        }

        Schema::create('rider_offers', function (Blueprint $table) {
            $table->id();

            /*
             * The public identifier, used in the URL and in the push payload.
             * A ULID rather than the row id: the app shows this to nobody,
             * and a sequential integer in a push message tells anyone who
             * reads one how many deliveries the business has done.
             */
            $table->ulid('ulid')->unique();

            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('rider_id')->constrained()->cascadeOnDelete();

            $table->string('status', 16)->default('pending');

            // Which wave produced it, and how far the rider was when it went
            // out. Both are for working out later why dispatch was slow.
            $table->unsignedTinyInteger('wave')->default(1);
            $table->unsignedInteger('distance_metres')->nullable();

            /*
             * dateTime rather than timestamp. MariaDB gives the first NOT
             * NULL timestamp column in a table an implicit default and then
             * refuses the second one outright — a two-line schema detail that
             * failed this whole migration the first time round.
             */
            $table->dateTime('offered_at');

            /*
             * The server decides when the card stops ringing. The app counts
             * down to this instant rather than from a duration it was handed,
             * so a phone with a wrong clock or a message that arrived late
             * cannot leave an offer live after the order has gone to someone
             * else.
             */
            $table->dateTime('expires_at');

            $table->dateTime('responded_at')->nullable();

            // Why it ended, when it ended badly: a reject reason from the
            // rider, or 'taken' when another rider was faster.
            $table->string('reason', 120)->nullable();

            $table->timestamps();

            /*
             * One offer per rider per order. A second wave must not ring the
             * same phone twice for the same food, and this is enforced in the
             * schema rather than in the query that picks riders — the query
             * is the thing most likely to be changed in a hurry.
             */
            $table->unique(['order_id', 'rider_id']);

            // "Does this rider have a popup showing" — the hottest read here.
            $table->index(['rider_id', 'status', 'expires_at']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rider_offers');

        Schema::table('riders', function (Blueprint $table) {
            $table->dropColumn(['offers_received', 'offers_accepted', 'consecutive_misses']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['dispatch_status', 'dispatch_wave']);
            $table->dropColumn([
                'dispatch_status',
                'dispatch_wave',
                'dispatch_started_at',
                'dispatch_failed_at',
                'reached_restaurant_at',
                'reached_customer_at',
            ]);
        });

        /*
         * The status columns are left as strings. Narrowing them back to an
         * ENUM would have to name a list of states, and any order sitting in
         * one of the two added here would fail the conversion — a rollback
         * that destroys data is worse than a column that is wider than it
         * needs to be.
         */
    }

    /**
     * Whether a named index already exists.
     *
     * Asked of the schema builder directly, and tolerant of it not being able
     * to answer: this guard exists so a re-run finishes the job, and it must
     * not itself be the thing that stops one.
     */
    private function hasIndex(string $table, string $index): bool
    {
        return rescue(function () use ($table, $index) {
            foreach (Schema::getIndexes($table) as $existing) {
                if (($existing['name'] ?? null) === $index) {
                    return true;
                }
            }

            return false;
        }, false, report: false);
    }
};
