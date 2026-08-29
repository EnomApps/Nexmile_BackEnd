<?php

return [

    /*
     * Storefront and dish photos share the KYC bucket, which blocks all public
     * access, so they are served through signed URLs like every other object
     * we store.
     *
     * They are not sensitive — a customer is meant to see them — but a second
     * public bucket plus CloudFront is AWS console work that would block menu
     * management on infrastructure. The API returns a URL string either way,
     * so moving to a public bucket later changes nothing for the apps.
     */
    'disk' => env('MEDIA_DISK', env('KYC_DISK', 's3')),

    /*
     * Far longer than a KYC link, which grants access to an Aadhaar. A dish
     * photo leaks nothing, and a short TTL would mean a customer scrolling a
     * menu for ten minutes watches the images expire underneath them.
     *
     * It also has to outlive the client's own image cache, or every scroll
     * re-downloads.
     */
    'url_ttl_minutes' => 60 * 24,

    'max_size_kb' => 4096,

    'mimes' => ['jpg', 'jpeg', 'png', 'webp'],

    /*
     * Banners may animate; dish photos and cuisine icons may not.
     *
     * A moving carousel slide is a design choice. A dish photo that animates
     * is a menu that will not sit still to be read, and sixty animating
     * cuisine icons on one screen is a phone getting warm.
     *
     * svg is deliberately absent from both. Laravel's `image` rule allows it,
     * and an SVG is a document that can carry script — not something to accept
     * from an upload and serve back under our own domain.
     */
    'banner_mimes' => ['jpg', 'jpeg', 'png', 'webp', 'gif'],

    /*
     * Slides in a restaurant's carousel.
     *
     * A carousel nobody swipes to the end of costs data for nothing, and every
     * slide is a signed URL the app fetches when the storefront opens.
     */
    'max_storefront_photos' => 8,

];
