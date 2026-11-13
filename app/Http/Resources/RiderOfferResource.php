<?php

namespace App\Http\Resources;

use App\Services\Riders\RiderPayoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ringing order card, over HTTP.
 *
 * The same facts as the push payload, in proper types rather than the flat
 * strings FCM is limited to. The app fetches this when it starts cold, when
 * it comes back from the background, and whenever it wants to be sure the
 * card on screen is still real — the push message starts the ring, this is
 * the source of truth about it.
 *
 * No customer address and no customer phone, same as the push payload. A
 * rider who is only being asked has not been trusted with where somebody
 * lives, and an offer can go to fifteen phones.
 */
class RiderOfferResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $order = $this->order;

        return [
            'offer_id' => $this->ulid,
            'status' => $this->status,

            /*
             * Both are given. `expires_at` is what the countdown should run
             * to, because the server's clock is the one that decides; the
             * seconds are there so a phone with a badly wrong clock still
             * shows something sensible rather than a card that is already
             * over or never ends.
             */
            'expires_at' => $this->expires_at,
            'seconds_left' => $this->secondsLeft(),
            'wave' => $this->wave,

            'order' => [
                'id' => $order->id,
                'order_number' => $order->order_number,
                'item_count' => (int) $order->items->sum('quantity'),
                'order_value' => (float) $order->grand_total,
                'collect_cash' => $order->cashToCollect(),
                'delivery_distance_metres' => $order->distance_metres,
                'customer_note' => $order->customer_note,

                /*
                 * The kitchen's state, because it decides what the rider
                 * does next. Offered at acceptance, the food is still being
                 * cooked — a rider who rides straight there waits at the
                 * counter, and should be told the wait is expected.
                 */
                'status' => $order->status,
                'ready_at' => $order->ready_at,
                'estimated_prep_minutes' => $order->estimated_prep_minutes,
            ],

            'pickup' => [
                'name' => $order->merchant?->business_name,
                'area' => $order->merchant?->city,
                'latitude' => $order->merchant?->latitude !== null ? (float) $order->merchant->latitude : null,
                'longitude' => $order->merchant?->longitude !== null ? (float) $order->merchant->longitude : null,
                'distance_metres' => $this->distance_metres,
            ],

            // Area only, until it is theirs.
            'dropoff' => [
                'area' => $order->delivery_city,
            ],

            /*
             * Quoted with the code that settles it, so a rider is never
             * shown a number that later turns out to be a different number.
             * Falls back to the delivery fee if the quote cannot be built —
             * a card with no money on it is a card nobody accepts.
             */
            'earnings' => rescue(
                fn () => round((float) app(RiderPayoutService::class)->calculate($order)['total'], 2),
                round((float) $order->delivery_fee, 2),
                report: false,
            ),
        ];
    }
}
