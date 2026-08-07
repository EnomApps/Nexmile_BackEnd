<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates the API documentation behind a single switch.
 *
 * The docs are public so the Flutter and web developers can work against the
 * deployed API. Setting API_DOCS_ENABLED=false hides them with a 404 rather
 * than a 403, so their existence is not advertised.
 */
class EnsureApiDocsEnabled
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless(config('scramble.enabled', true), 404);

        return $next($request);
    }
}
