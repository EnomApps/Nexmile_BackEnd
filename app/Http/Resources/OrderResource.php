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

            'delivery_address' => $this->when($this->isDelivery(), fn () => array_filter([
                'line1' => $this->delivery_line1,
                'line2' => $this->delivery_line2,
                'landmark' => $this->delivery_landmark,
                'city' => $this->delivery_city,
                'pincode' => $this->delivery_pincode,
            ])),
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
