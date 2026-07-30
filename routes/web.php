<?php

use App\Http\Controllers\Web\MerchantAuthController;
use App\Http\Controllers\Web\PageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public landing — coming-soon page until consumer launch
|--------------------------------------------------------------------------
*/
Route::get('/', [PageController::class, 'home'])->name('home');

/*
|--------------------------------------------------------------------------
| Merchant portal — session auth (the JSON API stays token-based)
|--------------------------------------------------------------------------
*/
Route::prefix('merchant')->name('merchant.')->group(function () {
    Route::middleware('guest')->group(function () {
        Route::get('register', [MerchantAuthController::class, 'showRegister'])->name('register');
        Route::post('register', [MerchantAuthController::class, 'register'])->name('register.submit');
        Route::get('login', [MerchantAuthController::class, 'showLogin'])->name('login');
        Route::post('login', [MerchantAuthController::class, 'login'])->name('login.submit');
    });

    Route::middleware('auth')->group(function () {
        Route::get('dashboard', [MerchantAuthController::class, 'dashboard'])->name('dashboard');
        Route::post('logout', [MerchantAuthController::class, 'logout'])->name('logout');
    });
});
