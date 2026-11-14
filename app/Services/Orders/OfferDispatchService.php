<?php

namespace App\Services\Orders;

use App\Enums\DispatchStatus;
use App\Enums\FulfilmentType;
use App\Enums\OfferStatus;
use App\Enums\OrderStatus;
use App\Enums\RiderStatus;
use App\Jobs\CloseDispatchWave;
use App\Models\Order;
use App\Models\Rider;
use App\Models\RiderOffer;
use App\Services\Discovery\NearbyMerchantService;
use App\Services\LiveState\RiderLocationService;
use App\Services\Push\OrderNotifier;
use App\Services\Push\PushService;
use App\Services\Riders\RiderPayoutService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Offer-based dispatch: the ringing order card (EP7, EP8).
 *
 * The order is offered to the riders nearest the restaurant, their phones
 * ring with a full-screen card and a countdown, and the first to accept gets
 * it. Nobody answers, the offers lapse and it goes out again, wider. Nobody
 * answers at all and it lands in front of a person.
 *
 * Three things in here are load-bearing and none of them are the happy path.
 *
 * **Every timeout is a delayed job.** The offer window, the next wave, giving
 * up — all of it is CloseDispatchWave, scheduled when the wave goes out. With
 * no queue worker running, the first wave is sent and nothing ever closes it:
 * the order sits in `searching` forever and no human is told. That is why
 * `dispatch.mode` defaults to the board and why switching it is gated on the
 * worker existing.
 *
 * **The server owns expiry.** The card counts down to an `expires_at` the
 * server set, not from a duration the app was handed. A phone with a wrong
 * clock, a message that sat in a queue, an app resumed from the background
 * twenty seconds late — each of those leaves a card ringing for an order
 * somebody else is already carrying, and the rider finds out by tapping
 * Accept and being refused.
 *
 * **Winning is one UPDATE.** Two riders tapping at the same millisecond is
 * the normal case, not the edge case, and it is settled by a conditional
 * UPDATE that only writes where `rider_id` is still null. No lock to acquire,
 * nothing to release if the process dies mid-way, and correct across separate
 * PHP workers on separate machines.
 */
class OfferDispatchService
{
    public function __construct(
        protected OrderStatusService $status,
        protected RiderLocationService $locations,
        protected NearbyMerchantService $geo,
        protected PushService $push,
        protected OrderNotifier $notifier,
        protected RiderPayoutService $payouts,
    ) {}

    /** Is offer dispatch the configured mode? */
    public function enabled(): bool
    {
        return config('dispatch.mode') === 'push';
    }

    /**
     * Begin looking for a rider.
     *
     * Idempotent on purpose. It is called from a status transition, and a
     * merchant double-tapping Accept, a retried job, or a release-then-
     * re-dispatch must not put two sets of offers out for one order.
     */
    public function start(Order $order): void
    {
        if (! $this->needsRider($order)) {
            return;
        }

        /*
         * Only from a standing start. Already searching means a wave is out
         * and a second set of offers for one order would have two riders
         * counting down on the same food; already assigned means somebody
         * has it. Both happen for real — a merchant double-tapping Accept,
         * a retried job, a release that walks the status back through
         * ready_for_pickup on its way to being re-dispatched properly.
         */
        $startable = [null, DispatchStatus::Idle, DispatchStatus::NoRiderFound];

        if (! in_array($order->dispatch_status, $startable, true)) {
            return;
        }

        $order->forceFill([
            'dispatch_status' => DispatchStatus::Searching->value,
            'dispatch_wave' => 0,
            'dispatch_started_at' => $order->dispatch_started_at ?? now(),
            'dispatch_failed_at' => null,
        ])->save();

        /*
         * Board mode records the state and stops there. Nothing is pushed —
         * riders find orders rather than the other way round — but the
         * customer's "finding a delivery partner" and the ops view read
         * this one field in both modes, which is the only way they can be
         * written once.
         */
        if (! $this->enabled()) {
            return;
        }

        $this->runWave($order, 1);
    }

    /**
     * Send one wave of offers.
     *
     * Returns the number of riders rung. Zero is not a failure — it means
     * nobody eligible is within the radius, and the wave still closes on its
     * timer so the next one goes out wider.
     */
    public function runWave(Order $order, int $wave): int
    {
        $waves = (array) config('dispatch.waves');

        if (! $this->needsRider($order) || ! isset($waves[$wave - 1])) {
            return 0;
        }

        $config = $waves[$wave - 1];
        $seconds = (int) $config['seconds'];
        $expiresAt = now()->addSeconds($seconds);

        $order->forceFill([
            'dispatch_status' => DispatchStatus::Searching->value,
            'dispatch_wave' => $wave,
        ])->save();

        $riders = $this->candidates(
            $order,
            (int) $config['radius_metres'],
            (int) $config['riders'],
        );

        foreach ($riders as $candidate) {
            $this->offer($order, $candidate['rider'], $wave, $candidate['distance_metres'], $expiresAt);
        }

        /*
         * Scheduled even when nobody was rung, because the wave still has to
         * end for the next one to start. The delay is the window plus a
         * second: a job that fires at the same instant the offers expire can
         * race the rider whose Accept is in flight, and a rider who tapped in
         * time must win that race.
         */
        CloseDispatchWave::dispatch($order->id, $wave)->delay(now()->addSeconds($seconds + 1));

        if ($riders->isEmpty()) {
            Log::info('Dispatch wave found nobody.', [
                'order_id' => $order->id,
                'wave' => $wave,
                'radius_metres' => $config['radius_metres'],
            ]);
        }

        return $riders->count();
    }

    /**
     * The wave's window has closed. Lapse what is still ringing, then widen
     * or give up.
     *
     * Guarded on the wave number so a job that fires late — the queue was
     * backed up, the worker restarted — cannot close a wave that has already
     * been superseded, or re-open the hunt for an order a rider is carrying.
     */
    public function closeWave(Order $order, int $wave): void
    {
        if ($order->dispatch_wave !== $wave || ! $this->needsRider($order)) {
            return;
        }

        $lapsed = $this->expireLive($order, $wave);

        foreach ($lapsed as $riderId) {
            $this->noteMiss($riderId);
        }

        /*
         * A later wave exists, so widen. \`runWave\` schedules the job that
         * closes *it*, which is what keeps the chain going without a
         * scheduler: each wave is responsible for ending itself.
         */
        if (isset(((array) config('dispatch.waves'))[$wave])) {
            $this->runWave($order->refresh(), $wave + 1);

            return;
        }

        $this->giveUp($order);
    }

    /**
     * Nobody took it. The order stops being dispatch's problem and becomes a
     * person's.
     *
     * Deliberately loud. An order with no rider is food going cold with a
     * customer watching a spinner, and the one thing that must not happen is
     * for it to fail quietly — so it is logged at error, the dispatch state
     * says so plainly, and the merchant is told their order needs attention.
     */
    protected function giveUp(Order $order): void
    {
        $order->forceFill([
            'dispatch_status' => DispatchStatus::NoRiderFound->value,
            'dispatch_failed_at' => now(),
        ])->save();

        Log::error('No rider found for an order.', [
            'order_id' => $order->id,
            'order_number' => $order->order_number,
            'merchant_id' => $order->merchant_id,
            'zone_id' => $order->zone_id,
            'waves' => $order->dispatch_wave,
            'searching_for_seconds' => $order->dispatch_started_at?->diffInSeconds(now()),
        ]);

        $this->push->toUser(
            $order->merchant?->user,
            PushService::MERCHANT,
            'push.merchant.no_rider',
            ['number' => $order->order_number],
            ['type' => 'order.no_rider', 'order_id' => (string) $order->id],
        );
    }

    /**
     * Ring one rider.
     *
     * The payload carries no customer address and no customer phone. A rider
     * who is only being asked has not been trusted with where somebody lives,
     * and an offer goes to up to fifteen phones — fourteen of which will
     * never touch this order.
     */
    protected function offer(Order $order, Rider $rider, int $wave, ?int $distance, Carbon $expiresAt): ?RiderOffer
    {
        /*
         * firstOrCreate on the (order, rider) unique key rather than create:
         * two workers running overlapping waves for one order is possible,
         * and a duplicate-key exception would abandon the rest of the wave.
         */
        $offer = RiderOffer::firstOrCreate(
            ['order_id' => $order->id, 'rider_id' => $rider->id],
            [
                'ulid' => (string) Str::ulid(),
                'status' => OfferStatus::Pending->value,
                'wave' => $wave,
                'distance_metres' => $distance,
                'offered_at' => now(),
                'expires_at' => $expiresAt,
            ],
        );

        if (! $offer->wasRecentlyCreated) {
            return null;
        }

        $rider->increment('offers_received');

        $this->push->dataToUsers(
            [$rider->user_id],
            PushService::RIDER,
            $this->offerPayload($order, $offer),
            // Past the window the card is not late, it is wrong.
            max(1, $offer->secondsLeft()),
        );

        return $offer;
    }

    /**
     * What the ringing card is drawn from.
     *
     * Flat strings throughout: FCM data payloads are string-to-string, and a
     * nested array arrives as the word "Array" on a rider's screen.
     *
     * @return array<string, string>
     */
    /**
     * The ringing card, as flat strings.
     *
     * FCM data payloads are string-to-string and flat, so every value is
     * cast — a number here makes FCM reject the whole message.
     *
     * **Every key must also exist in `RiderOfferResource` under the same
     * name and meaning.** The app parses the push and `GET /offers/current`
     * with one parser, so a name that differs between the two is a card
     * drawn wrong in exactly one of the paths — the harder one to notice,
     * because the push path only runs on a real device. A test asserts the
     * subset; `type` is the message envelope and is excluded from it.
     *
     * @return array<string, string>
     */
    public function offerPayload(Order $order, RiderOffer $offer): array
    {
        return [
            'type' => 'ORDER_OFFER',
            'offer_id' => $offer->ulid,
            'order_id' => (string) $order->id,
            'order_number' => (string) $order->order_number,

            // The server's clock, in UTC. The app counts down to this.
            'expires_at' => $offer->expires_at->toIso8601String(),
            'seconds_left' => (string) $offer->secondsLeft(),

            'restaurant' => (string) ($order->merchant?->business_name ?? ''),
            'restaurant_area' => (string) ($order->merchant?->city ?? ''),
            'restaurant_latitude' => (string) ($order->merchant?->latitude ?? ''),
            'restaurant_longitude' => (string) ($order->merchant?->longitude ?? ''),
            'pickup_distance_metres' => (string) ($offer->distance_metres ?? ''),

            /*
             * The drop area, not the drop address. Enough for a rider to
             * judge whether they want the job; not enough to know where
             * anybody lives.
             */
            'dropoff_area' => (string) ($order->delivery_city ?? ''),
            'delivery_distance_metres' => (string) ($order->distance_metres ?? ''),

            'item_count' => (string) $order->loadMissing('items')->items->sum('quantity'),

            /*
             * The kitchen's state, so the card can say "food ready in about
             * 12 min" rather than leaving the rider to find out at the
             * counter. An offer goes out when the merchant accepts, which is
             * before anything is cooked.
             */
            'order_status' => (string) $order->status->value,
            'estimated_prep_minutes' => (string) ($order->estimated_prep_minutes ?? ''),
            'earnings' => (string) $this->earnings($order),
            'collect_cash' => (string) $order->cashToCollect(),
            'wave' => (string) $offer->wave,
        ];
    }

    /**
     * Claim an order.
     *
     * @throws ValidationException on 409 (taken) and 410 (expired) — the
     *                             controller maps them to those codes, because
     *                             a rider whose card was a second too slow is
     *                             a normal event and not an error to log.
     */
    public function accept(Rider $rider, RiderOffer $offer): Order
    {
        $order = $offer->order;

        if ($offer->rider_id !== $rider->id) {
            $this->fail('offer', 'This offer is not yours.', 404);
        }

        if ($offer->status === OfferStatus::Accepted && $order->rider_id === $rider->id) {
            // Already won it. A retried request after a dropped response must
            // not look like a loss.
            return $order;
        }

        if ($offer->status->isFinished() || $offer->expires_at->isPast()) {
            $this->fail('offer', $this->endingFor($offer), $offer->status === OfferStatus::Cancelled ? 409 : 410);
        }

        $this->guardRider($rider);

        /*
         * The whole race, in one statement. `rider_id` is written only where
         * it is still null and the order is still being searched for, so two
         * riders tapping at the same instant produce one winner and one 409.
         */
        $claimed = Order::query()
            ->whereKey($order->id)
            ->whereNull('rider_id')
            ->where('dispatch_status', DispatchStatus::Searching->value)
            ->whereNotIn('status', Order::finishedStatuses())
            ->update([
                'rider_id' => $rider->id,
                'dispatch_status' => DispatchStatus::Assigned->value,
                'assigned_at' => now(),
            ]);

        if ($claimed !== 1) {
            $this->fail('offer', 'Another rider took this order.', 409);
        }

        $order->refresh();

        $offer->forceFill([
            'status' => OfferStatus::Accepted->value,
            'responded_at' => now(),
        ])->save();

        $rider->forceFill([
            'duty_status' => RiderStatus::OnOrder->value,
            'offers_accepted' => $rider->offers_accepted + 1,
            // Answered. Whatever they ignored before this is forgiven.
            'consecutive_misses' => 0,
        ])->save();

        rescue(fn () => $this->locations->setDutyStatus($rider->id, RiderStatus::OnOrder->value), report: true);

        $this->recordFirstMile($order, $rider);

        // Everybody else's phone stops ringing.
        $this->cancelOutstanding($order, 'taken', exceptRiderId: $rider->id);

        /*
         * Only the food being ready makes this `rider_assigned`. Until then
         * the kitchen owns the status and the rider is recorded in
         * `dispatch_status` and `rider_id` — otherwise accepting an order
         * would take "mark ready" away from the merchant who is still
         * cooking it.
         */
        if ($order->status === OrderStatus::ReadyForPickup) {
            $this->status->markAssigned($order, $rider->user);
        } else {
            /*
             * Food still cooking, so no status change — but the kitchen is
             * told, because "somebody is coming, have it bagged" is worth
             * knowing while you are still at the stove. The customer waits
             * for the food to be ready; they were given a time when the
             * order was accepted and telling them twice would be one
             * notification too many.
             */
            $this->notifier->riderAssignedEarly($order->refresh());
        }

        return $order->refresh();
    }

    /** The rider tapped no. */
    public function reject(Rider $rider, RiderOffer $offer, ?string $reason = null): void
    {
        if ($offer->rider_id !== $rider->id) {
            $this->fail('offer', 'This offer is not yours.', 404);
        }

        if ($offer->status->isFinished()) {
            // Nothing to do, and nothing worth refusing over: the card is
            // gone from their screen either way.
            return;
        }

        $offer->forceFill([
            'status' => OfferStatus::Rejected->value,
            'responded_at' => now(),
            'reason' => $reason === null ? null : Str::limit($reason, 115),
        ])->save();

        /*
         * Declining is answering. It does not count towards being taken off
         * duty — a system that punishes an honest "no" teaches riders to let
         * it ring instead, which costs everyone thirty seconds instead of
         * none.
         */
        $rider->forceFill(['consecutive_misses' => 0])->save();
    }

    /**
     * Withdraw every offer still ringing for this order.
     *
     * Sent as its own data message rather than relying on the card expiring:
     * twenty-nine seconds of a ringtone for an order that is already gone is
     * how a rider learns to ignore the sound.
     */
    public function cancelOutstanding(Order $order, string $reason, ?int $exceptRiderId = null): void
    {
        $offers = RiderOffer::query()
            ->with('rider:id,user_id')
            ->where('order_id', $order->id)
            ->live()
            ->when($exceptRiderId !== null, fn ($q) => $q->where('rider_id', '!=', $exceptRiderId))
            ->get();

        if ($offers->isEmpty()) {
            return;
        }

        RiderOffer::whereIn('id', $offers->pluck('id'))->update([
            'status' => OfferStatus::Cancelled->value,
            'responded_at' => now(),
            'reason' => Str::limit($reason, 115),
            'updated_at' => now(),
        ]);

        $userIds = $offers->pluck('rider.user_id')->filter()->unique()->values()->all();

        if ($userIds === []) {
            return;
        }

        $this->push->dataToUsers($userIds, PushService::RIDER, [
            'type' => 'OFFER_CANCELLED',
            'order_id' => (string) $order->id,
            'reason' => $reason,
        ], 60);
    }

    /**
     * Put an order back out after a rider handed it back.
     *
     * Starts from wave one rather than continuing where the last hunt left
     * off: time has passed, riders have moved, and the nearest rider now is
     * not the one who was nearest then.
     */
    public function redispatch(Order $order): void
    {
        if (! $this->enabled()) {
            return;
        }

        /*
         * The old offers are deleted, not kept. The unique key is (order,
         * rider) so a rider who declined the first time round could never be
         * offered it again — and a rider who declined an order forty minutes
         * ago, when they were mid-delivery, is a perfectly good candidate now.
         */
        RiderOffer::where('order_id', $order->id)->delete();

        $order->forceFill([
            'dispatch_status' => DispatchStatus::Idle->value,
            'dispatch_wave' => 0,
        ])->save();

        $this->start($order->refresh());
    }

    /** The card this rider should have on screen, if any. */
    public function current(Rider $rider): ?RiderOffer
    {
        return RiderOffer::query()
            ->with(['order.merchant', 'order.items'])
            ->where('rider_id', $rider->id)
            ->live()
            ->latest('offered_at')
            ->first();
    }

    public function hasLiveOffer(Rider $rider): bool
    {
        return RiderOffer::where('rider_id', $rider->id)->live()->exists();
    }

    /**
     * Riders worth ringing, nearest first.
     *
     * @return Collection<int, array{rider: Rider, distance_metres: int|null}>
     */
    protected function candidates(Order $order, int $radiusMetres, int $limit): Collection
    {
        $latitude = $order->merchant?->latitude;
        $longitude = $order->merchant?->longitude;

        if ($latitude === null || $longitude === null) {
            Log::warning('Cannot dispatch an order whose restaurant has no position.', [
                'order_id' => $order->id,
                'merchant_id' => $order->merchant_id,
            ]);

            return collect();
        }

        $nearby = $this->nearby($order, (float) $latitude, (float) $longitude, $radiusMetres, $limit);

        if ($nearby === []) {
            return collect();
        }

        $riders = Rider::query()
            ->with('user:id')
            ->whereIn('id', array_keys($nearby))
            ->get()
            ->keyBy('id');

        /*
         * Riders already offered this order are excluded by the query below
         * rather than by the unique key alone: the key would stop the row
         * being written, but only after this wave had already counted them
         * towards its limit and left somebody else unrung.
         */
        $alreadyOffered = RiderOffer::where('order_id', $order->id)
            /*
             * Every rider ever offered this order, unless re-offering is
             * turned on — in which case only the ones whose card is still
             * ringing, so a second wave does not ring the same phone twice
             * at once.
             */
            ->when(config('dispatch.reoffer_to_same_rider'), fn ($q) => $q->live())
            ->pluck('rider_id')
            ->all();

        $busy = RiderOffer::query()
            ->whereIn('rider_id', array_keys($nearby))
            ->live()
            ->pluck('rider_id')
            ->all();

        $onOrder = Order::query()
            ->whereIn('rider_id', array_keys($nearby))
            ->active()
            ->pluck('rider_id')
            ->all();

        return collect($nearby)
            ->map(fn (int $distance, int $riderId) => [
                'rider' => $riders->get($riderId),
                'distance_metres' => $distance,
            ])
            ->filter(fn (array $row) => $row['rider'] instanceof Rider)
            ->reject(fn (array $row) => in_array($row['rider']->id, $alreadyOffered, true))
            // Already has a card up. One popup at a time, or a rider answers
            // the wrong one.
            ->reject(fn (array $row) => in_array($row['rider']->id, $busy, true))
            ->reject(fn (array $row) => in_array($row['rider']->id, $onOrder, true))
            ->reject(fn (array $row) => $row['rider']->duty_status !== RiderStatus::Available)
            ->reject(fn (array $row) => ! $row['rider']->canAcceptOrders())
            /*
             * Never the customer's own order. Otherwise: order food, deliver
             * it to yourself, mark it delivered, keep the delivery fee.
             * Repeatable at will, and it looks like a rider who is very fast.
             */
            ->reject(fn (array $row) => $row['rider']->user_id === $order->user_id)
            ->take($limit)
            ->values();
    }

    /**
     * Rider ids within the radius, mapped to their distance in metres.
     *
     * Redis first, because that is where live positions are. Falling back to
     * the last position written to MySQL rather than returning nothing: a
     * Redis restart during the dinner rush must slow dispatch down, not stop
     * it, and a position from forty seconds ago is good enough to ring a
     * phone with.
     *
     * @return array<int, int>
     */
    protected function nearby(Order $order, float $latitude, float $longitude, int $radiusMetres, int $limit): array
    {
        $found = rescue(fn () => $this->locations->ridersNear(
            (int) ($order->zone_id ?? 0),
            $latitude,
            $longitude,
            $radiusMetres,
            // Asked for more than the wave needs, because the filters below
            // will drop some of them and a short list means a silent phone.
            $limit * 3,
        ), [], report: true);

        if ($found !== []) {
            return collect($found)
                ->mapWithKeys(fn (array $row) => [(int) $row['rider_id'] => (int) round($row['distance_metres'])])
                ->all();
        }

        $fresh = now()->subSeconds((int) config('dispatch.presence_ttl_seconds'));

        return Rider::query()
            ->whereNotNull('last_latitude')
            ->where('last_location_at', '>=', $fresh)
            ->where('duty_status', RiderStatus::Available->value)
            ->when($order->zone_id, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('zone_id')->orWhere('zone_id', $order->zone_id)))
            ->get(['id', 'last_latitude', 'last_longitude'])
            ->mapWithKeys(fn (Rider $rider) => [$rider->id => (int) round($this->geo->distance(
                $latitude,
                $longitude,
                (float) $rider->last_latitude,
                (float) $rider->last_longitude,
            ))])
            ->filter(fn (int $metres) => $metres <= $radiusMetres)
            ->sort()
            ->take($limit * 3)
            ->all();
    }

    /**
     * Lapse the offers from one wave that nobody answered.
     *
     * @return list<int> rider ids who let it ring
     */
    protected function expireLive(Order $order, int $wave): array
    {
        $riderIds = RiderOffer::query()
            ->where('order_id', $order->id)
            ->where('wave', $wave)
            ->where('status', OfferStatus::Pending->value)
            ->pluck('rider_id')
            ->all();

        if ($riderIds === []) {
            return [];
        }

        RiderOffer::query()
            ->where('order_id', $order->id)
            ->where('wave', $wave)
            ->where('status', OfferStatus::Pending->value)
            ->update([
                'status' => OfferStatus::Expired->value,
                'responded_at' => now(),
                'updated_at' => now(),
            ]);

        return $riderIds;
    }

    /**
     * A rider let one ring out.
     *
     * Three in a row and they go off duty. Not a punishment — a phone at the
     * bottom of a bag — but every wave that includes someone who is not
     * there is thirty seconds the customer waits for nothing.
     */
    protected function noteMiss(int $riderId): void
    {
        $rider = Rider::find($riderId);

        if ($rider === null) {
            return;
        }

        $misses = $rider->consecutive_misses + 1;
        $limit = (int) config('dispatch.auto_offline_after_misses');

        $rider->forceFill(['consecutive_misses' => $misses])->save();

        if ($limit <= 0 || $misses < $limit || $rider->duty_status !== RiderStatus::Available) {
            return;
        }

        $rider->forceFill([
            'duty_status' => RiderStatus::Offline->value,
            'consecutive_misses' => 0,
        ])->save();

        rescue(fn () => $this->locations->goOffline($rider->id, (int) ($rider->zone_id ?? 0)), report: true);

        Log::info('Rider taken off duty after ignoring offers.', [
            'rider_id' => $rider->id,
            'misses' => $misses,
        ]);

        $this->push->toUser(
            $rider->user,
            PushService::RIDER,
            'push.rider.auto_offline',
            [],
            ['type' => 'rider.auto_offline'],
        );
    }

    /**
     * Where the rider was when they took it.
     *
     * Written now because there is no way back to it afterwards: a rider's
     * position lives in a Redis set with a TTL and the next ping overwrites
     * it. The first mile is what they are paid for travelling to the
     * restaurant, and it cannot be reconstructed later.
     */
    protected function recordFirstMile(Order $order, Rider $rider): void
    {
        $state = rescue(fn () => $this->locations->state($rider->id), [], report: false);

        $latitude = $state['latitude'] ?? $rider->last_latitude;
        $longitude = $state['longitude'] ?? $rider->last_longitude;

        if ($latitude === null || $longitude === null) {
            return;
        }

        $order->forceFill(array_filter([
            'accepted_latitude' => $latitude,
            'accepted_longitude' => $longitude,
            'first_mile_metres' => $order->merchant?->latitude === null ? null : (int) round($this->geo->distance(
                (float) $latitude,
                (float) $longitude,
                (float) $order->merchant->latitude,
                (float) $order->merchant->longitude,
            )),
            'last_mile_metres' => $order->distance_metres,
        ], fn ($value) => $value !== null))->save();
    }

    /** What this delivery is worth to the rider, for the card. */
    protected function earnings(Order $order): float
    {
        return rescue(
            fn () => round((float) $this->payouts->calculate($order)['total'], 2),
            round((float) $order->delivery_fee, 2),
            report: false,
        );
    }

    /** Still looking for somebody to carry this? */
    protected function needsRider(Order $order): bool
    {
        return $order->rider_id === null
            && $order->fulfilment_type === FulfilmentType::Delivery
            && ! $order->status->isTerminal()
            && $order->status !== OrderStatus::PendingPayment;
    }

    protected function endingFor(RiderOffer $offer): string
    {
        return match ($offer->status) {
            OfferStatus::Accepted => 'You already accepted this order.',
            OfferStatus::Rejected => 'You declined this order.',
            OfferStatus::Cancelled => 'Another rider took this order.',
            default => 'This offer has expired.',
        };
    }

    /**
     * @throws ValidationException
     */
    protected function guardRider(Rider $rider): void
    {
        if (($reason = $rider->offlineReason()) !== null) {
            $this->fail('rider', $reason, 422);
        }

        if ($rider->duty_status === RiderStatus::Offline || $rider->duty_status === RiderStatus::OnBreak) {
            $this->fail('rider', 'Go online before accepting orders.', 422);
        }

        if ($rider->orders()->active()->count() >= (int) config('dispatch.max_concurrent_orders_per_rider')) {
            $this->fail('rider', 'Finish your current delivery first.', 422);
        }
    }

    /**
     * Refusals carry the HTTP status the rider app switches on.
     *
     * 409 and 410 mean different things to the popup: taken by somebody else
     * closes the card with "gone", expired closes it silently because the
     * countdown already ran out on screen. Collapsing both into 422 would
     * make the app guess.
     *
     * @throws ValidationException
     */
    protected function fail(string $field, string $message, int $status): never
    {
        throw ValidationException::withMessages([$field => $message])->status($status);
    }
}
