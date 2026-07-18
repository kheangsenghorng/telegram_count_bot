<?php

use App\Http\Controllers\Api\PackageController;          // ← ADJUST: public read-only controller (rename to PublicPackageController if you split them)
use App\Http\Controllers\Api\SystemStatusController;
use App\Http\Controllers\Api\TelegramBotHeartbeatController;
use App\Http\Controllers\Api\TelegramWebhookController;
use Illuminate\Support\Facades\Route;



Route::prefix('v1/telegram-bot')
    ->group(function (): void {
        Route::post(
            '/heartbeat',
            [
                TelegramBotHeartbeatController::class,
                'store',
            ]
        );

        Route::get(
            '/status',
            [
                TelegramBotHeartbeatController::class,
                'show',
            ]
        );
    });
/*
|--------------------------------------------------------------------------
| Telegram
|--------------------------------------------------------------------------
*/
Route::prefix('telegram')->group(function () {

    // ── Webhook (called by Telegram — must stay public) ──────────────────
    // Protected inside the controller by the X-Telegram-Bot-Api-Secret-Token
    // check + Redis update_id dedup.
    Route::post('/webhook', [TelegramWebhookController::class, 'webhook'])
        ->name('telegram.webhook');

    // ── Utility (open locally, auth-protected in production) ─────────────
    Route::middleware(app()->isProduction() ? ['auth:sanctum'] : [])->group(function () {

        Route::get('/set-webhook', [TelegramWebhookController::class, 'setWebhook'])
            ->name('telegram.set-webhook');

        Route::get('/webhook-info', [TelegramWebhookController::class, 'webhookInfo'])
            ->name('telegram.webhook-info');

        Route::get('/test', [TelegramWebhookController::class, 'testMessage'])
            ->name('telegram.test');
    });
    
    
});
