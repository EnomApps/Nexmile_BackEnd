<?php

use App\Http\Controllers\Api\V1\AddressController;
use App\Http\Controllers\Api\V1\Auth\OtpAuthController;
use App\Http\Controllers\Api\V1\Merchant\AuthController as MerchantAuthController;
use App\Http\Controllers\Api\V1\Merchant\ProfileController as MerchantProfileController;
use App\Http\Controllers\Api\V1\ProfileController;
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
        });
    });
});
