<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LanguageController extends Controller
{
    /**
     * Store the chosen language and return the visitor to the page they were on.
     */
    public function switch(Request $request, string $locale): RedirectResponse
    {
        abort_unless(array_key_exists($locale, config('site.locales')), 404);

        $request->session()->put('locale', $locale);

        return back();
    }
}
