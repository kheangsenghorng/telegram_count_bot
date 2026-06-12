<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramPayment;
use Illuminate\Http\Request;

class TelegramPaymentWebhookController extends Controller
{
    public function webhook(Request $request)
    {
        $data = $request->all();

        return response()->json([
            'success' => true,
            'message' => 'Webhook received',
            'data' => $data,
        ]);
    }
}