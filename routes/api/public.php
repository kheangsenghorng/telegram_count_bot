<?php

use Illuminate\Support\Facades\Route;

use App\Http\Controllers\Api\TelegramWebhookController;

Route::prefix('telegram')->group(function () {

    Route::post('/webhook', [TelegramWebhookController::class, 'webhook']);
    
    if (app()->environment('local')) {
        Route::get('/test-connect', [TelegramWebhookController::class, 'testConnect']);
    }
});