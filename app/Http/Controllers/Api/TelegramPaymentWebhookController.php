<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramGroup;
use App\Models\TelegramPayment;
use App\Models\User;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelegramPaymentWebhookController extends Controller
{
    public function webhook(Request $request)
    {
        try {
            $text = $request->input('message.text');

            if (!$text) {
                return response()->json(['ok' => true]);
            }

            $telegramUserId = (string) $request->input('message.from.id');
            $telegramChatId = (string) $request->input('message.chat.id');

            $user = User::where('telegram_id', $telegramUserId)->first();

            if (!$user) {
                return response()->json([
                    'ok' => false,
                    'message' => 'User not found by telegram_id',
                    'telegram_user_id' => $telegramUserId,
                ], 404);
            }

            $userId = $user->uuid;

            $subscription = UserSubscription::where('user_id', $userId)
                ->where('status', 'active')
                ->latest()
                ->first();

            if (!$subscription) {
                return response()->json([
                    'ok' => false,
                    'message' => 'Active subscription not found for this user',
                    'user_id' => $userId,
                    'telegram_user_id' => $telegramUserId,
                ], 404);
            }

            $subscriptionId = $subscription->userSubscriptionsID;

            $group = TelegramGroup::where('user_id', $userId)
                ->where('subscription_id', $subscriptionId)
                ->where('status', 'connected')
                ->latest()
                ->first();

            $telegramGroupId = $group?->telegramGroupsID;

            preg_match(
                '/^(?<currency>៛|\$)?(?<amount>[\d,.]+)\s+paid by\s+(?<payer_name>.*?)\s+\((?<payer_account>.*?)\)\s+on\s+(?<date>.*?)\s+via\s+(?<method>.*?)\s+at\s+(?<merchant>.*?)\.\s+Trx\. ID:\s+(?<trx_id>\d+),\s+APV:\s+(?<apv>\d+)/u',
                $text,
                $match
            );

            if (!$match) {
                $payment = TelegramPayment::create([
                    'user_id' => $userId,
                    'telegram_group_id' => $telegramGroupId,
                    'subscription_id' => $subscriptionId,
                    'raw_message' => $text,
                    'status' => 'pending',
                    'parsed_successfully' => false,
                    'is_duplicate' => false,
                ]);

                return response()->json([
                    'ok' => true,
                    'message' => 'Text saved but not parsed',
                    'telegram_user_id' => $telegramUserId,
                    'telegram_chat_id' => $telegramChatId,
                    'user_id' => $userId,
                    'subscription_id' => $subscriptionId,
                    'telegram_group_id' => $telegramGroupId,
                    'found_group' => (bool) $group,
                    'data' => $payment,
                ]);
            }

            try {
                $paymentDate = Carbon::createFromFormat('M d, h:i A', trim($match['date']))
                    ->year(now()->year);
            } catch (\Throwable $e) {
                $paymentDate = now();
            }

            $trxId = trim($match['trx_id']);
            $existingPayment = TelegramPayment::where('trx_id', $trxId)->first();

            $payment = TelegramPayment::updateOrCreate(
                ['trx_id' => $trxId],
                [
                    'user_id' => $userId,
                    'telegram_group_id' => $telegramGroupId,
                    'subscription_id' => $subscriptionId,

                    'currency' => $match['currency'] === '៛' ? 'KHR' : 'USD',
                    'amount' => str_replace(',', '', $match['amount']),
                    'payer_name' => trim($match['payer_name']),
                    'payer_account' => trim($match['payer_account']),
                    'merchant_name' => trim($match['merchant']),
                    'payment_method' => trim($match['method']),
                    'bank_code' => 'ABA',
                    'apv' => trim($match['apv']),

                    'payment_date' => $paymentDate,
                    'report_date' => $paymentDate->toDateString(),
                    'report_month' => $paymentDate->month,
                    'report_year' => $paymentDate->year,

                    'raw_message' => $text,
                    'status' => 'success',
                    'parsed_successfully' => true,
                    'is_duplicate' => $existingPayment ? true : false,
                ]
            );

            if ($group) {
                $group->update([
                    'last_payment_at' => now(),
                ]);
            }

            return response()->json([
                'ok' => true,
                'message' => 'Payment saved',
                'telegram_user_id' => $telegramUserId,
                'telegram_chat_id' => $telegramChatId,
                'user_id' => $userId,
                'subscription_id' => $subscriptionId,
                'telegram_group_id' => $telegramGroupId,
                'found_group' => (bool) $group,
                'data' => $payment,
            ]);

        } catch (\Throwable $e) {
            Log::error('Telegram payment webhook error', [
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ]);

            return response()->json([
                'ok' => false,
                'message' => $e->getMessage(),
                'line' => $e->getLine(),
                'file' => $e->getFile(),
            ], 500);
        }
    }
}