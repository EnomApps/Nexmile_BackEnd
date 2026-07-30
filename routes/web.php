<?php

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
