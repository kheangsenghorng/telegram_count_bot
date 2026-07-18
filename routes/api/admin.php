<?php

declare(strict_types=1);

use App\Http\Controllers\Api\Admin\Dashboard\DashboardStatsController;
use App\Http\Controllers\Api\Admin\PackageTransaction\PackageTransactionController;
use App\Http\Controllers\Api\Admin\RevenueOverview\RevenueOverviewController;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\UserSubscriptionController;
use Illuminate\Support\Facades\Route;

Route::prefix('admin')->name('admin.')->middleware(['auth:api','role:admin','throttle:api',])
    ->group(function (): void {


        Route::get('/revenue/overview', [RevenueOverviewController::class, 'overview']);
        

        Route::get('/dashboard/stats', [DashboardStatsController::class, 'stats']);

        Route::apiResource('packages',PackageController::class);

        Route::apiResource('subscriptions',UserSubscriptionController::class)->only([
            'index',
            'show',
            'update',
            'destroy',
        ]);

        // ────────────────────────────────────────────────────────────────────
        // Add to routes/api/admin.php
        // (inside your existing admin middleware group — auth:sanctum + admin)
        // ───

        Route::prefix('transactions')->group(function () {
            Route::get('/', [PackageTransactionController::class, 'index']);
            Route::get('/stats', [PackageTransactionController::class, 'stats']);
            Route::get('/{id}', [PackageTransactionController::class, 'show']);
            Route::patch('/expire',[PackageTransactionController::class, 'expire']);  
        });
        

    });