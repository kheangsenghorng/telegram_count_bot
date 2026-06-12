<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramPayment;
use Illuminate\Http\Request;

class TelegramPaymentWebhookController extends Controller
{
    public function webhook(Request $request)
    {
        $message = $request->input('message');
        $text = $message['text'] ?? null;

        if (!$text) {
            return response()->json(['ok' => true]);
        }

        // Example:
        // ៛100 paid by SENG HORNG KHEANG (*621) on Jun 12, 02:35 PM via ABA KHQR (Bakong) at KHEANG SENG HORNG. Trx. ID: 178124970463496, APV: 153430.

        preg_match('/^(?<currency>៛|\$)?(?<amount>[\d,.]+)\s+paid by\s+(?<payer_name>.*?)\s+\((?<payer_account>.*?)\)\s+on\s+(?<date>.*?)\s+via\s+(?<method>.*?)\s+at\s+(?<merchant>.*?)\.\s+Trx\. ID:\s+(?<trx_id>\d+),\s+APV:\s+(?<apv>\d+)/u', $text, $match);

        if (!$match) {
            return response()->json([
                'ok' => true,
                'message' => 'Message not payment format',
                'text' => $text,
            ]);
        }

        $payment = TelegramPayment::updateOrCreate(
            ['trx_id' => $match['trx_id']],
            [
                'currency' => $match['currency'] ?: 'KHR',
                'amount' => str_replace(',', '', $match['amount']),
                'payer_name' => trim($match['payer_name']),
                'payer_account' => trim($match['payer_account']),
                'merchant_name' => trim($match['merchant']),
                'payment_method' => trim($match['method']),
                'trx_id' => $match['trx_id'],
                'apv' => $match['apv'],
                'raw_message' => $text,
                'status' => 'success',
                'parsed_successfully' => true,
            ]
        );

        return response()->json([
            'ok' => true,
            'message' => 'Payment saved',
            'data' => $payment,
        ]);
    }
}