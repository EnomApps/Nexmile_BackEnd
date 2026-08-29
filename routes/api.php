<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\Admin\KycReviewController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\CartController;
use App\Http\Controllers\Api\V1\CollectionController;
use App\Http\Controllers\Api\V1\DeviceTokenController;
use App\Http\Controllers\Api\V1\FavouriteController;
use App\Http\Controllers\Api\V1\FilterController;
use App\Http\Controllers\Api\V1\HomeController;
use App\Http\Controllers\Api\V1\Merchant\AuthController as MerchantAuthController;
use App\Http\Controllers\Api\V1\Merchant\CategoryController;
use App\Http\Controllers\Api\V1\Merchant\KycController as MerchantKycController;
use App\Http\Controllers\Api\V1\Merchant\MenuItemController;
use App\Http\Controllers\Api\V1\Merchant\OptionGroupController;
use App\Http\Controllers\Api\V1\Merchant\OrderController as MerchantOrderController;
use App\Http\Controllers\Api\V1\Merchant\ProfileController as MerchantProfileController;
use App\Http\Controllers\Api\V1\Merchant\StorefrontController;
use App\Http\Controllers\Api\V1\OrderController;
use App\Http\Controllers\Api\V1\PaymentController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RestaurantController;
use App\Http\Controllers\Api\V1\ReviewController;
use App\Http\Controllers\Api\V1\Rider\KycController as RiderKycController;
use App\Http\Controllers\Api\V1\Rider\LocationController as RiderLocationController;
use App\Http\Controllers\Api\V1\Rider\OrderController as RiderOrderController;
use App\Http\Controllers\Api\V1\Rider\ProfileController as RiderProfileController;
use App\Http\Controllers\Api\WebhookController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Provider webhooks
|--------------------------------------------------------------------------
| Outside the versioned group and unauthenticated by necessity — Razorpay has
| no token to present. The signature is the only thing between this URL and a
| stranger pushing unpaid orders into kitchens, so it is checked before
| anything else is read.
|
| Also outside `throttle`: the provider retries on failure, and rate-limiting
| a retry storm turns one lost payment into many.
*/
Route::post('webhooks/razorpay', [WebhookController::class, 'razorpay'])->name('webhooks.razorpay');

// Names are prefixed with `api.` so they never collide with the web routes.
Route::prefix('v1')->name('api.v1.')->group(function () {

    /*
    |--------------------------------------------------------------------------
    | Mobile OTP auth — EP2, all roles
    |--------------------------------------------------------------------------
    */
    Route::prefix('auth')->name('auth.')->group(function () {
        Route::post('otp/request', [OtpAuthController::class, 'request'])->name('otp.request');
        Route::post('otp/verify', [OtpAuthController::class, 'verify'])->name('otp.verify');
        Route::post('refresh', [OtpAuthController::class, 'refresh'])->name('refresh');

        Route::middleware('auth:sanctum')->group(function () {
            Route::get('me', [OtpAuthController::class, 'me'])->name('me');
            Route::post('logout', [OtpAuthController::class, 'logout'])->name('logout');
            Route::post('logout-all', [OtpAuthController::class, 'logoutAll'])->name('logout-all');
            Route::get('sessions', [OtpAuthController::class, 'sessions'])->name('sessions');
            Route::delete('sessions/{session}', [OtpAuthController::class, 'revokeSession'])->name('sessions.revoke');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Profile — every role
    |--------------------------------------------------------------------------
    */
    Route::middleware('auth:sanctum')->group(function () {
        Route::get('profile', [ProfileController::class, 'show'])->name('profile.show');
        Route::patch('profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::delete('profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

        /*
        |----------------------------------------------------------------------
        | Customer address book — EP2, feeds EP4 discovery
        |----------------------------------------------------------------------
        */
        Route::prefix('addresses')->name('addresses.')->group(function () {
            Route::get('/', [AddressController::class, 'index'])->name('index');
            Route::post('/', [AddressController::class, 'store'])->name('store');
            Route::get('{address}', [AddressController::class, 'show'])->name('show');
            Route::patch('{address}', [AddressController::class, 'update'])->name('update');
            Route::post('{address}/default', [AddressController::class, 'makeDefault'])->name('default');
            Route::delete('{address}', [AddressController::class, 'destroy'])->name('destroy');
        });

        /*
        |----------------------------------------------------------------------
        | Restaurant discovery and menu browsing — EP3, EP4
        |----------------------------------------------------------------------
        | Not role-gated. A rider ordering dinner and a merchant ordering from
        | the shop opposite are both customers here — see docs/ROLES.md.
        */
        /*
         * The home screen as ordered sections, and the filter sheet's own
         * definitions. Both exist so that merchandising and filtering can
         * change from the server rather than through a Play Store release.
         */
        Route::get('home', [HomeController::class, 'index'])->name('home');
        Route::get('filters', [FilterController::class, 'index'])->name('filters');
        Route::get('collections/{slug}', [CollectionController::class, 'show'])->name('collections.show');
        /*
         * Push registration, shared by both apps. A rider's phone is in their
         * pocket with the screen off, and a suspended app's socket is dead —
         * this is the only route to someone who is not looking at a screen.
         */
        Route::post('devices', [DeviceTokenController::class, 'store'])->name('devices.store');
        Route::delete('devices', [DeviceTokenController::class, 'destroy'])->name('devices.destroy');
        Route::get('favourites', [FavouriteController::class, 'index'])->name('favourites.index');

        Route::prefix('restaurants')->name('restaurants.')->group(function () {
            Route::get('/', [RestaurantController::class, 'index'])->name('index');
            // Before {restaurant}, or the literal path is read as an id.
            Route::get('deals', [RestaurantController::class, 'deals'])->name('deals');
            Route::get('{restaurant}', [RestaurantController::class, 'show'])->name('show');
            Route::get('{restaurant}/menu', [RestaurantController::class, 'menu'])->name('menu');
            // What people actually wrote. Without this the comment box asks a
            // customer to write something nobody will ever read.
            Route::get('{restaurant}/reviews', [RestaurantController::class, 'reviews'])->name('reviews');

            // The bookmark on a restaurant card.
            Route::post('{restaurant}/favourite', [FavouriteController::class, 'store'])->name('favourite.store');
            Route::delete('{restaurant}/favourite', [FavouriteController::class, 'destroy'])->name('favourite.destroy');

            /*
             * One cart per restaurant. Glancing at another shop does not empty
             * the basket you already started.
             */
            Route::prefix('{restaurant}/cart')->name('cart.')->group(function () {
                Route::get('/', [CartController::class, 'show'])->name('show');
                Route::post('items', [CartController::class, 'store'])->name('items.store');
                Route::patch('items/{item}', [CartController::class, 'updateItem'])->name('items.update');
                Route::delete('items/{item}', [CartController::class, 'destroyItem'])->name('items.destroy');
                Route::delete('/', [CartController::class, 'clear'])->name('clear');
                Route::post('checkout', [CartController::class, 'checkout'])->name('checkout');
            });
        });

        Route::get('carts', [CartController::class, 'index'])->name('carts.index');

        /*
        |----------------------------------------------------------------------
        | The customer's own orders — EP5, EP9
        |----------------------------------------------------------------------
        */
        Route::prefix('orders')->name('orders.')->group(function () {
            Route::get('/', [OrderController::class, 'index'])->name('index');
            Route::get('{order}', [OrderController::class, 'show'])->name('show');
            Route::get('{order}/track', [OrderController::class, 'track'])->name('track');
            Route::get('{order}/invoice', [OrderController::class, 'invoice'])->name('invoice');

            /*
             * Payment (EP6). `start` returns what the Razorpay SDK needs;
             * `confirm` reports back what it produced. Neither is the
             * authority — the webhook is — but confirming lets the customer's
             * screen react without waiting for it.
             */
            Route::post('{order}/payment', [PaymentController::class, 'start'])->name('payment.start');
            Route::post('{order}/payment/confirm', [PaymentController::class, 'confirm'])->name('payment.confirm');
            Route::post('{order}/cancel', [OrderController::class, 'cancel'])->name('cancel');

            /*
             * Ratings (EP12). One per order, only after delivery — the order is
             * the proof the person actually ate there.
             */
            Route::get('{order}/review', [ReviewController::class, 'show'])->name('review.show');
            Route::post('{order}/review', [ReviewController::class, 'store'])->name('review.store');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Rider — EP2
    |--------------------------------------------------------------------------
    */
    Route::prefix('rider')->name('rider.')->middleware(['auth:sanctum', 'role:rider'])->group(function () {
        Route::get('profile', [RiderProfileController::class, 'show'])->name('profile.show');
        Route::patch('profile', [RiderProfileController::class, 'update'])->name('profile.update');
        Route::post('duty-status', [RiderProfileController::class, 'setDutyStatus'])->name('duty-status');

        Route::prefix('kyc')->name('kyc.')->group(function () {
            Route::get('/', [RiderKycController::class, 'show'])->name('show');
            Route::patch('details', [RiderKycController::class, 'updateDetails'])->name('details');
            Route::post('documents', [RiderKycController::class, 'upload'])->name('documents.upload');
            Route::delete('documents/{document}', [RiderKycController::class, 'destroy'])->name('documents.destroy');
            Route::post('submit', [RiderKycController::class, 'submit'])->name('submit');
        });

        /*
        |----------------------------------------------------------------------
        | Working a shift — EP8, EP9, EP10
        |----------------------------------------------------------------------
        */
        Route::post('location', [RiderLocationController::class, 'store'])->name('location');

        Route::prefix('orders')->name('orders.')->group(function () {
            // Fixed segment first, or 'available' is read as an order id.
            Route::get('available', [RiderOrderController::class, 'available'])->name('available');
            Route::get('/', [RiderOrderController::class, 'index'])->name('index');
            Route::get('{order}', [RiderOrderController::class, 'show'])->name('show');
            Route::post('{order}/accept', [RiderOrderController::class, 'accept'])->name('accept');
            Route::post('{order}/pickup', [RiderOrderController::class, 'pickup'])->name('pickup');
            Route::post('{order}/release', [RiderOrderController::class, 'release'])->name('release');
            Route::post('{order}/deliver', [RiderOrderController::class, 'deliver'])->name('deliver');
        });
    });

    /*
    |--------------------------------------------------------------------------
    | Admin — EP2 KYC review
    |--------------------------------------------------------------------------
    */
    Route::prefix('admin')->name('admin.')->middleware(['auth:sanctum', 'role:admin'])->group(function () {
        Route::get('kyc/queue', [KycReviewController::class, 'queue'])->name('kyc.queue');
        Route::get('kyc/{type}/{id}/documents', [KycReviewController::class, 'documents'])->name('kyc.documents');
        Route::post('kyc/documents/{document}/review', [KycReviewController::class, 'reviewDocument'])->name('kyc.documents.review');
        Route::post('kyc/{type}/{id}/verify', [KycReviewController::class, 'verify'])->name('kyc.verify');
        Route::post('kyc/{type}/{id}/reject', [KycReviewController::class, 'reject'])->name('kyc.reject');
        Route::post('kyc/{type}/{id}/status', [KycReviewController::class, 'setUserStatus'])->name('kyc.status');
    });

    /*
    |--------------------------------------------------------------------------
    | Merchant Portal — EP2 Auth & Onboarding
    |--------------------------------------------------------------------------
    */
    Route::prefix('merchant')->name('merchant.')->group(function () {
        Route::post('register', [MerchantAuthController::class, 'register'])->name('register');
        Route::post('login', [MerchantAuthController::class, 'login'])->name('login');

        Route::middleware(['auth:sanctum', 'role:merchant'])->group(function () {
            Route::get('me', [MerchantAuthController::class, 'me'])->name('me');
            Route::post('logout', [MerchantAuthController::class, 'logout'])->name('logout');

            Route::get('profile', [MerchantProfileController::class, 'show'])->name('profile.show');
            Route::patch('profile', [MerchantProfileController::class, 'update'])->name('profile.update');
            Route::post('accepting-orders', [MerchantProfileController::class, 'setAcceptingOrders'])->name('accepting-orders');

            /*
            |------------------------------------------------------------------
            | Storefront presentation and opening hours — EP3, feeds EP4
            |------------------------------------------------------------------
            */
            Route::post('storefront/image', [StorefrontController::class, 'uploadImage'])->name('storefront.image.upload');
            Route::delete('storefront/image/{type}', [StorefrontController::class, 'destroyImage'])->name('storefront.image.destroy');
            Route::get('storefront/hours', [StorefrontController::class, 'hours'])->name('storefront.hours.show');
            Route::put('storefront/hours', [StorefrontController::class, 'setHours'])->name('storefront.hours.update');

            Route::prefix('kyc')->name('kyc.')->group(function () {
                Route::get('/', [MerchantKycController::class, 'show'])->name('show');
                Route::post('documents', [MerchantKycController::class, 'upload'])->name('documents.upload');
                Route::delete('documents/{document}', [MerchantKycController::class, 'destroy'])->name('documents.destroy');
                Route::post('submit', [MerchantKycController::class, 'submit'])->name('submit');
            });

            /*
            |------------------------------------------------------------------
            | EP3 — Menu management
            |------------------------------------------------------------------
            | Every route resolves its record through the authenticated
            | merchant, so another merchant's id is a 404, not an edit.
            */
            Route::apiResource('categories', CategoryController::class)
                ->only(['index', 'store', 'update', 'destroy']);

            Route::prefix('menu-items')->name('menu-items.')->group(function () {
                Route::get('/', [MenuItemController::class, 'index'])->name('index');
                Route::post('/', [MenuItemController::class, 'store'])->name('store');
                // Fixed segment first, or 'reorder' is read as an item id.
                Route::post('reorder', [MenuItemController::class, 'reorder'])->name('reorder');
                Route::get('{item}', [MenuItemController::class, 'show'])->name('show');
                Route::post('{item}', [MenuItemController::class, 'update'])->name('update');
                Route::post('{item}/availability', [MenuItemController::class, 'setAvailability'])->name('availability');
                Route::delete('{item}/image', [MenuItemController::class, 'destroyImage'])->name('image.destroy');
                Route::delete('{item}', [MenuItemController::class, 'destroy'])->name('destroy');

                // Customisation: "Spice level", "Add-ons", "Choose your rice".
                Route::get('{item}/option-groups', [OptionGroupController::class, 'index'])->name('option-groups.index');
                Route::post('{item}/option-groups', [OptionGroupController::class, 'store'])->name('option-groups.store');
            });

            Route::patch('option-groups/{group}', [OptionGroupController::class, 'update'])->name('option-groups.update');
            Route::delete('option-groups/{group}', [OptionGroupController::class, 'destroy'])->name('option-groups.destroy');
            Route::post('options/{option}/availability', [OptionGroupController::class, 'setOptionAvailability'])->name('options.availability');

            /*
            |------------------------------------------------------------------
            | EP5/EP8 — Orders
            |------------------------------------------------------------------
            */
            Route::prefix('orders')->name('orders.')->group(function () {
                Route::get('/', [MerchantOrderController::class, 'index'])->name('index');
                Route::get('{order}', [MerchantOrderController::class, 'show'])->name('show');
                Route::post('{order}/accept', [MerchantOrderController::class, 'accept'])->name('accept');
                Route::post('{order}/reject', [MerchantOrderController::class, 'reject'])->name('reject');
                // Cancelling after accepting is a different act from rejecting.
                Route::post('{order}/cancel', [MerchantOrderController::class, 'cancel'])->name('cancel');
                Route::post('{order}/preparing', [MerchantOrderController::class, 'preparing'])->name('preparing');
                Route::post('{order}/ready', [MerchantOrderController::class, 'ready'])->name('ready');
            });
        });
    });
});
