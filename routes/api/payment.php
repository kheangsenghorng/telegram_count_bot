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
        | KHQR checkout
        |--------------------------------------------------------------------------
        */

        Route::prefix('khqr')
            ->name('khqr.')
            ->controller(PaymentCheckoutApiController::class)
            ->group(function (): void {
                Route::get(
                    '/payment-checkouts/{transactionId}',
                    'show'
                )->name('payment-checkouts.show');

                Route::post(
                    '/payment-checkouts/{transactionId}/check',
                    'check'
                )
                    ->middleware('throttle:30,1')
                    ->name('payment-checkouts.check');
            });

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

