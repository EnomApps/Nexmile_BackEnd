<?php

namespace App\Http\Resources;

use App\Enums\OrderStatus;
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
                'contact_name' => $this->delivery_contact_name,
                'contact_phone' => $this->delivery_contact_phone,
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

            // Only useful once assigned; it is what the merchant reads out.
            'pickup_code_required' => $this->status === OrderStatus::RiderAssigned,

            'customer_note' => $this->customer_note,
            'placed_at' => $this->placed_at,
            'ready_at' => $this->ready_at,
            'assigned_at' => $this->assigned_at,
            'picked_up_at' => $this->picked_up_at,
            'delivered_at' => $this->delivered_at,
        ];
    }
}
