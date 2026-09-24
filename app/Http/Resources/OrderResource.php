<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderResource extends JsonResource
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
            'fulfilment_type' => $this->fulfilment_type,

            'customer_name' => $this->delivery_contact_name ?? $this->whenLoaded('customer', fn () => $this->customer?->name),
            'customer_note' => $this->customer_note,

            'delivery_address' => $this->when($this->isDelivery(), fn () => [
                // array_filter drops the nulls; line2 and landmark are usually
                // both absent and an address full of nulls reads as an error.
                ...array_filter([
                    'line1' => $this->delivery_line1,
                    'line2' => $this->delivery_line2,
                    'landmark' => $this->delivery_landmark,
                    'city' => $this->delivery_city,
                    'pincode' => $this->delivery_pincode,
                ]),

                /*
                 * The home pin. Outside the filter deliberately — a coordinate
                 * is a number and array_filter would eat a legitimate zero,
                 * which is a bug waiting somewhere that is not Madurai.
                 *
                 * Safe here where the rider board is not: this resource is
                 * only ever the customer reading their own order, or the
                 * merchant who was given the address to cook for.
                 */
                'latitude' => $this->delivery_latitude === null ? null : (float) $this->delivery_latitude,
                'longitude' => $this->delivery_longitude === null ? null : (float) $this->delivery_longitude,
            ]),

            /*
             * The shop pin, for the tracking map.
             *
             * whenLoaded, so the merchant's own order list does not fetch the
             * merchant once per row to tell them their own address.
             */
            'restaurant' => $this->whenLoaded('merchant', fn () => [
                'id' => $this->merchant->id,
                'name' => $this->merchant->business_name,
                'address' => implode(', ', array_filter([
                    $this->merchant->address_line1,
                    $this->merchant->address_line2,
                    $this->merchant->city,
                ])),
                'phone' => $this->merchant->business_phone,
                'latitude' => $this->merchant->latitude === null ? null : (float) $this->merchant->latitude,
                'longitude' => $this->merchant->longitude === null ? null : (float) $this->merchant->longitude,
            ]),

            'distance_metres' => $this->distance_metres,

            'items_total' => (float) $this->items_total,
            'packaging_fee' => (float) $this->packaging_fee,
            'delivery_fee' => (float) $this->delivery_fee,
            'discount_total' => (float) $this->discount_total,
            'tax_total' => (float) $this->tax_total,
            'grand_total' => (float) $this->grand_total,
            // What the merchant is actually paid, after commission.
            'merchant_payout' => (float) $this->merchant_payout,

            'pickup_code' => $this->pickup_code,
            'estimated_prep_minutes' => $this->estimated_prep_minutes,

            'placed_at' => $this->placed_at,
            'accepted_at' => $this->accepted_at,
            'ready_at' => $this->ready_at,
            'delivered_at' => $this->delivered_at,
            'cancelled_at' => $this->cancelled_at,
            'cancellation_reason' => $this->cancellation_reason,

            'items' => OrderItemResource::collection($this->whenLoaded('items')),
            'timeline' => $this->whenLoaded('statusHistory', fn () => $this->statusHistory->map(fn ($row) => [
                'to_status' => $row->to_status,
                'label' => __('portal.status.'.$row->to_status->value),
                'note' => $row->note,
                'at' => $row->created_at,
            ])),
        ];
    }
}
