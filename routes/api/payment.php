<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PaymentCheckoutApiController;
use App\Http\Controllers\Api\PaymentLinkController;
use App\Http\Controllers\Api\PayWayCallbackController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')
    ->name('api.v1.')
    ->group(function (): void {
        /*
        |--------------------------------------------------------------------------
        | ABA PayWay
        |--------------------------------------------------------------------------
        */

        Route::prefix('payway')
            ->name('payway.')
            ->group(function (): void {
                /*
                |--------------------------------------------------------------------------
                | Payment-link API
                |--------------------------------------------------------------------------
                */

                Route::controller(PaymentLinkController::class)
                    ->group(function (): void {
                        Route::post(
                            '/payment-links',
                            'store'
                        )->name('payment-links.store');

                        Route::post(
                            '/payment-links/detail',
                            'details'
                        )
                            ->middleware('throttle:60,1')
                            ->name('payment-links.details');

                        Route::post(
                            '/transactions/check',
                            'checkTransaction'
                        )
                            ->middleware('throttle:60,1')
                            ->name('transactions.check');
                    });

                /*
                |--------------------------------------------------------------------------
                | PayWay callback
                |--------------------------------------------------------------------------
                |
                | ABA PayWay sends a server-to-server POST request here.
                | This endpoint must return JSON and must not redirect.
                |
                | Do not place authentication middleware on this route because
                | the request comes directly from ABA PayWay.
                |
                */

                Route::post(
                    '/payment-link/callback',
                    [PayWayCallbackController::class, 'handle']
                )
                    ->middleware('throttle:120,1')
                    ->name('payment-link.callback');
            });
    });

