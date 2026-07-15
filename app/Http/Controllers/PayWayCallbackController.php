<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PayWayPayment;
use App\Services\PayWay\AbaPayWayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PayWayCallbackController extends Controller
{
    public function callback(
        Request $request,
        AbaPayWayClient $payWay
    ): JsonResponse {
        Log::info('PayWay payment-link callback received', [
            'payload' => $request->all(),
            'content_type' => $request->header('Content-Type'),
            'ip' => $request->ip(),
        ]);

        $validated = $request->validate([
            'tran_id' => [
                'required',
                'string',
                'max:100',
            ],

            'status' => [
                'required',
            ],

            'merchant_ref_no' => [
                'required',
                'string',
                'max:50',
            ],
        ]);

        $transactionId = $validated['tran_id'];
        $merchantReference = $validated['merchant_ref_no'];

        $payment = PayWayPayment::query()
            ->where('merchant_ref_no', $merchantReference)
            ->first();

        if (! $payment) {
            Log::warning('PayWay callback payment not found', [
                'merchant_ref_no' => $merchantReference,
                'tran_id' => $transactionId,
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Payment reference not found.',
            ], 404);
        }

        if (
            $payment->status === 'approved'
            && $payment->tran_id === $transactionId
        ) {
            return response()->json([
                'success' => true,
                'message' => 'Payment callback already processed.',
            ]);
        }

        try {
            $result = $payWay->checkTransaction(
                transactionId: $transactionId
            );

            $data = $result['data'] ?? [];

            $paymentStatus = strtoupper(
                trim(
                    (string) (
                        $data['payment_status']
                        ?? $data['status']
                        ?? ''
                    )
                )
            );

            $localStatus = match ($paymentStatus) {
                'APPROVED',
                'SUCCESS',
                'COMPLETED',
                'PAID' => 'approved',

                'PENDING',
                'PRE-AUTH',
                'PRE_AUTH',
                'PROCESSING' => 'pending',

                default => 'failed',
            };

            $payment->update([
                'tran_id' => $transactionId,
                'status' => $localStatus,
                'gateway_status' => $paymentStatus,
                'paid_at' => $localStatus === 'approved'
                    ? ($payment->paid_at ?? now())
                    : $payment->paid_at,
                'callback_payload' => $request->all(),
                'verification_response' => $result,
            ]);

            Log::info('PayWay transaction verified and saved', [
                'tran_id' => $transactionId,
                'merchant_ref_no' => $merchantReference,
                'payment_status' => $paymentStatus,
                'local_status' => $localStatus,
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Payment callback processed.',
            ]);
        } catch (Throwable $exception) {
            Log::error('PayWay verification failed', [
                'tran_id' => $transactionId,
                'merchant_ref_no' => $merchantReference,
                'error' => $exception->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => app()->isLocal()
                    ? $exception->getMessage()
                    : 'Payment verification failed.',
            ], 500);
        }
    }
}