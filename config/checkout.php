<?php

use App\Enums\PaymentMethod;

return [

    /*
     * Delivery fee. Flat, because the whole service area is 1 km — a
     * per-kilometre rate would produce fees that differ by a few rupees
     * between one end of a small town and the other, which is noise dressed
     * up as precision.
     */
    'delivery_fee' => env('DELIVERY_FEE', 25),

    /*
     * Above this basket size delivery is free. Set to null to always charge.
     */
    'free_delivery_above' => env('FREE_DELIVERY_ABOVE', 299),

    /*
     * Menu prices are tax-**exclusive**: GST is added at checkout and shown as
     * its own line, which is what `orders.tax_total` and `orders.items_total`
     * being separate columns already assumes. Each item carries its own rate
     * (see config/menu.php) because prepared food and packaged goods differ.
     */
    'prices_include_tax' => false,

    /*
     * GST on the delivery fee itself, which is a service rather than food.
     */
    'delivery_gst_rate' => 18.00,

    /*
     * Methods a customer may choose today.
     *
     * Cash on delivery only. No gateway is integrated yet, and offering a
     * payment method that cannot complete is worse than not offering it — the
     * customer loses their basket at the last step.
     *
     * COD is also how most of this market already pays.
     */
    'payment_methods' => [
        PaymentMethod::Cod->value,
    ],

    /*
     * A customer may cancel until the merchant accepts. After that the kitchen
     * may already have started, and cancelling would waste food someone has
     * paid to prepare.
     */
    'customer_cancellable_statuses' => ['placed'],

    /*
     * Prefix for the human-facing order number shown to customer, merchant and
     * rider. Demo orders use NXD so they can be found and removed.
     */
    'order_number_prefix' => 'NX',

    'max_quantity_per_item' => 20,

    /*
     * Commission a new merchant starts on, as a percentage of food and
     * packaging.
     *
     * The column defaults to 0 and is not mass-assignable, which is right —
     * a merchant must never set the rate that charges them. But it also meant
     * every merchant traded at 0% until somebody noticed, so registration
     * stamps this and an admin adjusts it per contract afterwards.
     */
    'default_commission_rate' => env('DEFAULT_COMMISSION_RATE', 15),

    /*
     * Nobody may be put on more than this, whatever is typed into the admin
     * form. A fat-fingered 150 would take more than the order is worth and
     * produce a negative payout.
     */
    'max_commission_rate' => 30,

];
