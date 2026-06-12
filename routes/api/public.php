<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\TelegramPaymentWebhookController;

Route::prefix('telegram')->group(function () {
    Route::post('/webhook', [TelegramWebhookController::class, 'webhook']);

    Route::get('/set-webhook', [TelegramWebhookController::class, 'setWebhook']);
    Route::get('/webhook-info', [TelegramWebhookController::class, 'webhookInfo']);

    Route::prefix('payment')->group(function () {
        Route::post('/webhook', [TelegramPaymentWebhookController::class, 'webhook']);
        Route::get('/set-webhook', [TelegramPaymentWebhookController::class, 'setWebhook']);
        Route::get('/webhook-info', [TelegramPaymentWebhookController::class, 'webhookInfo']);
        Route::get('/send-test-payment', [TelegramPaymentWebhookController::class, 'sendTestPayment']);
    });
});