<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Applies the visitor's chosen language for the request.
     *
     * Falls back to the app default when the session holds nothing, or holds
     * a locale that is no longer supported.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        if (is_string($locale) && array_key_exists($locale, config('site.locales'))) {
            App::setLocale($locale);
        }

        return $next($request);
    }
}
