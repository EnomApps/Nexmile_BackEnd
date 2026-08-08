<?php

namespace App\Services\Orders;

use App\Models\Order;

/**
 * Tax invoices (EP11).
 *
 * Every figure is read off the order, never recomputed. The order snapshotted
 * its prices and rates when it was placed, and an invoice that disagrees with
 * what the customer paid is worse than no invoice at all.
 */
class InvoiceService
{
    /**
     * @return array<string, mixed>
     */
    public function build(Order $order): array
    {
        $order->loadMissing(['merchant', 'items.options', 'customer', 'payments']);

        return [
            /*
             * Derived from the order number rather than a separate sequence.
             * A gapless invoice series is a statutory requirement and a
             * counter that can be written twice is how gaps appear; the order
             * number is already unique and already on every other document.
             */
            'number' => 'INV-'.$order->order_number,
            'date' => $order->delivered_at ?? $order->placed_at ?? $order->created_at,
            'order' => $order,
            'seller' => [
                'name' => $order->merchant?->business_name,
                'address' => $this->sellerAddress($order),
                'gstin' => $order->merchant?->gstin,
                'fssai' => $order->merchant?->fssai_license_no,
            ],
            'buyer' => [
                'name' => $order->delivery_contact_name ?? $order->customer?->name,
                'phone' => $order->delivery_contact_phone ?? $order->customer?->phone,
                'address' => $order->isDelivery() ? $this->buyerAddress($order) : null,
            ],
            'lines' => $this->lines($order),
            'tax_groups' => $this->taxGroups($order),
            'totals' => [
                'taxable' => round((float) $order->items_total + (float) $order->packaging_fee, 2),
                'delivery_fee' => (float) $order->delivery_fee,
                'discount' => (float) $order->discount_total,
                'tax' => (float) $order->tax_total,
                'grand_total' => (float) $order->grand_total,
            ],
            'payment' => [
                'method' => $order->payments->first()?->method?->value,
                'status' => $order->payments->first()?->status?->value,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function lines(Order $order): array
    {
        return $order->items->map(fn ($item) => [
            'name' => $item->name,
            'options' => $item->options->map(fn ($o) => $o->group_name.': '.$o->name)->all(),
            'quantity' => (int) $item->quantity,
            'unit_price' => (float) $item->unit_price,
            'options_total' => (float) $item->options_total,
            'taxable' => (float) $item->line_total,
            'gst_rate' => (float) $item->gst_rate,
            'tax' => (float) $item->tax_amount,
            'total' => round((float) $item->line_total + (float) $item->tax_amount, 2),
        ])->all();
    }

    /**
     * GST split by rate.
     *
     * Nexmile delivers within 1 km, so supply is always intra-state and the
     * tax divides equally into CGST and SGST. An inter-state order would be
     * IGST instead — impossible here by construction, which is why this does
     * not branch on it.
     *
     * @return list<array<string, mixed>>
     */
    protected function taxGroups(Order $order): array
    {
        $groups = $order->items
            ->groupBy(fn ($item) => (string) (float) $item->gst_rate)
            ->map(fn ($rows, $rate) => [
                'rate' => (float) $rate,
                'taxable' => round($rows->sum(fn ($i) => (float) $i->line_total), 2),
                'tax' => round($rows->sum(fn ($i) => (float) $i->tax_amount), 2),
            ])
            ->values()
            ->all();

        /*
         * The delivery fee is a service at its own rate and appears as its own
         * line, rather than being folded into the food rates — which would
         * misstate both.
         */
        $deliveryTax = round((float) $order->tax_total - array_sum(array_column($groups, 'tax')), 2);

        if ($deliveryTax > 0) {
            $groups[] = [
                'rate' => (float) config('checkout.delivery_gst_rate'),
                'taxable' => (float) $order->delivery_fee,
                'tax' => $deliveryTax,
                'is_delivery' => true,
            ];
        }

        return array_map(fn ($group) => [
            ...$group,
            'cgst' => round($group['tax'] / 2, 2),
            'sgst' => round($group['tax'] / 2, 2),
        ], $groups);
    }

    protected function sellerAddress(Order $order): string
    {
        $merchant = $order->merchant;

        return trim(implode(', ', array_filter([
            $merchant?->address_line1,
            $merchant?->address_line2,
            $merchant?->city,
            $merchant?->state,
            $merchant?->pincode,
        ])));
    }

    protected function buyerAddress(Order $order): string
    {
        return trim(implode(', ', array_filter([
            $order->delivery_line1,
            $order->delivery_line2,
            $order->delivery_landmark,
            $order->delivery_city,
            $order->delivery_pincode,
        ])));
    }
}
