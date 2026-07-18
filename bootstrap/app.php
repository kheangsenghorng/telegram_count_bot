<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\RoleMiddleware;
use App\Http\Middleware\UserMiddleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        /*
        |--------------------------------------------------------------------------
        | Broadcasting auth
        |--------------------------------------------------------------------------
        |
        | This app uses JWT (guard: api), NOT Sanctum — same guard as the
        | protected API routes, so the frontend's Bearer JWT works here.
        */
        ['prefix' => '', 'middleware' => ['auth:api']],
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
    ->booted(function (): void {
        /*
        |--------------------------------------------------------------------------
        | Rate limiters
        |--------------------------------------------------------------------------
        |
        | Laravel 11+ no longer defines the "api" limiter automatically.
        | Every route using throttle:api requires this registration.
        */

        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by(
                $request->user()?->getKey() ?: $request->ip()
            );
        });

        /*
        | PayWay callback — per-IP. Generous enough for ABA retries,
        | but stops the endpoint from being hammered (each hit costs
        | an outbound ABA verification call).
        */

        RateLimiter::for('payway-callback', function (Request $request) {
            return Limit::perMinute(30)->by($request->ip());
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })
    ->create();