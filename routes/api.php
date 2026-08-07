<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\Admin\KycReviewController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\Merchant\AuthController as MerchantAuthController;
use App\Http\Controllers\Api\V1\Merchant\CategoryController;
use App\Http\Controllers\Api\V1\Merchant\KycController as MerchantKycController;
use App\Http\Controllers\Api\V1\Merchant\MenuItemController;
use App\Http\Controllers\Api\V1\Merchant\OrderController as MerchantOrderController;
use App\Http\Controllers\Api\V1\Merchant\ProfileController as MerchantProfileController;
use App\Http\Controllers\Api\V1\ProfileController;
use App\Http\Controllers\Api\V1\RestaurantController;
use App\Http\Controllers\Api\V1\Rider\KycController as RiderKycController;
use App\Http\Controllers\Api\V1\Rider\ProfileController as RiderProfileController;
use Illuminate\Support\Facades\Route;

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
        Route::prefix('restaurants')->name('restaurants.')->group(function () {
            Route::get('/', [RestaurantController::class, 'index'])->name('index');
            Route::get('{restaurant}', [RestaurantController::class, 'show'])->name('show');
            Route::get('{restaurant}/menu', [RestaurantController::class, 'menu'])->name('menu');
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
            });

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
                Route::post('{order}/preparing', [MerchantOrderController::class, 'preparing'])->name('preparing');
                Route::post('{order}/ready', [MerchantOrderController::class, 'ready'])->name('ready');
            });
        });
    });
});
