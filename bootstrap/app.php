<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         * Telegram posts to this route from its own servers. There is no session
         * and therefore no CSRF token to send, so the check has to be lifted here
         * or every delivery would be rejected with a 419.
         *
         * What replaces it is not nothing: the controller compares a secret header
         * with hash_equals before it looks at the body. Keep this list to that one
         * route — a path added here is a path with no CSRF protection at all.
         */
        $middleware->validateCsrfTokens(except: [
            'telegram/webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
