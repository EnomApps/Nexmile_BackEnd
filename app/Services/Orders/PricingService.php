<?php

namespace App\Services\Orders;

use App\Enums\FulfilmentType;
use App\Models\Cart;
use App\Models\CartItem;

/**
 * Every rupee the customer is charged (EP5, EP11).
 *
 * The only place money is calculated. The cart preview and the order that is
 * actually written both come through here, so what the customer was shown and
 * what they are billed cannot disagree.
 *
 * **Nothing here trusts a client-supplied amount.** Totals arriving in a
 * request are ignored entirely rather than validated — a validated total is
 * still a number the customer chose.
 */
class PricingService
{
    /**
     * A full breakdown for a cart.
     *
     * Returned as a plain array rather than a model: this is the same shape
     * whether it is a preview of an unsaved cart or the figures being written
     * onto an order.
     *
     * @return array<string, mixed>
     */
    public function quote(Cart $cart, FulfilmentType $fulfilment): array
    {
        $cart->loadMissing(['items.menuItem', 'items.options.itemOption', 'merchant']);

        $lines = $cart->items->map(fn (CartItem $item) => $this->line($item));

        $itemsTotal = $this->round($lines->sum('line_total'));
        $itemsTax = $this->round($lines->sum('tax_amount'));

        $packaging = $this->round((float) ($cart->merchant->packaging_fee ?? 0));
        $delivery = $this->deliveryFee($itemsTotal, $fulfilment);
        $deliveryTax = $this->round($delivery * (float) config('checkout.delivery_gst_rate') / 100);

        $discount = 0.00;
        $taxTotal = $this->round($itemsTax + $deliveryTax);

        $grandTotal = $this->round($itemsTotal + $packaging + $delivery - $discount + $taxTotal);

        /*
         * Commission is charged on food, not on the delivery fee — that fee
         * pays the rider and is not the merchant's revenue to be taxed on.
         */
        $commissionRate = (float) ($cart->merchant->commission_rate ?? 0);
        $commission = $this->round(($itemsTotal + $packaging) * $commissionRate / 100);

        return [
            'lines' => $lines->all(),
            'items_total' => $itemsTotal,
            'packaging_fee' => $packaging,
            'delivery_fee' => $delivery,
            'discount_total' => $discount,
            'tax_total' => $taxTotal,
            'grand_total' => $grandTotal,
            'commission_amount' => $commission,
            'merchant_payout' => $this->round($itemsTotal + $packaging - $commission),
            'free_delivery_applied' => $fulfilment === FulfilmentType::Delivery && $delivery === 0.00,
            'minimum_order_value' => (float) ($cart->merchant->min_order_value ?? 0),
            'meets_minimum' => $itemsTotal >= (float) ($cart->merchant->min_order_value ?? 0),
        ];
    }

    /**
     * One cart line, priced with its chosen options.
     *
     * @return array<string, mixed>
     */
    public function line(CartItem $item): array
    {
        $unitPrice = (float) ($item->menuItem->price ?? 0);

        $optionsTotal = $this->round(
            (float) $item->options->sum(fn ($option) => (float) ($option->itemOption->price_delta ?? 0)),
        );

        $quantity = (int) $item->quantity;
        $lineTotal = $this->round(($unitPrice + $optionsTotal) * $quantity);

        $gstRate = (float) ($item->menuItem->gst_rate ?? 0);

        return [
            'cart_item_id' => $item->id,
            'menu_item_id' => $item->menu_item_id,
            'name' => $item->menuItem->name ?? '',
            'is_veg' => (bool) ($item->menuItem->is_veg ?? true),
            'unit_price' => $unitPrice,
            'options_total' => $optionsTotal,
            'quantity' => $quantity,
            'line_total' => $lineTotal,
            'gst_rate' => $gstRate,
            'tax_amount' => $this->taxOn($lineTotal, $gstRate),
            'notes' => $item->notes,
            'options' => $item->options->map(fn ($option) => [
                'item_option_id' => $option->item_option_id,
                'group_name' => $option->itemOption->group->name ?? '',
                'name' => $option->itemOption->name ?? '',
                'price_delta' => (float) ($option->itemOption->price_delta ?? 0),
            ])->all(),
        ];
    }

    /**
     * Free above the threshold, and never charged on self-pickup — there is no
     * delivery to pay for.
     */
    public function deliveryFee(float $itemsTotal, FulfilmentType $fulfilment): float
    {
        if ($fulfilment === FulfilmentType::Pickup) {
            return 0.00;
        }

        $threshold = config('checkout.free_delivery_above');

        if ($threshold !== null && $itemsTotal >= (float) $threshold) {
            return 0.00;
        }

        return $this->round((float) config('checkout.delivery_fee'));
    }

    /**
     * Tax on a line.
     *
     * With tax-exclusive prices this is a straight percentage. With inclusive
     * prices the tax is already inside the figure and has to be extracted,
     * which is a different calculation — not the same number rounded
     * differently.
     */
    public function taxOn(float $amount, float $ratePercent): float
    {
        if ($ratePercent <= 0) {
            return 0.00;
        }

        if (config('checkout.prices_include_tax')) {
            return $this->round($amount - ($amount * 100 / (100 + $ratePercent)));
        }

        return $this->round($amount * $ratePercent / 100);
    }

    /**
     * Two decimal places, half-up.
     *
     * Every intermediate total is rounded as it is produced rather than once
     * at the end, so the lines a customer can add up themselves sum to the
     * total they are charged. A breakdown that is a paisa out is a support
     * ticket, however defensible the arithmetic.
     */
    private function round(float $amount): float
    {
        return round($amount, 2);
    }
}
