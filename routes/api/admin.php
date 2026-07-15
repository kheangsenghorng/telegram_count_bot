<?php

declare(strict_types=1);

use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\UserSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware(['auth:api','role:admin','throttle:api',])
    ->group(function (): void {
        Route::apiResource('packages',PackageController::class);

        Route::apiResource('subscriptions',UserSubscriptionController::class)->only([
            'index',
            'show',
            'update',
            'destroy',
        ]);
        
    });