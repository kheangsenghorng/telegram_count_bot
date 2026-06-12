<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\TelegramWebhookController;
use App\Http\Controllers\Api\TelegramPaymentWebhookController;

Route::prefix('telegram')->group(function () {

    Route::post('/webhook', [TelegramWebhookController::class, 'webhook']);

    Route::post('/payment-webhook', [TelegramPaymentWebhookController::class, 'webhook']);

    Route::get('/test', [TelegramWebhookController::class, 'testMessage']);
    Route::get('/set-webhook', [TelegramWebhookController::class, 'setWebhook']);
    Route::get('/webhook-info', [TelegramWebhookController::class, 'webhookInfo']);
});