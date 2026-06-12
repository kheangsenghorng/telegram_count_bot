<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\SubscriptionUsageLogController;
use App\Http\Controllers\Api\TelegramPaymentController;
use App\Http\Controllers\Api\TelegramPaymentWebhookController;
use App\Http\Controllers\Api\UserSubscriptionController;

/*
|--------------------------------------------------------------------------
| Public Webhook
|--------------------------------------------------------------------------
*/

Route::post('telegram-payment/webhook', [TelegramPaymentWebhookController::class, 'webhook']);

/*
|--------------------------------------------------------------------------
| Customer Routes
|--------------------------------------------------------------------------
*/

Route::middleware(['auth:api', 'role:user'])
    ->prefix('customer')
    ->group(function () {

        Route::get('packages', [PackageController::class, 'index']);
        Route::get('packages/{package}', [PackageController::class, 'show']);

        Route::get('subscriptions', [UserSubscriptionController::class, 'index']);
        Route::get('subscriptions/{subscription}', [UserSubscriptionController::class, 'show']);
        Route::post('subscriptions', [UserSubscriptionController::class, 'store']);

        Route::patch(
            'subscriptions/{subscription}/cancel',
            [UserSubscriptionController::class, 'cancel']
        );

        Route::apiResource(
            'subscription-logs',
            SubscriptionUsageLogController::class
        )->only(['index', 'show']);

        Route::apiResource(
            'telegram-payments',
            TelegramPaymentController::class
        );
    });