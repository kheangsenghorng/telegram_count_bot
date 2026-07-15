<?php

declare(strict_types=1);

use App\Http\Controllers\PayWayPaymentPageController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::prefix('payway')
    ->name('payway.')
    ->controller(PayWayPaymentPageController::class)
    ->group(function (): void {
        Route::get(
            '/payments/{merchantReference}',
            'show'
        )->name('payments.show');

        Route::get(
            '/payments/{merchantReference}/status',
            'status'
        )
            ->middleware('throttle:60,1')
            ->name('payments.status');

        Route::get(
            '/payment-result/{merchantReference}',
            'success'
        )->name('payment-result');

        Route::get(
            '/payment-cancelled/{merchantReference}',
            'cancelled'
        )->name('payment-cancelled');
    });