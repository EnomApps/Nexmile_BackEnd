<?php

namespace App\Http\Middleware;

use App\Enums\UserStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role->value, $roles, true)) {
            return $this->deny($request, 'This account is not allowed to access this resource.');
        }

        if ($user->status === UserStatus::Suspended) {
            return $this->deny($request, 'This account has been suspended. Contact support.');
        }

        return $next($request);
    }

    /**
     * API callers get JSON; a browser gets the standard 403 page rather than a
     * wall of JSON it cannot render.
     */
    private function deny(Request $request, string $message): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message], 403);
        }

        abort(403, $message);
    }
}
