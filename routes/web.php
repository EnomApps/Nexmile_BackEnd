<?php

use App\Http\Controllers\Web\Admin\AdminController;
use App\Http\Controllers\Web\LanguageController;
use App\Http\Controllers\Web\MerchantPortalController;
use App\Http\Controllers\Web\PageController;
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

Route::get('language/{locale}', [LanguageController::class, 'switch'])->name('language.switch');
