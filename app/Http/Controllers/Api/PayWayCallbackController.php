<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Telegram\PackageHandler;
use App\Http\Controllers\Controller;
use App\Models\PackageTransaction;
use App\Models\PayWayPayment;
use App\Services\PaymentConfirmationService;
use App\Services\PayWay\AbaCheckoutService;
use App\Services\PayWay\AbaPayWayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PayWayCallbackController extends Controller
{
    public function __construct(
        private readonly AbaCheckoutService $checkout,
        private readonly AbaPayWayClient $client,
        private readonly PaymentConfirmationService $confirmation,
    ) {}

    /**
     * Receive an ABA PayWay payment-link callback.
     *
     * The callback body is never trusted as final proof of payment.
     * Every payment is verified with ABA before it is marked as paid.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();

        $merchantReference = trim((string) (
            $payload['merchant_ref_no']
            ?? $payload['merchant_ref']
            ?? ''
        ));

        $abaTransactionId = trim((string) (
            $payload['tran_id']
            ?? $payload['transaction_id']
            ?? ''
        ));

        Log::info('PayWay callback received', [
            'merchant_ref_no' => $merchantReference,
            'tran_id' => $abaTransactionId,
            'payload_keys' => array_keys($payload),
        ]);

        if (
            $merchantReference === ''
            && $abaTransactionId === ''
        ) {
            Log::warning(
                'PayWay callback missing payment identifiers'
            );

            return response()->json([
                'ok' => false,
                'message' =>
                    'Missing merchant_ref_no and tran_id.',
            ], 422);
        }

        /*
        |--------------------------------------------------------------------------
        | Find the PayWay gateway record
        |--------------------------------------------------------------------------
        */

        $gatewayPayment = $this->findGatewayPayment(
            merchantReference: $merchantReference,
            abaTransactionId: $abaTransactionId,
        );

        if (! $gatewayPayment) {
            Log::warning('PayWay callback payment not found', [
                'merchant_ref_no' => $merchantReference,
                'tran_id' => $abaTransactionId,
            ]);

            /*
             * Return 200 for an unknown merchant reference so ABA does
             * not continuously retry a callback that cannot be matched.
             */
            return response()->json(['ok' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | Save callback data for auditing
        |--------------------------------------------------------------------------
        */

        $gatewayPayment->forceFill([
            'callback_payload' => $payload,

            /*
             * Save the customer payment transaction ID only when ABA
             * included one in the callback.
             */
            'tran_id' => $abaTransactionId !== ''
                ? $abaTransactionId
                : $gatewayPayment->tran_id,
        ])->save();

        /*
        |--------------------------------------------------------------------------
        | Find the linked package transaction
        |--------------------------------------------------------------------------
        */

        $packageTransaction = PackageTransaction::query()
            ->where(
                'merchant_ref_no',
                $gatewayPayment->merchant_ref_no
            )
            ->where('gateway', 'aba_payway')
            ->first();

        /*
         * For a standalone payment link, an already-approved gateway
         * record is fully processed.
         *
         * Do not apply this early return to package transactions:
         * payment may be approved while subscription activation still
         * needs to be retried.
         */
        if (
            ! $packageTransaction
            && $gatewayPayment->isApproved()
        ) {
            return response()->json(['ok' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | Save the ABA transaction ID on the package transaction
        |--------------------------------------------------------------------------
        */

        if (
            $packageTransaction
            && $abaTransactionId !== ''
            && empty($packageTransaction->aba_tran_id)
        ) {
            $packageTransaction->forceFill([
                'aba_tran_id' => $abaTransactionId,
            ])->save();
        }

        /*
        |--------------------------------------------------------------------------
        | Verify directly with ABA
        |--------------------------------------------------------------------------
        */

        try {
            $verification = $this->verifyPayment(
                gatewayPayment: $gatewayPayment,
                packageTransaction: $packageTransaction,
                abaTransactionId: $abaTransactionId,
            );

            $isPaid = $this->checkout->isPaid(
                $verification
            );
        } catch (Throwable $exception) {
            Log::error('PayWay callback verification failed', [
                'merchant_ref_no' =>
                    $gatewayPayment->merchant_ref_no,

                'tran_id' => $abaTransactionId,

                'error' => $exception->getMessage(),
            ]);

            /*
             * Return a non-2xx response so ABA can retry later.
             */
            return response()->json([
                'ok' => false,
                'message' =>
                    'Payment verification failed. Retry later.',
            ], 502);
        }

        $gatewayPayment->forceFill([
            'verification_response' => $verification,
        ])->save();

        if (! $isPaid) {
            Log::info('PayWay callback payment not completed', [
                'merchant_ref_no' =>
                    $gatewayPayment->merchant_ref_no,

                'tran_id' => $abaTransactionId,
            ]);

            return response()->json(['ok' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | Standalone payment link
        |--------------------------------------------------------------------------
        */

        if (! $packageTransaction) {
            $this->markStandalonePaymentApproved(
                gatewayPayment: $gatewayPayment,
                abaTransactionId: $abaTransactionId,
            );

            return response()->json(['ok' => true]);
        }

        /*
        |--------------------------------------------------------------------------
        | Claim package payment
        |--------------------------------------------------------------------------
        */

        try {
            $activatedTransaction = $this->checkout->markPaid(
                payment: $packageTransaction,
                abaTranId: $abaTransactionId,
            );
        } catch (Throwable $exception) {
            Log::error(
                'PayWay callback failed to persist payment',
                [
                    'packageTransactionsID' =>
                        $packageTransaction->getKey(),

                    'merchant_ref_no' =>
                        $gatewayPayment->merchant_ref_no,

                    'error' =>
                        $exception->getMessage(),
                ]
            );

            return response()->json([
                'ok' => false,
                'message' =>
                    'Payment persistence failed. Retry later.',
            ], 502);
        }

        /*
         * markPaid() returns null when another callback already changed
         * the transaction from pending to paid.
         *
         * Reload it and continue activation when the subscription has
         * not yet been attached. This repairs the failed-activation case.
         */
        if (! $activatedTransaction) {
            $activatedTransaction =
                PackageTransaction::query()
                    ->whereKey(
                        $packageTransaction->getKey()
                    )
                    ->first();

            if (! $activatedTransaction) {
                Log::error(
                    'Paid package transaction disappeared',
                    [
                        'packageTransactionsID' =>
                            $packageTransaction->getKey(),
                    ]
                );

                return response()->json([
                    'ok' => false,
                    'message' =>
                        'Package transaction not found.',
                ], 500);
            }

            /*
             * Payment and package activation are already complete.
             */
            if (
                ! empty(
                    $activatedTransaction->subscription_id
                )
            ) {
                return response()->json(['ok' => true]);
            }

            if (
                ! in_array(
                    (string) $activatedTransaction->status,
                    ['paid', 'completed'],
                    true
                )
            ) {
                Log::warning(
                    'Package transaction was not claimed as paid',
                    [
                        'packageTransactionsID' =>
                            $activatedTransaction->getKey(),

                        'status' =>
                            $activatedTransaction->status,
                    ]
                );

                return response()->json([
                    'ok' => false,
                    'message' =>
                        'Payment state is not ready.',
                ], 409);
            }
        }

        /*
        |--------------------------------------------------------------------------
        | Activate package subscription
        |--------------------------------------------------------------------------
        */

        try {
            $subscription = $this->confirmation
                ->activateFromPackageTransaction(
                    $activatedTransaction
                );

            if ($subscription) {
                $this->checkout->attachSubscription(
                    payment: $activatedTransaction,
                    userSubscriptionsID: (string)
                        $subscription->userSubscriptionsID,
                );

                /*
                 * Refresh the Telegram package/subscription cache.
                 *
                 * package_transactions.user_id references users.uuid.
                 */
                if (! empty($activatedTransaction->user_id)) {
                    PackageHandler::invalidateSubscription(
                        (string) $activatedTransaction->user_id
                    );
                }
            }
        } catch (Throwable $exception) {
            Log::error(
                'PayWay callback package activation failed',
                [
                    'packageTransactionsID' =>
                        $activatedTransaction->getKey(),

                    'merchant_ref_no' =>
                        $gatewayPayment->merchant_ref_no,

                    'error' =>
                        $exception->getMessage(),
                ]
            );

            /*
             * The transaction remains safely paid.
             *
             * Return a non-2xx response so the next callback can retry
             * activation. PaymentConfirmationService should also be
             * idempotent and avoid creating duplicate subscriptions.
             */
            return response()->json([
                'ok' => false,
                'message' =>
                    'Package activation failed. Retry later.',
            ], 502);
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Find a gateway payment using the merchant reference first.
     */
    private function findGatewayPayment(
        string $merchantReference,
        string $abaTransactionId,
    ): ?PayWayPayment {
        if ($merchantReference !== '') {
            $payment = PayWayPayment::query()
                ->where(
                    'merchant_ref_no',
                    $merchantReference
                )
                ->first();

            if ($payment) {
                return $payment;
            }
        }

        if ($abaTransactionId !== '') {
            return PayWayPayment::query()
                ->where('tran_id', $abaTransactionId)
                ->first();
        }

        return null;
    }

    /**
     * Verify the transaction against ABA.
     */
    private function verifyPayment(
        PayWayPayment $gatewayPayment,
        ?PackageTransaction $packageTransaction,
        string $abaTransactionId,
    ): array {
        if ($packageTransaction) {
            return $this->checkout->verifyTransaction(
                $packageTransaction
            );
        }

        if ($abaTransactionId !== '') {
            return $this->client->checkTransaction(
                $abaTransactionId
            );
        }

        $paymentLinkId = trim(
            (string) $gatewayPayment->payment_link_id
        );

        if ($paymentLinkId === '') {
            throw new \RuntimeException(
                'The PayWay payment-link ID is missing.'
            );
        }

        return $this->client->getPaymentLinkDetails(
            $paymentLinkId
        );
    }

    /**
     * Mark a non-package PayWay payment as approved.
     */
    private function markStandalonePaymentApproved(
        PayWayPayment $gatewayPayment,
        string $abaTransactionId,
    ): void {
        $updates = [
            'status' => 'approved',
            'gateway_status' => 'APPROVED',
            'paid_at' => now(),
        ];

        if ($abaTransactionId !== '') {
            $updates['tran_id'] = $abaTransactionId;
        }

        PayWayPayment::query()
            ->whereKey($gatewayPayment->getKey())
            ->where('status', '!=', 'approved')
            ->update($updates);
    }
}

