<?php

return [

    /*
     * Dish photos share the KYC bucket, which blocks all public access, so
     * they are served through signed URLs like every other object we store.
     *
     * They are not sensitive — a customer is meant to see them — but a second
     * public bucket plus CloudFront is AWS console work that would block menu
     * management on infrastructure. The API returns a URL string either way,
     * so moving to a public bucket later changes nothing for the apps.
     */
    'image_disk' => env('MENU_DISK', env('KYC_DISK', 's3')),

    /*
     * Far longer than a KYC link, which grants access to an Aadhaar. A dish
     * photo leaks nothing, and a short TTL would mean a customer scrolling a
     * menu for ten minutes watches the images expire underneath them.
     *
     * It also has to outlive the client's own image cache, or every scroll
     * re-downloads.
     */
    'image_url_ttl_minutes' => 60 * 24,

    'image_max_size_kb' => 4096,

    'image_mimes' => ['jpg', 'jpeg', 'png', 'webp'],

    /*
     * GST on prepared food is 5%. Air-conditioned restaurants and some
     * packaged goods attract 18%, so the merchant can override per item, but
     * these are the only two rates they may choose (EP11).
     */
    'gst_rates' => [5.00, 12.00, 18.00],

];
