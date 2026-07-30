<?php

use App\Http\Controllers\Web\PageController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public site
|--------------------------------------------------------------------------
| A single coming-soon page until launch. All application functionality is
| served by the JSON API in routes/api.php.
*/
Route::get('/', [PageController::class, 'home'])->name('home');
