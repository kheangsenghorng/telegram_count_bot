<?php

use App\Http\Controllers\Api\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:api')->group(function () {
    Route::get('/system/status', [SystemStatusController::class, 'status'])
        ->name('system.status');

    Route::get('/system/status/stream', [SystemStatusController::class, 'stream'])
        ->name('system.status.stream');
});