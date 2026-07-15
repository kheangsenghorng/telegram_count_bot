<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\UserMiddleware;
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
        /*
        |--------------------------------------------------------------------------
        | Middleware aliases
        |--------------------------------------------------------------------------
        */

        $middleware->alias([
            'admin' => AdminMiddleware::class,
            'user' => UserMiddleware::class,
            'role' => RoleMiddleware::class,
        ]);

        /*
        |--------------------------------------------------------------------------
        | Trusted proxies
        |--------------------------------------------------------------------------
        |
        | Allows Laravel to detect HTTPS correctly when using ngrok, Nginx,
        | Cloudflare, or another reverse proxy.
        */

        $middleware->trustProxies(at: '*');

        /*
        |--------------------------------------------------------------------------
        | CSRF exceptions
        |--------------------------------------------------------------------------
        |
        | PayWay is an external service and cannot send Laravel's CSRF token.
        */

        $middleware->validateCsrfTokens(except: [
            'payway/payment-link/callback',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();