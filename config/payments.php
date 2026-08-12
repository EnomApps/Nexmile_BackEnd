<?php

use App\Enums\PaymentMethod;

return [

    /*
     * Which gateway takes online payments.
     *
     * `null` means cash only — the app is told online payment is unavailable
     * rather than being offered a method that cannot complete. That is the
     * state until real Razorpay keys exist, and it is also the right state for
     * local development.
     */
    'gateway' => env('PAYMENT_GATEWAY'),

    /*
     * Razorpay is an aggregator: one integration covers UPI (GPay, PhonePe,
     * Paytm), cards, netbanking and wallets. Integrating those separately
     * would mean several merchant accounts and several settlement cycles for
     * the same money.
     */
    'razorpay' => [
        'key' => env('RAZORPAY_KEY_ID'),
        'secret' => env('RAZORPAY_KEY_SECRET'),

        /*
         * Set when creating the webhook in the Razorpay dashboard. Without it
         * anyone who learns the URL can post a fake "payment captured" and
         * push an unpaid order into a kitchen, so a webhook that cannot be
         * verified is rejected rather than trusted.
         */
        'webhook_secret' => env('RAZORPAY_WEBHOOK_SECRET'),

        'base_url' => 'https://api.razorpay.com/v1',
    ],

    /*
     * Methods a customer may choose. Cash stays whatever else is enabled —
     * it is how most of this market still pays, and it is the only one that
     * works when a customer's bank is having a bad morning.
     */
    'methods' => [
        PaymentMethod::Cod->value,
        PaymentMethod::Upi->value,
        PaymentMethod::Card->value,
        PaymentMethod::NetBanking->value,
        PaymentMethod::Wallet->value,
    ],

    /*
     * How long an unpaid order may sit before it is cancelled and its stock
     * released.
     *
     * A customer who closes the app mid-payment leaves an order nobody will
     * ever pay for, and if it holds the last Food Rescue portion nobody else
     * can buy it either. Long enough to survive a slow bank page, short enough
     * that a rescue deal is not held hostage.
     */
    'abandon_after_minutes' => 20,

    /*
     * Razorpay works in paise, and so does every amount we send it. Rupees
     * only ever appear at the edges.
     */
    'currency' => 'INR',

];
