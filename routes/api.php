<?php

use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\Merchant\AuthController as MerchantAuthController;
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
