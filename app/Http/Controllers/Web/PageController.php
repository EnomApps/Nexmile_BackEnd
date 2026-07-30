<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

/**
 * Static marketing pages. Copy lives in config/site.php so it can be
 * updated without touching the templates.
 */
class PageController extends Controller
{
    public function show(string $page): View
    {
        return view("pages.{$page}");
    }
}
