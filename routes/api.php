<?php

use App\Http\Controllers\Api\V1\Merchant\AuthController as MerchantAuthController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {

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
