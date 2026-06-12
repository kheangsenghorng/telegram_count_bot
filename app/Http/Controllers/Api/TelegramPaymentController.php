<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TelegramPayment;
use Illuminate\Http\Request;

class TelegramPaymentController extends Controller
{
    public function index()
    {
        return response()->json([
            'success' => true,
            'data' => TelegramPayment::latest()->paginate(20),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'user_id' => 'required|exists:users,uuid',
            'subscription_id' => 'required|exists:user_subscriptions,userSubscriptionsID',
            'telegram_group_id' => 'nullable|exists:telegram_groups,telegramGroupsID',

            'currency' => 'required|string|max:10',
            'amount' => 'required|numeric|min:0',

            'payer_name' => 'nullable|string',
            'payer_account' => 'nullable|string',
            'merchant_name' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'bank_code' => 'nullable|string',

            'trx_id' => 'required|string|unique:telegram_payments,trx_id',
            'apv' => 'nullable|string',
            'payment_date' => 'nullable|date',

            'raw_message' => 'nullable|string',
            'status' => 'nullable|in:pending,success,failed,cancelled',
        ]);

        $data['status'] = $data['status'] ?? 'success';
        $data['parsed_successfully'] = true;

        $payment = TelegramPayment::create($data);

        return response()->json([
            'success' => true,
            'message' => 'ABA payment created successfully',
            'data' => $payment,
        ]);
    }

    public function show(string $id)
    {
        $payment = TelegramPayment::with([
            'user',
            'subscription',
            'telegramGroup',
        ])->findOrFail($id);

        return response()->json([
            'success' => true,
            'data' => $payment,
        ]);
    }

    public function update(Request $request, string $id)
    {
        $payment = TelegramPayment::findOrFail($id);

        $data = $request->validate([
            'currency' => 'sometimes|string|max:10',
            'amount' => 'sometimes|numeric|min:0',
            'payer_name' => 'nullable|string',
            'payer_account' => 'nullable|string',
            'merchant_name' => 'nullable|string',
            'payment_method' => 'nullable|string',
            'bank_code' => 'nullable|string',
            'apv' => 'nullable|string',
            'payment_date' => 'nullable|date',
            'status' => 'nullable|in:pending,success,failed,cancelled',
        ]);

        $payment->update($data);

        return response()->json([
            'success' => true,
            'message' => 'Payment updated successfully',
            'data' => $payment,
        ]);
    }

    public function destroy(string $id)
    {
        TelegramPayment::findOrFail($id)->delete();

        return response()->json([
            'success' => true,
            'message' => 'Payment deleted successfully',
        ]);
    }
}