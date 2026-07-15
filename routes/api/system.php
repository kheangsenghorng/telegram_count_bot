<?php

use App\Http\Controllers\Api\SystemStatusController;
use Illuminate\Support\Facades\Route;

Route::get('/system/status', [SystemStatusController::class, 'status'])
    ->middleware(app()->isProduction() ? ['auth:sanctum'] : [])
    ->name('system.status');