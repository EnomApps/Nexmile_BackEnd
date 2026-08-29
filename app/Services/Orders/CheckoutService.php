<?php

namespace App\Services\Orders;

use App\Enums\FulfilmentType;
use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Address;
use App\Models\Cart;
use App\Models\Order;
use App\Models\User;
use App\Services\Discovery\NearbyMerchantService;
use App\Services\LiveState\OrderStateService;
use App\Services\Menu\SurplusService;
use App\Services\Payments\PaymentService;
use App\Services\Push\OrderNotifier;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Turning a cart into an order (EP5).
 *
 * Everything a customer could have changed since they started shopping is
 * re-checked here: prices, availability, whether the kitchen is still open,
 * whether they are still inside the radius. A cart is a working document; an
 * order is a commitment, and the gap between the two is where a bad ticket
 * reaches a kitchen.
 */
class CheckoutService
{
    public function __construct(
        protected PricingService $pricing,
        protected CartService $carts,
        protected NearbyMerchantService $nearby,
        protected OrderStateService $liveState,
        protected SurplusService $surplus,
        protected OrderNotifier $notifier,
    ) {}

    /**
     * @throws ValidationException
     */
    public function place(
        User $user,
        Cart $cart,
        FulfilmentType $fulfilment,
        PaymentMethod $method,
        ?Address $address = null,
        ?string $note = null,
    ): Order {
        $cart->loadMissing(['merchant', 'items.menuItem', 'items.options.itemOption.group']);

        $this->guardPaymentMethod($method);
        $this->guardCart($cart);
        $this->guardMerchant($cart);

        $distance = $fulfilment === FulfilmentType::Delivery
            ? $this->guardDeliverable($cart, $address)
            : null;

        $quote = $this->pricing->quote($cart, $fulfilment);

        $this->guardMinimum($quote);

        $order = DB::transaction(function () use ($user, $cart, $fulfilment, $method, $address, $note, $quote, $distance) {
            $order = Order::create([
                'order_number' => $this->orderNumber(),
                'user_id' => $user->id,
                'merchant_id' => $cart->merchant_id,
                'zone_id' => $cart->merchant->zone_id,

                /*
                 * COD is placed immediately — there is nothing to wait for.
                 * An online order sits at pending_payment until the provider
                 * confirms, which is why the merchant queue filters that
                 * status out: a kitchen must never start on money that has
                 * not moved.
                 */
                'status' => $method === PaymentMethod::Cod
                    ? OrderStatus::Placed
                    : OrderStatus::PendingPayment,
                'fulfilment_type' => $fulfilment,

                ...$this->addressSnapshot($address, $fulfilment),
                'distance_metres' => $distance,

                'items_total' => $quote['items_total'],
                'packaging_fee' => $quote['packaging_fee'],
                'delivery_fee' => $quote['delivery_fee'],
                'discount_total' => $quote['discount_total'],
                'tax_total' => $quote['tax_total'],
                'grand_total' => $quote['grand_total'],
                'commission_amount' => $quote['commission_amount'],
                'merchant_payout' => $quote['merchant_payout'],

                // Four digits, read aloud in a noisy kitchen doorway.
                'pickup_code' => str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT),
                'customer_note' => $note,
                'estimated_prep_minutes' => $cart->merchant->avg_prep_time_minutes,
                // Stamped when the order actually reaches the kitchen, which
                // for an online order is when the money lands, not now.
                'placed_at' => $method === PaymentMethod::Cod ? now() : null,
            ]);

            /*
             * Rescue portions are claimed inside the transaction, before the
             * order is considered placed. Two customers taking the last two
             * portions at the same instant must not both succeed, and if the
             * claim fails the order must not exist at all.
             */
            foreach ($cart->items as $line) {
                if ($line->menuItem?->is_surplus_deal) {
                    $this->surplus->claim($line->menuItem, (int) $line->quantity);
                }
            }

            $this->snapshotItems($order, $quote['lines']);

            $order->payments()->create([
                'user_id' => $user->id,
                'method' => $method,
                // Cash is collected at the door; nothing is captured now.
                'status' => PaymentStatus::Pending,
                'amount' => $quote['grand_total'],
            ]);

            $order->statusHistory()->create([
                'from_status' => null,
                'to_status' => $order->status,
                'changed_by_user_id' => $user->id,
                'created_at' => now(),
            ]);

            // The cart is consumed, not kept. Leaving it would let a customer
            // place the same order twice by tapping back.
            $this->carts->clear($cart);

            return $order;
        });

        rescue(fn () => $this->liveState->setStatus($order->id, $order->status), report: true);

        /*
         * Tell the kitchen, but only once the order is really theirs to cook.
         * An online order sits in pending_payment until the money lands, and a
         * merchant woken for an order that may never be paid for is a merchant
         * who stops trusting the alert.
         */
        if ($order->status === OrderStatus::Placed) {
            $this->notifier->placed($order);
        }

        return $order->load('items.options');
    }

    /**
     * Copy names, prices and tax rates onto the order.
     *
     * The order must survive the merchant renaming a dish or changing its
     * price. Reading those through a live relation would rewrite history and,
     * worse, rewrite an invoice.
     *
     * @param  list<array<string, mixed>>  $lines
     */
    protected function snapshotItems(Order $order, array $lines): void
    {
        foreach ($lines as $line) {
            $item = $order->items()->create([
                'menu_item_id' => $line['menu_item_id'],
                'name' => $line['name'],
                'unit_price' => $line['unit_price'],
                'quantity' => $line['quantity'],
                'options_total' => $line['options_total'],
                'line_total' => $line['line_total'],
                'gst_rate' => $line['gst_rate'],
                'tax_amount' => $line['tax_amount'],
                'is_veg' => $line['is_veg'],
                'notes' => $line['notes'],
            ]);

            foreach ($line['options'] as $option) {
                $item->options()->create([
                    'item_option_id' => $option['item_option_id'],
                    'group_name' => $option['group_name'],
                    'name' => $option['name'],
                    'price_delta' => $option['price_delta'],
                ]);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function addressSnapshot(?Address $address, FulfilmentType $fulfilment): array
    {
        if ($fulfilment === FulfilmentType::Pickup || $address === null) {
            return [];
        }

        return [
            'delivery_contact_name' => $address->contact_name,
            'delivery_contact_phone' => $address->contact_phone,
            'delivery_line1' => $address->line1,
            'delivery_line2' => $address->line2,
            'delivery_landmark' => $address->landmark,
            'delivery_city' => $address->city,
            'delivery_pincode' => $address->pincode,
            'delivery_latitude' => $address->latitude,
            'delivery_longitude' => $address->longitude,
        ];
    }

    /**
     * @throws ValidationException
     */
    protected function guardCart(Cart $cart): void
    {
        if ($cart->items->isEmpty()) {
            $this->fail('cart', 'Your cart is empty.');
        }

        if ($unavailable = $this->carts->unavailableItems($cart)) {
            // Named, not just counted: the customer has to know what to remove.
            $this->fail('cart', count($unavailable) === 1
                ? "{$unavailable[0]} is no longer available. Remove it to continue."
                : implode(', ', $unavailable).' are no longer available. Remove them to continue.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function guardMerchant(Cart $cart): void
    {
        $merchant = $cart->merchant;

        if (! $merchant->isKycVerified()) {
            $this->fail('merchant', 'This restaurant is not taking orders.');
        }

        if (! $merchant->is_accepting_orders) {
            $this->fail('merchant', 'This restaurant has stopped taking orders for now.');
        }

        if (! $merchant->isWithinOperatingHours()) {
            $this->fail('merchant', 'This restaurant is closed right now.');
        }

        if (! $merchant->hasValidFssai()) {
            // Not the customer's problem to solve, so it does not say why.
            $this->fail('merchant', 'This restaurant is not taking orders.');
        }
    }

    /**
     * The 1 km promise, checked against the address actually being delivered
     * to rather than wherever the customer was browsing from.
     *
     * @throws ValidationException
     */
    protected function guardDeliverable(Cart $cart, ?Address $address): int
    {
        if ($address === null) {
            $this->fail('address_id', 'Choose a delivery address.');
        }

        if ($address->latitude === null || $address->longitude === null) {
            $this->fail('address_id', 'This address has no location saved. Edit it and drop a pin.');
        }

        $merchant = $cart->merchant;

        if ($merchant->latitude === null || $merchant->longitude === null) {
            $this->fail('merchant', 'This restaurant is not taking orders.');
        }

        $distance = $this->nearby->distance(
            (float) $address->latitude,
            (float) $address->longitude,
            (float) $merchant->latitude,
            (float) $merchant->longitude,
        );

        $radius = $this->nearby->radiusFor((float) $address->latitude, (float) $address->longitude);

        if ($distance > $radius) {
            $this->fail('address_id', 'This restaurant does not deliver to that address.');
        }

        return (int) round($distance);
    }

    /**
     * @param  array<string, mixed>  $quote
     *
     * @throws ValidationException
     */
    protected function guardMinimum(array $quote): void
    {
        if (! $quote['meets_minimum']) {
            $this->fail('cart', 'This restaurant has a minimum order of ₹'
                .number_format($quote['minimum_order_value'], 2).'.');
        }
    }

    /**
     * @throws ValidationException
     */
    protected function guardPaymentMethod(PaymentMethod $method): void
    {
        if (! in_array($method->value, PaymentService::availableMethods(), true)) {
            $this->fail('payment_method', $method === PaymentMethod::Cod
                ? 'Cash on delivery is not available for this order.'
                : 'Online payment is not available yet. Choose cash on delivery.');
        }
    }

    /**
     * Unique, short, and readable over a phone line.
     *
     * Collisions are astronomically unlikely but the column is unique, so a
     * retry is cheaper than an exception reaching a customer mid-checkout.
     */
    protected function orderNumber(): string
    {
        $prefix = config('checkout.order_number_prefix').now()->format('ymd');

        do {
            $number = $prefix.strtoupper(Str::random(4));
        } while (Order::withTrashed()->where('order_number', $number)->exists());

        return $number;
    }

    /**
     * @throws ValidationException
     */
    protected function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
