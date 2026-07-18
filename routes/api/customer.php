<?php

use App\Http\Controllers\Api\Customer\PackageTransaction\PackageTransactionController;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\SubscriptionUsageLogController;
use App\Http\Controllers\Api\UserSubscriptionController;

Route::middleware(['auth:api', 'role:user'])
    ->prefix('customer')
    ->group(function () {

        Route::get('packages', [PackageController::class, 'index']);
        Route::get('packages/{package}', [PackageController::class, 'show']);

        Route::get('subscriptions', [UserSubscriptionController::class, 'index']);
        Route::get('subscriptions/{subscription}', [UserSubscriptionController::class, 'show']);
        Route::post('subscriptions', [UserSubscriptionController::class, 'store']);

        Route::prefix('transactions')
        ->controller(PackageTransactionController::class)
        ->group(function () {
            Route::get('/', 'index');
            Route::get('/{id}', 'show');
        });

        Route::patch(
            'subscriptions/{subscription}/cancel',
            [UserSubscriptionController::class, 'cancel']
        );

        Route::apiResource('subscription-logs', SubscriptionUsageLogController::class)
            ->only(['index', 'show']);
    });