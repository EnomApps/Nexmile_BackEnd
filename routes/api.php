<?php

use App\Http\Controllers\Api\V1\Merchant\AuthController as MerchantAuthController;
use Illuminate\Support\Facades\Route;

// Names are prefixed with `api.` so they never collide with the Blade portal
// routes in web.php, which use the bare `merchant.*` names.
Route::prefix('v1')->name('api.v1.')->group(function () {

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
        });
    });
});
