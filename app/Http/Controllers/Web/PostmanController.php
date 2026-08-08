<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the Postman collections over HTTP.
 *
 * They live in `docs/postman`, which is outside the web root, so there is no
 * URL for them by default — an app developer without repo access had no way to
 * get one.
 *
 * **Allowlisted by name, never by path.** Serving the docs directory would
 * also serve DEPLOYMENT.md, MAPS.md and KYC.md, which describe the
 * infrastructure and belong to nobody outside the team.
 */
class PostmanController extends Controller
{
    private const COLLECTIONS = [
        'customer' => 'Nexmile-Customer.postman_collection.json',
        'rider' => 'Nexmile-Rider.postman_collection.json',
        'merchant' => 'Nexmile-Merchant.postman_collection.json',
    ];

    /**
     * Download one collection.
     */
    public function download(string $app): BinaryFileResponse
    {
        // A key from a fixed map, so no request input ever reaches the path.
        abort_unless(isset(self::COLLECTIONS[$app]), 404);

        $file = self::COLLECTIONS[$app];
        $path = base_path('docs/postman/'.$file);

        abort_unless(is_file($path), 404);

        /*
         * Downloaded rather than rendered: Postman imports a file, and a
         * browser showing 40 KB of raw JSON helps nobody.
         */
        return response()->download($path, $file, [
            'Content-Type' => 'application/json',
            // Safe to cache briefly; regenerated only when routes change.
            'Cache-Control' => 'public, max-age=300',
        ]);
    }

    /**
     * The three, with a one-line description each.
     */
    public function index(): View
    {
        return view('postman', [
            'collections' => [
                ['app' => 'customer', 'name' => 'Customer app', 'requests' => 35,
                    'blurb' => 'Sign in, addresses, restaurants, Food Rescue deals, cart, checkout, tracking, invoices.'],
                ['app' => 'rider', 'name' => 'Rider app', 'requests' => 21,
                    'blurb' => 'Sign in, the onboarding wizard, duty status, location, the order board, pickup and delivery.'],
                ['app' => 'merchant', 'name' => 'Merchant', 'requests' => 39,
                    'blurb' => 'Sign in, storefront and hours, KYC, menu, choices, and the order queue.'],
            ],
        ]);
    }
}
