<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Append Inertia middleware to the 'web' group
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        /*
         * Where an ALREADY-AUTHENTICATED visitor goes when they hit a `guest`
         * route such as the login page.
         *
         * Laravel's default is '/', which used to be the document list and is
         * now the public portfolio. Since the portfolio links to the login URL,
         * the default sent a signed-in visitor who clicked that link straight
         * back to the page they clicked from — a loop with no error anywhere.
         *
         * A closure, not a string: route() is not available this early in the
         * bootstrap, and deriving it from the route name keeps the app prefix
         * defined in exactly one place (config/site.php).
         *
         * Guests need no equivalent setting — Laravel's Authenticate middleware
         * already redirects to the route named `login`, which is this app's.
         */
        $middleware->redirectUsersTo(fn () => route('documents.index'));
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
