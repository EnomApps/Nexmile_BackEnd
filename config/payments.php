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

    /*
    |--------------------------------------------------------------------------
    | Customer wallet
    |--------------------------------------------------------------------------
    | A closed-loop balance: money goes in by card or UPI and comes out only
    | as food.
    |
    | It must never become withdrawable to a bank account. A closed-loop wallet
    | that cannot be cashed out sits outside the RBI's prepaid payment
    | instrument licensing regime; one that can does not. Adding a withdraw
    | button is a legal decision rather than a product one.
    */

    'wallet' => [

        /*
         * Topping up needs a gateway, so this follows PAYMENT_GATEWAY by
         * default: with no gateway configured there is no way to put money in,
         * and offering the screen anyway is a dead end a customer finds for
         * themselves.
         */
        'enabled' => env('WALLET_ENABLED', true),

        /*
         * Per top-up. The floor keeps the gateway's own per-transaction cost
         * from eating the whole amount; the ceiling is a blast radius — a
         * stolen card tried against this endpoint should not be able to load
         * ten thousand rupees at a time.
         */
        'minimum_topup' => env('WALLET_MIN_TOPUP', 10),
        'maximum_topup' => env('WALLET_MAX_TOPUP', 10000),

        /*
         * The most a wallet may hold.
         *
         * RBI caps a minimum-detail prepaid instrument at ₹10,000. We are not
         * issuing one, but a balance that quietly grows past that invites the
         * question, and a customer cannot spend what they cannot order.
         */
        'maximum_balance' => env('WALLET_MAX_BALANCE', 10000),

        // The statement the app shows. Fifty is a long way back for a service
        // somebody uses twice a week.
        'statement_size' => 50,
    ],
];
