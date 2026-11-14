<?php

namespace App\Http\Resources;

use App\Services\Riders\RiderPayoutService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One ringing order card, over HTTP.
 *
 * The app fetches this when it starts cold, when it comes back from the
 * background, and whenever it wants to be sure the card on screen is still
 * real — the push message starts the ring, this is the source of truth about
 * it.
 *
 * **Flat, and deliberately the same field names as the push payload.** The
 * app parses both with one parser, so a nested shape here would mean two
 * parsers for one card and a second place for the card to be drawn wrong.
 * Every key `OfferDispatchService::offerPayload()` sends appears here under
 * the same name and the same meaning; this response carries a few extra that
 * are too long or too private to put in a 4 KB push. A test asserts the
 * subset holds, because the two drift the moment nothing is watching.
 *
 * The types are real here — numbers are numbers — where FCM allows only
 * strings. That is the one difference, and the reason the app should parse
 * through a tolerant cast rather than a direct type assertion.
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
            'order_id' => $order->id,
            'order_number' => $order->order_number,

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

            // Where the food is.
            'restaurant' => $order->merchant?->business_name,
            'restaurant_area' => $order->merchant?->city,
            'restaurant_latitude' => $order->merchant?->latitude !== null ? (float) $order->merchant->latitude : null,
            'restaurant_longitude' => $order->merchant?->longitude !== null ? (float) $order->merchant->longitude : null,
            'pickup_distance_metres' => $this->distance_metres,

            // The drop area, not the drop address. Enough to judge the job.
            'dropoff_area' => $order->delivery_city,
            'delivery_distance_metres' => $order->distance_metres,

            'item_count' => (int) $order->items->sum('quantity'),

            /*
             * The kitchen's state, because it decides what the rider does
             * next. Offered at acceptance, the food is still being cooked —
             * a rider who rides straight there waits at the counter, and
             * should be told the wait is expected.
             */
            'order_status' => $order->status,
            'estimated_prep_minutes' => $order->estimated_prep_minutes,

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
            'collect_cash' => $order->cashToCollect(),

            // HTTP only. Too long for a push payload with a 4 KB ceiling.
            'order_value' => (float) $order->grand_total,
            'ready_at' => $order->ready_at,
            'customer_note' => $order->customer_note,
        ];
    }
}
