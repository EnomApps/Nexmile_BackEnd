<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => \App\Http\Middleware\EnsureUserHasRole::class,
        ]);

        /*
         * There is no generic /login. Send guests to the sign-in page for the
         * area they were trying to reach, so an admin is not bounced to the
         * merchant form and vice versa.
         */
        $middleware->redirectGuestsTo(fn ($request) => $request->is("admin", "admin/*")
            ? route("admin.login")
            : route("merchants.login"));

        $middleware->redirectUsersTo(fn ($request) => $request->user()?->role === \App\Enums\UserRole::Admin
            ? route("admin.index")
            : route("merchants.dashboard"));

        // Runs after the session starts so the chosen locale is available.
        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
