<?php

use App\Enums\UserRole;
use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
        ]);

        /*
         * There is no generic /login. Send guests to the sign-in page for the
         * area they were trying to reach, so an admin is not bounced to the
         * merchant form and vice versa.
         */
        $middleware->redirectGuestsTo(fn ($request) => $request->is('admin', 'admin/*')
            ? route('admin.login')
            : route('merchants.login'));

        $middleware->redirectUsersTo(fn ($request) => $request->user()?->role === UserRole::Admin
            ? route('admin.index')
            : route('merchants.dashboard'));

        // Runs after the session starts so the chosen locale is available.
        $middleware->web(append: [
            SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         * A stale CSRF token is a dead end, and it should be a retry.
         *
         * The default is a bare "419 PAGE EXPIRED" on a blank page, with no
         * explanation and nowhere to go. It happens for an ordinary reason —
         * a sign-in page left open past the session lifetime, which is exactly
         * what a merchant's browser does overnight — and the fix is always the
         * same: load the form again. Doing that for them removes the whole
         * class of support call.
         *
         * Deliberately not an exemption from CSRF. The check still runs and
         * still refuses; only the refusal is made survivable.
         */
        /*
         * Caught as an HttpException, not as TokenMismatchException: the
         * framework converts one to the other in prepareException(), which
         * runs before render callbacks are consulted. A handler typed on the
         * original class is never reached, and fails silently in exactly the
         * way that looks like it works.
         */
        $exceptions->render(function (HttpException $e, $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Your session expired. Sign in again.',
                ], 419);
            }

            return redirect()
                ->to($request->is('admin*') ? route('admin.login') : route('merchants.login'))
                ->withInput($request->except(['password', '_token']))
                ->with('status', 'That page had been open a while, so we could not verify it was you. Please sign in again.');
        });
    })->create();
