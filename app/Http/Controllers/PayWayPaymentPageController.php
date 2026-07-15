<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\PayWayPayment;
use App\Services\PayWay\AbaPayWayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Throwable;

final class PayWayPaymentPageController extends Controller
{
    public function show(
        string $merchantReference,
        AbaPayWayClient $payWay
    ): View {
        $payment = $this->findPayment(
            $merchantReference
        );

        $payment = $this->refreshPaymentStatus(
            payment: $payment,
            payWay: $payWay
        );

        return view('payway.payment', [
            'payment' => $payment,
        ]);
    }

    public function status(
        string $merchantReference,
        AbaPayWayClient $payWay
    ): JsonResponse {
        $payment = $this->findPayment(
            $merchantReference
        );

        $payment = $this->refreshPaymentStatus(
            payment: $payment,
            payWay: $payWay
        );

        return response()->json([
            'success' => true,

            'data' => [
                'merchant_ref_no' =>
                    $payment->merchant_ref_no,

                /*
                 * Actual paid transaction ID received from callback.
                 */
                'tran_id' =>
                    $payment->tran_id,

                /*
                 * Local application status:
                 * pending, approved, failed, expired.
                 */
                'status' =>
                    $payment->status,

                /*
                 * PayWay payment-link status:
                 * OPEN, PAID, etc.
                 */
                'gateway_status' =>
                    $payment->gateway_status,

                'amount' =>
                    $payment->amount,

                'currency' =>
                    $payment->currency,

                'payment_limit' =>
                    $payment->payment_limit,

                'expired_date' =>
                    $payment->expired_date,

                'paid_at' =>
                    $payment->paid_at?->toISOString(),

                'payment_link' =>
                    $payment->payment_link,

                'success_url' => route(
                    'payway.payment-result',
                    [
                        'merchantReference' =>
                            $payment->merchant_ref_no,
                    ]
                ),

                'cancel_url' => route(
                    'payway.payment-cancelled',
                    [
                        'merchantReference' =>
                            $payment->merchant_ref_no,
                    ]
                ),
            ],
        ]);
    }

    public function success(
        string $merchantReference,
        AbaPayWayClient $payWay
    ): View {
        $payment = $this->findPayment(
            $merchantReference
        );

        $payment = $this->refreshPaymentStatus(
            payment: $payment,
            payWay: $payWay
        );

        return view('payway.payment-result', [
            'payment' => $payment,
        ]);
    }

    public function cancelled(
        string $merchantReference
    ): View {
        $payment = $this->findPayment(
            $merchantReference
        );

        /*
         * Do not overwrite an approved payment as cancelled.
         *
         * The cancel page only means the customer stopped or closed
         * the browser payment flow. It does not necessarily mean
         * PayWay cancelled the payment.
         */
        return view('payway.payment-cancelled', [
            'payment' => $payment,
        ]);
    }

    /**
     * Refresh the local payment status using:
     *
     * 1. Local expiration timestamp
     * 2. PayWay payment-link detail API
     * 3. payment_limit compared with total_trxn
     */
    private function refreshPaymentStatus(
        PayWayPayment $payment,
        AbaPayWayClient $payWay
    ): PayWayPayment {
        /*
         * Final approved payments do not need another gateway request.
         */
        if ($payment->status === 'approved') {
            return $payment;
        }

        /*
         * Mark a still-pending payment as expired based on the
         * local expiration timestamp.
         */
        if ($this->hasExpired($payment)) {
            $payment->update([
                'status' => 'expired',
            ]);

            return $payment->refresh();
        }

        /*
         * Cannot check PayWay details without the payment-link ID.
         */
        if (
            $payment->payment_link_id === null
            || trim($payment->payment_link_id) === ''
        ) {
            return $payment;
        }

        try {
            $result = $payWay->getPaymentLinkDetails(
                paymentLinkId: $payment->payment_link_id
            );

            $data = $result['data'] ?? [];

            $gatewayStatus = strtoupper(
                trim(
                    (string) ($data['status'] ?? '')
                )
            );

            $paymentLimit = $this->nullableInteger(
                $data['payment_limit']
                    ?? $payment->payment_limit
            );

            $totalTransactions = max(
                0,
                (int) ($data['total_trxn'] ?? 0)
            );

            /*
             * PayWay may temporarily return:
             *
             * status        = OPEN
             * payment_limit = 1
             * total_trxn    = 1
             *
             * Treat the payment link as completed when its transaction
             * limit has been reached.
             */
            $limitReached =
                $paymentLimit !== null
                && $paymentLimit > 0
                && $totalTransactions >= $paymentLimit;

            $localStatus = match (true) {
                $limitReached => 'approved',

                in_array(
                    $gatewayStatus,
                    ['PAID', 'APPROVED', 'COMPLETED'],
                    true
                ) => 'approved',

                in_array(
                    $gatewayStatus,
                    ['EXPIRED', 'CLOSED'],
                    true
                ) => 'expired',

                in_array(
                    $gatewayStatus,
                    ['FAILED', 'DECLINED'],
                    true
                ) => 'failed',

                default => 'pending',
            };

            $updateData = [
                'status' => $localStatus,

                'gateway_status' =>
                    $gatewayStatus !== ''
                        ? $gatewayStatus
                        : $payment->gateway_status,

                'payment_limit' =>
                    $paymentLimit
                    ?? $payment->payment_limit,

                'verification_response' =>
                    $result,
            ];

            if (
                $localStatus === 'approved'
                && $payment->paid_at === null
            ) {
                $updateData['paid_at'] = now();
            }

            /*
             * Keep any existing callback transaction ID.
             * The detail API tran_id is only a gateway request/log ID.
             */
            $payment->update($updateData);

            Log::info(
                'PayWay payment-link status refreshed',
                [
                    'merchant_ref_no' =>
                        $payment->merchant_ref_no,

                    'payment_link_id' =>
                        $payment->payment_link_id,

                    'gateway_status' =>
                        $gatewayStatus,

                    'payment_limit' =>
                        $paymentLimit,

                    'total_trxn' =>
                        $totalTransactions,

                    'limit_reached' =>
                        $limitReached,

                    'local_status' =>
                        $localStatus,
                ]
            );
        } catch (Throwable $exception) {
            /*
             * Do not break the payment page if PayWay is temporarily
             * unavailable. Return the latest local database status.
             */
            Log::warning(
                'Unable to refresh PayWay payment-link status',
                [
                    'merchant_ref_no' =>
                        $payment->merchant_ref_no,

                    'payment_link_id' =>
                        $payment->payment_link_id,

                    'error' =>
                        $exception->getMessage(),
                ]
            );
        }

        return $payment->refresh();
    }

    private function hasExpired(
        PayWayPayment $payment
    ): bool {
        return $payment->status === 'pending'
            && $payment->expired_date !== null
            && (int) $payment->expired_date
                <= now()->timestamp;
    }

    private function nullableInteger(
        mixed $value
    ): ?int {
        if (
            $value === null
            || $value === ''
            || ! is_numeric($value)
        ) {
            return null;
        }

        return (int) $value;
    }

    private function findPayment(
        string $merchantReference
    ): PayWayPayment {
        return PayWayPayment::query()
            ->where(
                'merchant_ref_no',
                $merchantReference
            )
            ->firstOrFail();
    }
}

