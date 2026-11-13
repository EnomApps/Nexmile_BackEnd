<?php

namespace App\Services\Orders;

use App\Enums\DispatchStatus;
use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\LiveState\OrderStateService;
use App\Services\Payments\PaymentService;
use App\Services\Push\OrderNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The merchant's half of the order lifecycle (EP5, EP8).
 *
 * Every status change goes through here. A controller setting
 * `$order->status` directly would skip the history row, the timestamp and the
 * Redis mirror, and those three are what the customer's tracking screen, the
 * dispatch queue and the payout report each read.
 */
class OrderStatusService
{
    /**
     * What a merchant may do, and from where.
     *
     * Deliberately not the full lifecycle: RiderAssigned, PickedUp and
     * Delivered belong to dispatch and the rider app (EP8, EP10). A merchant
     * cannot mark an order delivered — they never handle it after pickup.
     *
     * @var array<string, list<string>>
     */
    protected const MERCHANT_TRANSITIONS = [
        'accept' => [OrderStatus::Placed->value],
        'reject' => [OrderStatus::Placed->value],
        'preparing' => [OrderStatus::Accepted->value],
        'ready' => [OrderStatus::Accepted->value, OrderStatus::Preparing->value],

        /*
         * Cancelling *after* accepting is a different act from rejecting. A
         * gas cylinder runs out, a key ingredient is gone — without this the
         * kitchen has no way out and the order sits until someone rings a
         * support line that does not exist.
         *
         * Not available once a rider is carrying it; at that point the food
         * exists and is somebody's problem to deliver or hand back.
         */
        'cancel' => [
            OrderStatus::Accepted->value,
            OrderStatus::Preparing->value,
            OrderStatus::ReadyForPickup->value,
        ],
    ];

    /**
     * What a rider may do (EP8, EP10).
     *
     * The other half of the same machine. A rider cannot accept an order that
     * is not ready, cannot collect one they have not been assigned, and cannot
     * deliver one they have not collected — each of those would leave the
     * customer's tracking screen describing something that did not happen.
     *
     * @var array<string, list<string>>
     */
    protected const RIDER_TRANSITIONS = [
        'assign' => [OrderStatus::ReadyForPickup->value],

        /*
         * Standing at the counter, and standing at the door. Both are the
         * rider saying so, not GPS saying so — \`arrived_at\` is the GPS one
         * and it is what waiting pay is measured from, precisely because a
         * button pays whatever the rider taps.
         */
        'reached_restaurant' => [OrderStatus::RiderAssigned->value],
        'reached_customer' => [OrderStatus::PickedUp->value],

        // Collectable whether or not the rider announced arriving first.
        // A rider holding a bag should never be blocked by a button they
        // did not press.
        'pickup' => [OrderStatus::RiderAssigned->value, OrderStatus::ReachedRestaurant->value],
        'deliver' => [OrderStatus::PickedUp->value, OrderStatus::ReachedCustomer->value],

        /*
         * Hand the job back — a breakdown, a wrong turn, a shift ending.
         * Only before collection: once the food is in the bag it cannot be
         * returned to a board, and a human has to be involved.
         */
        'release' => [OrderStatus::RiderAssigned->value, OrderStatus::ReachedRestaurant->value],
    ];

    public function __construct(
        protected OrderStateService $liveState,
        protected PaymentService $payments,
        protected OrderNotifier $notifier,
    ) {}

    /**
     * @throws ValidationException
     */
    public function accept(Order $order, User $actor, ?int $prepMinutes = null): Order
    {
        $this->guard($order, 'accept');

        return $this->transition($order, OrderStatus::Accepted, $actor, attributes: [
            'accepted_at' => now(),
            // Falls back to the merchant's configured average so the customer
            // always sees an estimate, even when the merchant just taps Accept.
            'estimated_prep_minutes' => $prepMinutes ?? $order->merchant->avg_prep_time_minutes ?? 20,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function reject(Order $order, User $actor, string $reason): Order
    {
        $this->guard($order, 'reject');

        return $this->transition($order, OrderStatus::Rejected, $actor, note: $reason, attributes: [
            'cancelled_at' => now(),
            'cancelled_by' => 'merchant',
            'cancellation_reason' => $reason,
            /*
             * No cancellation fee. The customer did nothing wrong, and a
             * rejected order is refunded in full — charging them for the
             * merchant's decision would be indefensible.
             */
            'cancellation_fee' => 0,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function startPreparing(Order $order, User $actor): Order
    {
        $this->guard($order, 'preparing');

        return $this->transition($order, OrderStatus::Preparing, $actor);
    }

    /**
     * @throws ValidationException
     */
    public function markReady(Order $order, User $actor): Order
    {
        $this->guard($order, 'ready');

        $ready = $this->transition($order, OrderStatus::ReadyForPickup, $actor, attributes: [
            'ready_at' => now(),
        ]);

        /*
         * A rider who accepted while the food was cooking has been waiting
         * for exactly this, so the order goes straight on to being theirs
         * rather than sitting in a state that means "waiting for a rider"
         * when one is standing at the counter.
         *
         * Two history rows rather than one, and both are true: the food
         * became ready, and then the order became a rider's to collect.
         * Credited to the rider, because they are whose act it was — the
         * merchant only finished cooking.
         */
        if ($ready->rider_id !== null) {
            return $this->markAssigned($ready, $ready->rider?->user ?? $actor);
        }

        return $ready;
    }

    /**
     * Cancel an order the merchant already accepted.
     *
     * @throws ValidationException
     */
    public function cancelByMerchant(Order $order, User $actor, string $reason): Order
    {
        // A rider taking the order moves it straight to rider_assigned, which
        // is not a cancellable state — so the guard covers that case and
        // refusal() explains it in terms the merchant can act on.
        $this->guard($order, 'cancel');

        return $this->transition($order, OrderStatus::Cancelled, $actor, note: $reason, attributes: [
            'cancelled_at' => now(),
            'cancelled_by' => 'merchant',
            'cancellation_reason' => $reason,
            // The customer did nothing wrong.
            'cancellation_fee' => 0,
        ]);
    }

    /**
     * Cancel anything still in flight. The escape hatch for an order no rider
     * ever took, or one stuck behind a problem nobody else can resolve.
     *
     * @throws ValidationException
     */
    public function cancelByAdmin(Order $order, User $actor, string $reason): Order
    {
        if ($order->status->isTerminal()) {
            throw ValidationException::withMessages([
                'status' => 'This order is already '.str_replace('_', ' ', $order->status->value).'.',
            ]);
        }

        return $this->transition($order, OrderStatus::Cancelled, $actor, note: $reason, attributes: [
            'cancelled_at' => now(),
            'cancelled_by' => 'admin',
            'cancellation_reason' => $reason,
            'cancellation_fee' => 0,
        ]);
    }

    /**
     * Hand an order back before collecting it.
     *
     * Two shapes, because offer dispatch assigns a rider while the food is
     * still cooking. If the order was ready, this walks the status back to
     * ready_for_pickup. If the kitchen never stopped, there is no status to
     * walk back — the rider is simply removed, and the history row is
     * written by hand so that "a rider gave this back" is still on the
     * record. Losing that is losing the only evidence of why a delivery
     * took twice as long as it should have.
     *
     * @throws ValidationException
     */
    public function releaseByRider(Order $order, User $actor, ?string $reason = null): Order
    {
        $note = $reason ?? 'Handed back by the rider.';

        $cooking = in_array($order->status, [
            OrderStatus::Accepted,
            OrderStatus::Preparing,
        ], true);

        if (! $cooking) {
            $this->guard($order, 'release', self::RIDER_TRANSITIONS);
        }

        $order->forceFill([
            'rider_id' => null,
            'assigned_at' => null,
            'reached_restaurant_at' => null,
            'dispatch_status' => DispatchStatus::Idle->value,
        ])->save();

        if (! $cooking) {
            return $this->transition($order, OrderStatus::ReadyForPickup, $actor, note: $note);
        }

        $order->statusHistory()->create([
            'from_status' => $order->status,
            'to_status' => $order->status,
            'changed_by_user_id' => $actor->id,
            'note' => $note,
            'created_at' => now(),
        ]);

        return $order->refresh();
    }

    /**
     * The rider is at the restaurant.
     *
     * Tells the kitchen somebody is waiting, which is the single most
     * useful thing a merchant can know and the thing they currently find
     * out by looking up.
     *
     * @throws ValidationException
     */
    public function markReachedRestaurant(Order $order, User $actor): Order
    {
        $this->guard($order, 'reached_restaurant', self::RIDER_TRANSITIONS);

        return $this->transition($order, OrderStatus::ReachedRestaurant, $actor, attributes: [
            'reached_restaurant_at' => now(),
            /*
             * Also stamped as the GPS arrival if nothing has stamped it yet.
             * Waiting pay starts from the earlier of the two, and a rider
             * whose phone lost signal on the way in should not lose the
             * minutes they then spent standing there.
             */
            'arrived_at' => $order->arrived_at ?? now(),
        ]);
    }

    /**
     * The rider is at the customer's door.
     *
     * @throws ValidationException
     */
    public function markReachedCustomer(Order $order, User $actor): Order
    {
        $this->guard($order, 'reached_customer', self::RIDER_TRANSITIONS);

        return $this->transition($order, OrderStatus::ReachedCustomer, $actor, attributes: [
            'reached_customer_at' => now(),
        ]);
    }

    /**
     * Mark an order collected from the restaurant.
     *
     * The pickup code is the proof that the right rider took the right order,
     * which is what a disputed delivery is settled with. Without it this is
     * just a button a rider can press from anywhere.
     *
     * @throws ValidationException
     */
    public function markPickedUp(Order $order, User $actor, string $pickupCode): Order
    {
        $this->guard($order, 'pickup', self::RIDER_TRANSITIONS);

        // hash_equals rather than !==: the code is short and guessable by
        // timing otherwise, and it costs nothing to compare properly.
        if (! hash_equals((string) $order->pickup_code, trim($pickupCode))) {
            throw ValidationException::withMessages([
                'pickup_code' => 'That code does not match. Ask the restaurant to read it again.',
            ]);
        }

        return $this->transition($order, OrderStatus::PickedUp, $actor, attributes: [
            'picked_up_at' => now(),
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function markDelivered(Order $order, User $actor): Order
    {
        $this->guard($order, 'deliver', self::RIDER_TRANSITIONS);

        return $this->transition($order, OrderStatus::Delivered, $actor, attributes: [
            'delivered_at' => now(),
        ]);
    }

    /**
     * Record that a rider took the job. The assignment itself is written by
     * DispatchService, which claims it atomically; this only moves the status.
     *
     * @throws ValidationException
     */
    public function markAssigned(Order $order, User $actor): Order
    {
        $this->guard($order, 'assign', self::RIDER_TRANSITIONS);

        return $this->transition($order, OrderStatus::RiderAssigned, $actor, attributes: [
            /*
             * Kept if it is already set. Under offer dispatch the rider
             * accepted while the food was still cooking, and overwriting it
             * here would restate the wait as starting when the kitchen
             * finished — which is the opposite of what happened, and what
             * the first mile is paid from.
             */
            'assigned_at' => $order->assigned_at ?? now(),
        ]);
    }

    /**
     * @param  array<string, list<string>>  $map
     *
     * @throws ValidationException
     */
    protected function guard(Order $order, string $action, ?array $map = null): void
    {
        $allowed = ($map ?? self::MERCHANT_TRANSITIONS)[$action];

        if (! in_array($order->status->value, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => $this->refusal($order->status, $action),
            ]);
        }
    }

    /**
     * A merchant tapping Accept on an order the customer cancelled two seconds
     * earlier needs to know which of those happened, not just that it failed.
     */
    protected function refusal(OrderStatus $current, string $action): string
    {
        $withRider = in_array($current, [
            OrderStatus::RiderAssigned,
            OrderStatus::ReachedRestaurant,
            OrderStatus::PickedUp,
            OrderStatus::ReachedCustomer,
        ], true);

        return match (true) {
            $current === OrderStatus::Cancelled => 'This order was cancelled.',
            $current === OrderStatus::Rejected => 'You already rejected this order.',
            $current === OrderStatus::PendingPayment => 'This order is not paid for yet.',
            /*
             * Once a rider has it, cancelling is a conversation rather than a
             * button — somebody is standing at a counter or already riding.
             */
            $action === 'cancel' && $withRider => 'A rider is already collecting this order. Call them before cancelling.',
            $action === 'cancel' && $current === OrderStatus::Delivered => 'This order was already delivered.',
            $action === 'accept' && $current !== OrderStatus::Placed => 'This order has already been accepted.',
            default => 'This order is already '.str_replace('_', ' ', $current->value).'.',
        };
    }

    /**
     * The write itself: status, timestamps, history and live state, in that
     * order and in one transaction.
     *
     * @param  array<string, mixed>  $attributes
     */
    protected function transition(
        Order $order,
        OrderStatus $to,
        User $actor,
        ?string $note = null,
        array $attributes = [],
    ): Order {
        $from = $order->status;

        DB::transaction(function () use ($order, $to, $from, $actor, $note, $attributes) {
            $order->update([...$attributes, 'status' => $to]);

            $order->statusHistory()->create([
                'from_status' => $from,
                'to_status' => $to,
                'changed_by_user_id' => $actor->id,
                'note' => $note,
                'created_at' => now(),
            ]);
        });

        /*
         * Outside the transaction, and swallowed: Redis is a cache of MySQL,
         * so it must not roll back a status the merchant has already been
         * told about. Losing Redis costs the tracking screen its latency, not
         * the order its state — the hash rebuilds from the orders table.
         */
        rescue(fn () => $this->liveState->setStatus($order->id, $to), report: true);

        /*
         * An order that dies after the customer paid has to give the money
         * back, whoever ended it. COD orders fall straight through — nothing
         * was taken.
         *
         * Also outside the transaction: a gateway being slow must not roll
         * back a cancellation the merchant has already been shown.
         */
        if (in_array($to, [OrderStatus::Cancelled, OrderStatus::Rejected], true)) {
            $this->payments->refund($order, $note ?? 'Order '.$to->value, $actor->id);
        }

        $order->refresh();

        /*
         * Notifications hang off the transition rather than off each caller.
         * Every status change in the product funnels through here, so a new
         * path cannot forget to tell anyone — and the alternative was the same
         * two lines pasted into six methods.
         *
         * After the commit and the refresh, because the queued job reads the
         * order back and would otherwise race the write.
         */
        $this->notify($order, $to);

        /*
         * And the hunt for a rider, for the same reason: every status change
         * funnels through here, so dispatch cannot be started twice or
         * forgotten by a new caller.
         */
        $this->redirectDispatch($order, $to);

        return $order;
    }

    /**
     * Start or stop looking for a rider.
     *
     * Resolved out of the container rather than injected, because the
     * dispatch service depends on this one — constructor-injecting it both
     * ways is a circular dependency Laravel cannot build.
     *
     * In board mode this only records the dispatch state — no phone rings,
     * because riders find orders rather than the other way round.
     */
    protected function redirectDispatch(Order $order, OrderStatus $to): void
    {
        $offers = app(OfferDispatchService::class);

        rescue(fn () => match (true) {
            /*
             * The kitchen has taken it, so start riding. This is the whole
             * point of dispatching at 'accepted': the rider travels while
             * the food cooks instead of afterwards.
             */
            $to === OrderStatus::Accepted && config('dispatch.start_at') === 'accepted' => $offers->start($order),

            // Either the configured starting point, or the safety net for an
            // order that is ready and somehow still has nobody.
            $to === OrderStatus::ReadyForPickup => $offers->start($order),

            /*
             * Dead order, live phones. Withdrawing the cards matters more
             * than the cancellation itself here — a rider who accepts an
             * offer for a cancelled order rides to a restaurant that has
             * thrown the food away.
             */
            in_array($to, [OrderStatus::Cancelled, OrderStatus::Rejected], true) => $offers->cancelOutstanding($order, 'order_cancelled'),

            default => null,
        }, report: true);
    }

    /**
     * Who to tell about this state, if anyone.
     *
     * Statuses absent from this list are deliberate. Preparing is not news to
     * a customer who was told a time when the order was accepted, and a
     * notification for every step trains people to swipe them away — taking
     * the rider's order offer with them.
     */
    protected function notify(Order $order, OrderStatus $to): void
    {
        match ($to) {
            OrderStatus::Accepted => $this->notifier->accepted($order),
            OrderStatus::Rejected => $this->notifier->rejected($order),
            OrderStatus::ReadyForPickup => $this->notifier->readyForPickup($order),
            OrderStatus::RiderAssigned => $this->notifier->riderAssigned($order),
            OrderStatus::ReachedRestaurant => $this->notifier->reachedRestaurant($order),
            OrderStatus::PickedUp => $this->notifier->pickedUp($order),
            OrderStatus::ReachedCustomer => $this->notifier->reachedCustomer($order),
            OrderStatus::Delivered => $this->notifier->delivered($order),
            OrderStatus::Cancelled => $this->notifier->cancelled($order),
            default => null,
        };
    }
}
