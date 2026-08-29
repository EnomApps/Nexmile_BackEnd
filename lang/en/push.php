<?php

/*
|--------------------------------------------------------------------------
| Push notification copy — English
|--------------------------------------------------------------------------
| Read on a lock screen, in a pocket, mid-shift. Short enough to survive
| truncation on a narrow phone, and specific enough to be worth unlocking for:
| a title that says what happened, a body that says what it means.
|
| Sent in the recipient's own locale, so keys here must exist in ta and hi too.
*/

return [

    'merchant' => [
        'new_order' => [
            'title' => 'New order',
            'body' => 'Order #:number is waiting. Accept it to start cooking.',
        ],
        'rider_assigned' => [
            'title' => 'Rider on the way',
            'body' => ':rider is coming to collect. Have the order ready.',
        ],
    ],

    'rider' => [
        'offer' => [
            // The one that justifies the feature. Everything else is courtesy;
            // this is food going cold until somebody's phone buzzes.
            'title' => 'Delivery available',
            'body' => 'Pickup from :restaurant, dropping in :area. Open the app to accept.',
        ],
    ],

    'customer' => [
        'accepted' => [
            'title' => 'Order accepted',
            'body' => ':restaurant is cooking. About :minutes minutes.',
        ],
        'rejected' => [
            'title' => 'Order could not be accepted',
            'body' => ':reason Any payment is being refunded in full.',
        ],
        'ready' => [
            'title' => 'Your food is ready',
            'body' => 'Waiting for a delivery partner to collect it.',
        ],
        'rider_assigned' => [
            'title' => 'On its way soon',
            'body' => ':rider is collecting your order.',
        ],
        'picked_up' => [
            'title' => 'Out for delivery',
            'body' => 'Your order has left the restaurant. Track it in the app.',
        ],
        'delivered' => [
            'title' => 'Delivered',
            'body' => 'Enjoy your food. Tap to rate it.',
        ],
        'cancelled' => [
            'title' => 'Order cancelled',
            'body' => ':reason Any payment is being refunded in full.',
        ],
    ],

];
