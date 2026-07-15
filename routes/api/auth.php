<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\TelegramAuthController;

Route::prefix('auth')
    ->name('auth.')
    ->group(function (): void {
        Route::post('register', [AuthController::class, 'register'])
            ->name('register');

        Route::post('login', [AuthController::class, 'login'])
            ->name('login');

        Route::post('telegram/login', [TelegramAuthController::class, 'login'])
            ->name('telegram.login');

        Route::middleware('auth:api')->group(function (): void {
            Route::get('me', [AuthController::class, 'me'])
                ->name('me');

            Route::post('refresh', [AuthController::class, 'refresh'])
                ->name('refresh');

            Route::post('logout', [AuthController::class, 'logout'])
                ->name('logout');
        });
    });