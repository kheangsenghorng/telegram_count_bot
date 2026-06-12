<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use Carbon\Carbon;
use Illuminate\Http\Request;

class TelegramPaymentWebhookController extends Controller
{
    public function webhook(Request $request)
    {
        $message = $request->input('message');

        if (!$message) {
            return response()->json(['ok' => true]);
        }

        $chat = $message['chat'] ?? [];
        $chatId = (string) ($chat['id'] ?? '');
        $text = trim($message['text'] ?? '');

        if (!$text) {
            return response()->json(['ok' => true]);
        }

        $telegramGroup = TelegramGroup::where('group_id', $chatId)
            ->where('status', 'connected')
            ->first();

        if (!$telegramGroup) {
            return response()->json([
                'ok' => true,
                'message' => 'Group not connected',
            ]);
        }

        preg_match_all(
            '/៛([\d,]+)\s+paid by\s+(.*?)\s+\(\*(\d+)\)\s+on\s+(.*?),\s+via\s+(.*?)\s+at\s+(.*?)\.\s+Trx\. ID:\s*(\d+),\s*APV:\s*(\d+)/i',
            $text,
            $matches,
            PREG_SET_ORDER
        );

        $saved = 0;
        $duplicates = 0;

        foreach ($matches as $payment) {
            $amount = (float) str_replace(',', '', $payment[1]);
            $payerName = trim($payment[2]);
            $payerAccount = '*' . trim($payment[3]);
            $dateText = trim($payment[4]);
            $paymentMethod = trim($payment[5]);
            $merchantName = trim($payment[6]);
            $trxId = trim($payment[7]);
            $apv = trim($payment[8]);

            $exists = TelegramPayment::where('trx_id', $trxId)->first();

            if ($exists) {
                $duplicates++;
                continue;
            }

            TelegramPayment::create([
                'user_id' => $telegramGroup->user_id,
                'subscription_id' => $telegramGroup->subscription_id,
                'telegram_group_id' => $telegramGroup->telegramGroupsID,

                'currency' => 'KHR',
                'amount' => $amount,

                'payer_name' => $payerName,
                'payer_account' => $payerAccount,
                'merchant_name' => $merchantName,

                'payment_method' => $paymentMethod,
                'bank_code' => 'ABA',

                'trx_id' => $trxId,
                'apv' => $apv,

                'payment_date' => $this->parseAbaDate($dateText),
                'report_date' => now()->toDateString(),
                'report_month' => now()->month,
                'report_year' => now()->year,

                'raw_message' => $text,
                'parsed_successfully' => true,
                'is_duplicate' => false,
                'status' => 'success',
            ]);

            $saved++;
        }

        return response()->json([
            'ok' => true,
            'saved' => $saved,
            'duplicates' => $duplicates,
        ]);
    }

    private function parseAbaDate(string $dateText)
    {
        try {
            return Carbon::parse($dateText . ' ' . now()->year);
        } catch (\Throwable $e) {
            return now();
        }
    }
}