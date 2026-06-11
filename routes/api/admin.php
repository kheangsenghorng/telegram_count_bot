<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\PackageController;
use App\Http\Controllers\Api\UserSubscriptionController;

Route::middleware(['auth:api','role:admin'])->prefix('admin')->group(function () {

    Route::apiResource('packages',PackageController::class);
    
    Route::apiResource('subscriptions', UserSubscriptionController::class);

});