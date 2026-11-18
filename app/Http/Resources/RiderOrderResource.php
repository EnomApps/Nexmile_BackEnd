<?php

namespace App\Http\Resources;

use App\Services\Media\ImageService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * An order as a rider sees it (EP8, EP10).
 *
 * Shaped around the two addresses and the money they are owed. It deliberately
 * omits the merchant's payout, commission and the customer's account details:
 * a rider needs to collect from one place and deliver to another.
 */
class RiderOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order_number' => $this->order_number,
            'status' => $this->status,
            'status_label' => __('portal.status.'.$this->status->value),

            'pickup' => [
                'name' => $this->merchant?->business_name,
                'address' => trim(implode(', ', array_filter([
                    $this->merchant?->address_line1,
                    $this->merchant?->address_line2,
                    $this->merchant?->city,
                ]))),
                'phone' => $this->merchant?->business_phone,
                'latitude' => $this->merchant?->latitude !== null ? (float) $this->merchant->latitude : null,
                'longitude' => $this->merchant?->longitude !== null ? (float) $this->merchant->longitude : null,
                'distance_metres' => $this->when(
                    isset($this->pickup_distance_metres),
                    fn () => (int) $this->pickup_distance_metres,
                ),
            ],

            /*
             * The drop address is withheld until the rider has actually taken
             * the job. A board anyone on duty can poll should not double as a
             * list of where customers live.
             */
            'dropoff' => $this->when($this->rider_id !== null, fn () => [
                /*
                 * Whoever is actually taking the delivery, which is not always
                 * the person who paid. A customer ordering for their parents
                 * puts their parents' number here, and a rider who rings the
                 * account holder instead is calling a different city.
                 */
                'contact_name' => $this->delivery_contact_name,
                'contact_phone' => $this->delivery_contact_phone,

                /*
                 * Also sent apart from the composed `address` line below, so
                 * the app can give each part its own weight on screen instead
                 * of hunting for it inside one long string.
                 *
                 * The customer app fills these from three different places:
                 * `line1` is typed by hand (flat, floor, house number),
                 * `line2` is the street and `landmark` the locality, both off
                 * the map pin. The flat is what a rider reads at the gate and
                 * the street is what they navigate by, so all three go out
                 * rather than the two that were asked for.
                 */
                'line1' => $this->delivery_line1,
                'line2' => $this->delivery_line2,
                'landmark' => $this->delivery_landmark,

                /*
                 * The door itself. Snapshotted with the order, so replacing
                 * the photo on the address book does not change what the rider
                 * on an in-flight delivery is looking at.
                 */
                'photo_url' => app(ImageService::class)->doorUrl($this->delivery_photo_path),

                'address' => trim(implode(', ', array_filter([
                    $this->delivery_line1,
                    $this->delivery_line2,
                    $this->delivery_landmark,
                    $this->delivery_city,
                    $this->delivery_pincode,
                ]))),
                'latitude' => $this->delivery_latitude !== null ? (float) $this->delivery_latitude : null,
                'longitude' => $this->delivery_longitude !== null ? (float) $this->delivery_longitude : null,
            ]),

            'delivery_distance_metres' => $this->distance_metres,
            'item_count' => $this->whenLoaded('items', fn () => (int) $this->items->sum('quantity')),
            'items' => OrderItemResource::collection($this->whenLoaded('items')),

            'order_value' => (float) $this->grand_total,
            'delivery_fee' => (float) $this->delivery_fee,
            /*
             * Cash to collect at the door, or nothing if it was paid online.
             * Shown plainly because getting this wrong costs the rider money
             * out of their own pocket.
             */
            'collect_cash' => $this->cashToCollect(),

            /*
             * Only useful once assigned; it is what the merchant reads out.
             * True from the moment the order is theirs until it is in the
             * bag, including while the kitchen is still cooking — a rider
             * who arrives early still collects with a code.
             */
            'pickup_code_required' => $this->rider_id !== null && $this->picked_up_at === null,

            'customer_note' => $this->customer_note,
            'placed_at' => $this->placed_at,
            'ready_at' => $this->ready_at,
            'assigned_at' => $this->assigned_at,
            'reached_restaurant_at' => $this->reached_restaurant_at,
            'picked_up_at' => $this->picked_up_at,
            'reached_customer_at' => $this->reached_customer_at,
            'delivered_at' => $this->delivered_at,
        ];
    }
}
