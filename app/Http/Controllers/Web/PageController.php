<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PageController extends Controller
{
    /**
     * Public landing page. Replaced with the full marketing site
     * (home / about / contact) closer to consumer launch.
     */
    public function home(): View
    {
        return view('pages.coming-soon');
    }
}
