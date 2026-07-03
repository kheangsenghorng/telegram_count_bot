<?php

use App\Http\Controllers\Api\V1\KhqrController;
use App\Http\Controllers\Api\PaymentCheckoutApiController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1/khqr')->group(function () {
    Route::post('/merchant', [KhqrController::class, 'generateMerchant']);
    Route::post('/individual', [KhqrController::class, 'generateIndividual']);
    Route::post('/generate-image', [KhqrController::class, 'generateImage']);
    Route::post('/deeplink', [KhqrController::class, 'generateDeeplink']);

    Route::post('/check-transaction-by-md5', [KhqrController::class, 'checkTransactionByMd5']);
    Route::post('/check-transaction-by-hash', [KhqrController::class, 'checkTransactionByHash']);
    Route::post('/check-bakong-account', [KhqrController::class, 'checkBakongAccount']);
    Route::post('/check-transaction-by-external-ref', [KhqrController::class, 'checkTransactionByExternalRef']);


    Route::get('/payment-checkout/{transactionId}', [PaymentCheckoutApiController::class, 'show']);
    Route::post('/payment-checkout/{transactionId}/check', [PaymentCheckoutApiController::class, 'check']);
});