<?php

namespace App\Services\Push;

use App\Enums\FulfilmentType;
use App\Enums\RiderStatus;
use App\Models\Order;
use App\Models\Rider;

/**
 * The moments worth interrupting someone for.
 *
 * Deliberately short. A notification for every status change trains people to
 * swipe them away, and the one that matters — a rider's order offer — then
 * goes with the rest. Each of these is a moment where someone has to act, or
 * has been waiting for news they cannot get any other way.
 *
 * Translation keys are passed, never rendered text. The wording depends on
 * the recipient's own language, and that is not known here: a rider board
 * goes to several people at once, and all of it is sent from a queue worker
 * long after the request. Rendering here posted English to everybody.
 */
class OrderNotifier
{
    public function __construct(protected PushService $push) {}

    /**
     * A new order landed in a kitchen.
     *
     * The merchant portal already chimes while it is open on a laptop. This is
     * for the shop where the tablet is on a shelf and nobody is watching it.
     */
    public function placed(Order $order): void
    {
        $this->push->toUser(
            $order->merchant?->user,
            PushService::MERCHANT,
            'push.merchant.new_order',
            ['number' => $order->order_number],
            ['type' => 'order.placed', 'order_id' => (string) $order->id],
        );
    }

    /** The kitchen said yes, with a time. */
    public function accepted(Order $order): void
    {
        $this->push->toUser(
            $order->customer,
            PushService::CUSTOMER,
            'push.customer.accepted',
            [
                'restaurant' => $order->merchant?->business_name ?? '',
                'minutes' => (int) $order->estimated_prep_minutes,
            ],
            ['type' => 'order.accepted', 'order_id' => (string) $order->id],
        );
    }

    /** The kitchen said no. Always worth a notification: money is coming back. */
    public function rejected(Order $order): void
    {
        $this->push->toUser(
            $order->customer,
            PushService::CUSTOMER,
            'push.customer.rejected',
            ['reason' => $order->cancellation_reason ?? ''],
            ['type' => 'order.rejected', 'order_id' => (string) $order->id],
        );
    }

    /**
     * Food is ready and needs a rider.
     *
     * The one that justifies the whole feature. Everything else here is
     * courtesy; this is an order that sits going cold until somebody's phone
     * buzzes in their pocket.
     */
    public function readyForPickup(Order $order): void
    {
        $this->push->toUser(
            $order->customer,
            PushService::CUSTOMER,
            'push.customer.ready',
            [],
            ['type' => 'order.ready', 'order_id' => (string) $order->id],
        );

        if ($order->fulfilment_type !== FulfilmentType::Delivery) {
            return;
        }

        $this->push->toUsers(
            $this->ridersToOffer($order),
            PushService::RIDER,
            'push.rider.offer',
            [
                'restaurant' => $order->merchant?->business_name ?? '',
                'area' => $order->delivery_city ?? '',
            ],
            ['type' => 'order.offer', 'order_id' => (string) $order->id],
        );
    }

    /** A rider took it. The kitchen now knows who is coming. */
    public function riderAssigned(Order $order): void
    {
        $this->push->toUser(
            $order->merchant?->user,
            PushService::MERCHANT,
            'push.merchant.rider_assigned',
            ['rider' => $order->rider?->full_name ?? ''],
            ['type' => 'order.rider_assigned', 'order_id' => (string) $order->id],
        );

        $this->push->toUser(
            $order->customer,
            PushService::CUSTOMER,
            'push.customer.rider_assigned',
            ['rider' => $order->rider?->full_name ?? ''],
            ['type' => 'order.rider_assigned', 'order_id' => (string) $order->id],
        );
    }

    /** On its way. The customer starts watching the map here. */
    public function pickedUp(Order $order): void
    {
        $this->push->toUser(
            $order->customer,
            PushService::CUSTOMER,
            'push.customer.picked_up',
            [],
            ['type' => 'order.picked_up', 'order_id' => (string) $order->id],
        );
    }

    /** Arrived. Also the prompt to rate it, which is where ratings come from. */
    public function delivered(Order $order): void
    {
        $this->push->toUser(
            $order->customer,
            PushService::CUSTOMER,
            'push.customer.delivered',
            [],
            ['type' => 'order.delivered', 'order_id' => (string) $order->id],
        );
    }

    /** Cancelled by someone other than the customer. */
    public function cancelled(Order $order): void
    {
        $this->push->toUser(
            $order->customer,
            PushService::CUSTOMER,
            'push.customer.cancelled',
            ['reason' => $order->cancellation_reason ?? ''],
            ['type' => 'order.cancelled', 'order_id' => (string) $order->id],
        );
    }

    /**
     * Riders who could actually take this order.
     *
     * The same gates dispatch applies, minus distance — a rider two kilometres
     * away who is on duty is still worth telling, because they may be riding
     * towards it. Offering it to someone whose licence expired is not.
     *
     * @return list<int>
     */
    private function ridersToOffer(Order $order): array
    {
        return Rider::query()
            ->where('duty_status', RiderStatus::Available->value)
            ->when($order->zone_id, fn ($q) => $q->where(fn ($w) => $w
                ->whereNull('zone_id')->orWhere('zone_id', $order->zone_id)))
            ->get()
            ->filter(fn (Rider $rider) => $rider->canAcceptOrders())
            /*
             * Never the customer's own order. A rider who ordered dinner
             * getting an offer to deliver it to themselves is the same hole
             * the board already closes.
             */
            ->filter(fn (Rider $rider) => $rider->user_id !== $order->user_id)
            ->pluck('user_id')
            ->all();
    }
}
