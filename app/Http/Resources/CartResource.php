<?php

namespace App\Http\Resources;

use App\Enums\FulfilmentType;
use App\Services\Orders\CartService;
use App\Services\Orders\PricingService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A cart with its money already worked out (EP5).
 *
 * The totals come from PricingService, the same code that prices the order at
 * checkout, so the figure a customer sees here is the figure they are charged.
 */
class CartResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fulfilment = FulfilmentType::tryFrom((string) $request->query('fulfilment_type'))
            ?? FulfilmentType::Delivery;

        $quote = app(PricingService::class)->quote($this->resource, $fulfilment);
        $unavailable = app(CartService::class)->unavailableItems($this->resource);

        return [
            'id' => $this->id,
            'fulfilment_type' => $fulfilment,

            'restaurant' => [
                'id' => $this->merchant->id,
                'name' => $this->merchant->business_name,
                'is_open' => $this->merchant->isOpenNow(),
                'avg_prep_time_minutes' => (int) $this->merchant->avg_prep_time_minutes,
                'supports_pickup' => (bool) $this->merchant->supports_pickup,
            ],

            /*
             * Both, on purpose. GET /carts returns every cart a customer has
             * open, and the badge on the basket icon only needs the number —
             * the app was summing `items` to get it, which meant parsing every
             * line of every cart to render one digit.
             */
            'item_count' => (int) $this->items->sum('quantity'),
            'items' => $quote['lines'],

            'totals' => [
                'items_total' => $quote['items_total'],
                'packaging_fee' => $quote['packaging_fee'],
                'delivery_fee' => $quote['delivery_fee'],
                'discount_total' => $quote['discount_total'],
                'tax_total' => $quote['tax_total'],
                'grand_total' => $quote['grand_total'],
            ],

            'free_delivery_applied' => $quote['free_delivery_applied'],
            'minimum_order_value' => $quote['minimum_order_value'],
            'meets_minimum' => $quote['meets_minimum'],

            /*
             * Named rather than counted, and left in the cart rather than
             * removed. A basket that quietly shrinks between screens is worse
             * than one that explains itself.
             */
            'unavailable_items' => $unavailable,
            'can_checkout' => $quote['meets_minimum']
                && $unavailable === []
                && $this->items->isNotEmpty()
                && $this->merchant->isOpenNow(),
        ];
    }
}
