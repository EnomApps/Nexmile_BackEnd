<?php

use App\Http\Controllers\Web\Admin\AdminController;
use App\Http\Controllers\Web\Admin\AdminOrderController;
use App\Http\Controllers\Web\Admin\DashboardController;
use App\Http\Controllers\Web\Admin\MerchandisingController;
use App\Http\Controllers\Web\Admin\ReviewModerationController;
use App\Http\Controllers\Web\LanguageController;
use App\Http\Controllers\Web\MerchantEarningsController;
use App\Http\Controllers\Web\MerchantMenuController;
use App\Http\Controllers\Web\MerchantOptionController;
use App\Http\Controllers\Web\MerchantOrderController;
use App\Http\Controllers\Web\MerchantPortalController;
use App\Http\Controllers\Web\MerchantProfileController;
use App\Http\Controllers\Web\MerchantReviewController;
use App\Http\Controllers\Web\MerchantStorefrontController;
use App\Http\Controllers\Web\MerchantSurplusController;
use App\Http\Controllers\Web\PageController;
use App\Http\Controllers\Web\PostmanController;
use App\Http\Middleware\EnsureApiDocsEnabled;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Marketing site
|--------------------------------------------------------------------------
| Static pages only. All application functionality is served by the JSON
| API in routes/api.php.
*/

Route::get('/', fn () => view('pages.home'))->name('home');

foreach ([
    'about',
    'services',
    'food-rescue',
    'merchants',
    'delivery-partners',
    'technology',
    'investors',
    'contact',
] as $page) {
    Route::get($page, [PageController::class, 'show'])
        ->defaults('page', $page)
        ->name($page);
}

/*
 * Terms, privacy and refunds. Razorpay will not activate live payments until
 * all three are publicly reachable, and a customer is entitled to read them
 * before they order.
 */
foreach (['terms', 'privacy', 'refunds'] as $document) {
    Route::get($document, [PageController::class, 'legal'])
        ->defaults('document', $document)
        ->name($document);
}

/*
|--------------------------------------------------------------------------
| Merchant portal — registration and onboarding happen here, on nexmile.in
|--------------------------------------------------------------------------
| Session-based, sharing the users table with the JSON API.
*/
Route::prefix('merchants')->name('merchants.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('register', [MerchantPortalController::class, 'showRegister'])->name('register');
        Route::post('register', [MerchantPortalController::class, 'register'])->name('register.submit');
        Route::get('login', [MerchantPortalController::class, 'showLogin'])->name('login');
        Route::post('login', [MerchantPortalController::class, 'login'])->name('login.submit');
    });

    Route::middleware(['auth', 'role:merchant'])->group(function () {
        Route::get('dashboard', [MerchantPortalController::class, 'dashboard'])->name('dashboard');
        Route::post('dashboard/documents', [MerchantPortalController::class, 'uploadDocument'])->name('documents.upload');
        Route::delete('dashboard/documents/{document}', [MerchantPortalController::class, 'destroyDocument'])->name('documents.destroy');
        Route::post('dashboard/submit', [MerchantPortalController::class, 'submitKyc'])->name('kyc.submit');
        Route::post('logout', [MerchantPortalController::class, 'logout'])->name('logout');

        // The most-used control a merchant has: stop the queue when swamped.
        Route::post('accepting-orders', [MerchantPortalController::class, 'setAcceptingOrders'])
            ->name('accepting-orders');

        Route::get('earnings', [MerchantEarningsController::class, 'index'])->name('earnings');

        // A score a merchant cannot explain is a score they cannot act on.
        Route::get('reviews', [MerchantReviewController::class, 'index'])->name('reviews');

        Route::get('profile', [MerchantProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('profile', [MerchantProfileController::class, 'update'])->name('profile.update');

        /*
         * Menu (EP3). A merchant may build their menu before verification —
         * it only becomes visible to customers once the account is verified,
         * so there is nothing to gain by making them wait.
         */
        Route::prefix('menu')->name('menu.')->group(function () {
            Route::get('/', [MerchantMenuController::class, 'index'])->name('index');

            Route::post('categories', [MerchantMenuController::class, 'storeCategory'])->name('categories.store');
            Route::delete('categories/{category}', [MerchantMenuController::class, 'destroyCategory'])->name('categories.destroy');
            // Category 0 is the uncategorised group, which is still part of the menu.
            Route::post('categories/{category}/availability', [MerchantMenuController::class, 'toggleCategory'])
                ->whereNumber('category')->name('categories.availability');

            // A photo per category. Added after the fact, because a merchant
            // builds the menu first and photographs it later.
            Route::post('categories/{category}/image', [MerchantMenuController::class, 'uploadCategoryImage'])
                ->whereNumber('category')->name('categories.image.upload');
            Route::delete('categories/{category}/image', [MerchantMenuController::class, 'destroyCategoryImage'])
                ->whereNumber('category')->name('categories.image.destroy');

            // 'create' before '{item}' so the literal path is not swallowed.
            Route::get('items/create', [MerchantMenuController::class, 'createItem'])->name('items.create');
            Route::post('items', [MerchantMenuController::class, 'storeItem'])->name('items.store');
            Route::get('items/{item}/edit', [MerchantMenuController::class, 'editItem'])->name('items.edit');
            Route::patch('items/{item}', [MerchantMenuController::class, 'updateItem'])->name('items.update');
            Route::post('items/{item}/toggle', [MerchantMenuController::class, 'toggleItem'])->name('items.toggle');
            Route::delete('items/{item}', [MerchantMenuController::class, 'destroyItem'])->name('items.destroy');

            // Customisation: "Spice level", "Add-ons", "Choose your rice".
            Route::get('items/{item}/options', [MerchantOptionController::class, 'index'])->name('items.options.index');
            Route::post('items/{item}/options', [MerchantOptionController::class, 'store'])->name('items.options.store');
            Route::patch('option-groups/{group}', [MerchantOptionController::class, 'update'])->name('options.update');
            Route::delete('option-groups/{group}', [MerchantOptionController::class, 'destroy'])->name('options.destroy');
        });

        /*
         * Storefront presentation and opening hours (EP3, feeds EP4). Both
         * decide what a customer sees: the logo is the first thing on the home
         * screen, and the hours decide whether the shop appears open at all.
         */
        Route::prefix('storefront')->name('storefront.')->group(function () {
            Route::get('/', [MerchantStorefrontController::class, 'edit'])->name('edit');
            Route::post('image', [MerchantStorefrontController::class, 'uploadImage'])->name('image.upload');
            Route::delete('image/{type}', [MerchantStorefrontController::class, 'destroyImage'])->name('image.destroy');
            Route::post('hours', [MerchantStorefrontController::class, 'saveHours'])->name('hours');

            /*
             * The storefront carousel. One banner heads a page; it does not
             * sell a place a customer has never visited.
             */
            Route::post('photos', [MerchantStorefrontController::class, 'uploadPhoto'])->name('photos.store');
            Route::post('photos/{photo}/move', [MerchantStorefrontController::class, 'movePhoto'])->name('photos.move');
            Route::delete('photos/{photo}', [MerchantStorefrontController::class, 'destroyPhoto'])->name('photos.destroy');
            // Cuisine, price bracket and pure-veg: without them a restaurant is
            // invisible to the cuisine rail, the VEG toggle and the price filters.
            Route::post('listing', [MerchantStorefrontController::class, 'saveListing'])->name('listing');
        });

        /*
         * Orders (EP5, EP8).
         */
        /*
         * Food Rescue (EP14). Its own page: a merchant reaches for this at the
         * end of service with a specific question — what is left and what can
         * I shift — which is a different task from editing a menu.
         */
        Route::prefix('food-rescue')->name('surplus.')->group(function () {
            Route::get('/', [MerchantSurplusController::class, 'index'])->name('index');
            Route::post('{item}', [MerchantSurplusController::class, 'store'])->name('store');
            Route::delete('{item}', [MerchantSurplusController::class, 'destroy'])->name('destroy');
        });

        Route::prefix('orders')->name('orders.')->whereNumber('order')->group(function () {
            Route::get('/', [MerchantOrderController::class, 'index'])->name('index');
            // Polled by the queue, before {order} so the literal path stands.
            Route::get('queue-status', [MerchantOrderController::class, 'queueStatus'])->name('queue-status');
            // Before {order} so the literal path is not read as an id.
            Route::get('{order}/invoice', [MerchantOrderController::class, 'invoice'])->name('invoice');
            // Polled by the detail page: everything after "ready" is done by a
            // rider, so without it the merchant sees nothing until they reload.
            Route::get('{order}/status', [MerchantOrderController::class, 'status'])->name('status');
            Route::get('{order}', [MerchantOrderController::class, 'show'])->name('show');
            Route::post('{order}/accept', [MerchantOrderController::class, 'accept'])->name('accept');
            Route::post('{order}/reject', [MerchantOrderController::class, 'reject'])->name('reject');
            Route::post('{order}/cancel', [MerchantOrderController::class, 'cancel'])->name('cancel');
            Route::post('{order}/preparing', [MerchantOrderController::class, 'preparing'])->name('preparing');
            Route::post('{order}/ready', [MerchantOrderController::class, 'ready'])->name('ready');
        });
    });
});

/*
|--------------------------------------------------------------------------
| Admin — KYC review
|--------------------------------------------------------------------------
| Not linked from the public navigation. Accounts are created with
| `php artisan nexmile:make-admin`, never by signing up.
*/
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('login', [AdminController::class, 'showLogin'])->name('login');
        Route::post('login', [AdminController::class, 'login'])->name('login.submit');
    });

    Route::middleware(['auth', 'role:admin'])->group(function () {
        Route::get('/', [AdminController::class, 'index'])->name('index');
        Route::post('logout', [AdminController::class, 'logout'])->name('logout');

        /*
         * Order visibility for support. Registered before the {type}/{id}
         * catch-all, or 'orders' is read as an account type.
         */
        Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

        /*
         * Review moderation. Without it the only options for an abusive
         * comment are leaving it up or reaching into the database.
         */
        Route::get('reviews', [ReviewModerationController::class, 'index'])->name('reviews.index');
        Route::post('reviews/{review}/hide', [ReviewModerationController::class, 'hide'])->name('reviews.hide');
        Route::post('reviews/{review}/unhide', [ReviewModerationController::class, 'unhide'])->name('reviews.unhide');

        /*
         * Home screen merchandising. The banners, cuisines and collections
         * tables exist so the customer home screen can change without an app
         * release; without this screen that only moved the release from the
         * Play Store to a database client, which needs an engineer at night
         * with production credentials.
         */
        Route::prefix('home-screen')->name('merchandising.')->group(function () {
            Route::get('/', [MerchandisingController::class, 'index'])->name('index');

            Route::post('banners', [MerchandisingController::class, 'storeBanner'])->name('banners.store');
            Route::post('banners/{banner}/toggle', [MerchandisingController::class, 'toggleBanner'])->name('banners.toggle');
            Route::delete('banners/{banner}', [MerchandisingController::class, 'destroyBanner'])->name('banners.destroy');

            Route::post('cuisines', [MerchandisingController::class, 'storeCuisine'])->name('cuisines.store');
            Route::delete('cuisines/{cuisine}', [MerchandisingController::class, 'destroyCuisine'])->name('cuisines.destroy');
            // Icons are optional at creation, so they have to be addable later —
            // otherwise the only way to get one is deleting the cuisine, which
            // orphans every restaurant filed under that slug.
            Route::post('cuisines/{cuisine}/image', [MerchandisingController::class, 'uploadCuisineImage'])->name('cuisines.image.upload');
            Route::delete('cuisines/{cuisine}/image', [MerchandisingController::class, 'destroyCuisineImage'])->name('cuisines.image.destroy');

            Route::post('collections', [MerchandisingController::class, 'storeCollection'])->name('collections.store');
            Route::post('collections/{collection}/merchants', [MerchandisingController::class, 'updateCollectionMerchants'])->name('collections.merchants');
            Route::post('collections/{collection}/toggle', [MerchandisingController::class, 'toggleCollection'])->name('collections.toggle');
            Route::post('collections/{collection}/image', [MerchandisingController::class, 'uploadCollectionImage'])->name('collections.image.upload');
            Route::delete('collections/{collection}', [MerchandisingController::class, 'destroyCollection'])->name('collections.destroy');
        });

        // Commercial terms and the food licence: admin-only, never on the
        // merchant's own profile. A merchant who could type their own FSSAI
        // number would make verification pointless.
        Route::post('merchants/{id}/terms', [AdminController::class, 'updateTerms'])->name('merchants.terms');
        Route::post('merchants/{id}/compliance', [AdminController::class, 'updateCompliance'])->name('merchants.compliance');

        Route::prefix('orders')->name('orders.')->whereNumber('order')->group(function () {
            Route::get('/', [AdminOrderController::class, 'index'])->name('index');
            Route::get('{order}', [AdminOrderController::class, 'show'])->name('show');
            Route::post('{order}/cancel', [AdminOrderController::class, 'cancel'])->name('cancel');
        });

        Route::get('{type}/{id}', [AdminController::class, 'show'])
            ->whereIn('type', ['merchants', 'riders'])->name('show');
        Route::post('{type}/{id}/documents/{document}', [AdminController::class, 'reviewDocument'])
            ->whereIn('type', ['merchants', 'riders'])->name('documents.review');
        Route::post('{type}/{id}/verify', [AdminController::class, 'verify'])
            ->whereIn('type', ['merchants', 'riders'])->name('verify');
        Route::post('{type}/{id}/reject', [AdminController::class, 'reject'])
            ->whereIn('type', ['merchants', 'riders'])->name('reject');
        Route::post('{type}/{id}/status', [AdminController::class, 'setStatus'])
            ->whereIn('type', ['merchants', 'riders'])->name('status');
    });
});

/*
|--------------------------------------------------------------------------
| Postman collections
|--------------------------------------------------------------------------
| They live outside the web root, so without this there is no URL for an app
| developer who does not have repo access. Behind the same switch as the API
| docs, and allowlisted by name — serving the docs directory would also serve
| DEPLOYMENT.md and MAPS.md.
*/
Route::middleware(EnsureApiDocsEnabled::class)->group(function () {
    Route::get('docs/postman', [PostmanController::class, 'index'])->name('postman.index');
    Route::get('docs/postman/{app}', [PostmanController::class, 'download'])
        ->whereIn('app', ['customer', 'rider', 'merchant'])
        ->name('postman.download');
});

/*
|--------------------------------------------------------------------------
| Per-app OpenAPI documents
|--------------------------------------------------------------------------
| /docs/api stays as the complete reference. These are the same endpoints
| split three ways, because an app developer reading one 94-endpoint document
| has to work out which half belongs to them — and the answer is not obvious,
| since /auth and /profile are shared and /orders means something different to
| a customer and a rider.
|
| Registered here rather than in a provider: Scramble's own provider boots
| first, so registerApi() alone produces a document with no route to reach it.
*/
foreach (['customer', 'rider', 'merchant'] as $api) {
    Scramble::registerUiRoute("docs/{$api}", api: $api)
        ->middleware(EnsureApiDocsEnabled::class)
        ->name("docs.{$api}");

    Scramble::registerJsonSpecificationRoute("docs/{$api}.json", api: $api)
        ->middleware(EnsureApiDocsEnabled::class)
        ->name("docs.{$api}.json");
}

Route::get('language/{locale}', [LanguageController::class, 'switch'])->name('language.switch');
