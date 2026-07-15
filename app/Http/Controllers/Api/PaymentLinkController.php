<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\PayWayPayment;
use App\Services\PayWay\AbaPayWayClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

final class PaymentLinkController extends Controller
{
    /**
     * Create an ABA PayWay payment link.
     */
    public function store(
        Request $request,
        AbaPayWayClient $payWay
    ): JsonResponse {
        if ($request->filled('currency')) {
            $request->merge([
                'currency' => strtoupper(
                    trim((string) $request->input('currency'))
                ),
            ]);
        }

        $validated = $request->validate([
            'title' => [
                'required',
                'string',
                'max:250',
            ],

            'amount' => [
                'required',
                'numeric',
                'decimal:0,2',
                'gt:0',
            ],

            'currency' => [
                'required',
                'string',
                Rule::in(['USD', 'KHR']),
            ],

            'description' => [
                'nullable',
                'string',
                'max:250',
            ],

            'payment_limit' => [
                'nullable',
                'integer',
                'min:1',
            ],

            'expired_date' => [
                'nullable',
                'integer',
                function (
                    string $attribute,
                    mixed $value,
                    \Closure $fail
                ): void {
                    if (
                        $value !== null
                        && (int) $value <= now()->timestamp
                    ) {
                        $fail(
                            'The expiration date must be in the future.'
                        );
                    }
                },
            ],

            'merchant_ref_no' => [
                'nullable',
                'string',
                'max:50',
                'regex:/^[A-Za-z0-9_-]+$/',
                Rule::unique(
                    'pay_way_payments',
                    'merchant_ref_no'
                ),
            ],

            'payout' => [
                'nullable',
                'array',
                'min:1',
            ],

            'payout.*.acc' => [
                'required_with:payout',
                'string',
                'max:50',
            ],

            'payout.*.amt' => [
                'required_with:payout',
                'numeric',
                'gt:0',
            ],

            'image' => [
                'nullable',
                'file',
                'image',
                'mimes:jpg,jpeg,png',
                'max:3072',
            ],
        ]);

        $this->validateMinimumAmount($validated);
        $this->validatePayoutTotal($validated);

        $callbackUrl = trim(
            (string) config('payway.callback_url')
        );

        if ($callbackUrl === '') {
            throw ValidationException::withMessages([
                'return_url' => [
                    'The PayWay callback URL is not configured.',
                ],
            ]);
        }

        if (
            filter_var(
                $callbackUrl,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            throw ValidationException::withMessages([
                'return_url' => [
                    'The configured PayWay callback URL is invalid.',
                ],
            ]);
        }

        $validated['return_url'] = $callbackUrl;

        $validated['merchant_ref_no'] =
            $validated['merchant_ref_no']
            ?? $this->generateMerchantReference();

        /*
         * A generated reference was not checked by Laravel's unique rule,
         * so check it again before sending the request.
         */
        while (
            PayWayPayment::query()
                ->where(
                    'merchant_ref_no',
                    $validated['merchant_ref_no']
                )
                ->exists()
        ) {
            $validated['merchant_ref_no'] =
                $this->generateMerchantReference();
        }

        try {
            $imagePath = $request
                ->file('image')
                ?->getRealPath();

            $result = $payWay->createPaymentLink(
                link: $validated,
                imagePath: $imagePath ?: null,
            );

            $data = $result['data'] ?? [];

            $payment = DB::transaction(
                function () use (
                    $validated,
                    $result,
                    $data
                ): PayWayPayment {
                    return PayWayPayment::query()->create([
                        'merchant_ref_no' =>
                            $validated['merchant_ref_no'],

                        'payment_link_id' =>
                            $data['id'] ?? null,

                        /*
                         * This is the payment-link creation log ID.
                         * The real paid transaction ID arrives later
                         * through the callback.
                         */
                        'create_log_id' =>
                            isset($result['tran_id'])
                                ? (string) $result['tran_id']
                                : null,

                        'tran_id' => null,

                        'title' =>
                            $validated['title'],

                        'amount' =>
                            $validated['amount'],

                        'currency' =>
                            $validated['currency'],

                        'description' =>
                            $validated['description'] ?? null,

                        'payment_limit' =>
                            $validated['payment_limit'] ?? null,

                        'expired_date' =>
                            $validated['expired_date'] ?? null,

                        'payment_link' =>
                            $data['payment_link'] ?? null,

                        'status' => 'pending',

                        'gateway_status' =>
                            $data['status'] ?? 'OPEN',

                        'create_response' =>
                            $result,
                    ]);
                }
            );

            return response()->json([
                'success' => true,

                'message' =>
                    'Payment link created successfully.',

                'data' => $data,

                'tran_id' =>
                    $payment->create_log_id,

                'merchant_ref_no' =>
                    $payment->merchant_ref_no,

                'payment_link_id' =>
                    $payment->payment_link_id,

                'payment_link' =>
                    $payment->payment_link,

                'callback_url' =>
                    $validated['return_url'],

                'payment_page_url' => route(
                    'payway.payments.show',
                    [
                        'merchantReference' =>
                            $payment->merchant_ref_no,
                    ]
                ),

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

                'status' =>
                    $result['status'] ?? null,
            ], 201);
        } catch (Throwable $exception) {
            return $this->payWayErrorResponse(
                exception: $exception,
                publicMessage:
                    'Unable to create the payment link.'
            );
        }
    }

    /**
     * Verify a paid PayWay transaction.
     */
    public function checkTransaction(
        Request $request,
        AbaPayWayClient $payWay
    ): JsonResponse {
        $validated = $request->validate([
            'tran_id' => [
                'required',
                'string',
                'max:100',
            ],
        ]);

        try {
            $result = $payWay->checkTransaction(
                transactionId: $validated['tran_id']
            );

            return response()->json([
                'success' => true,
                'data' => $result['data'] ?? null,
                'status' => $result['status'] ?? null,
            ]);
        } catch (Throwable $exception) {
            return $this->payWayErrorResponse(
                exception: $exception,
                publicMessage:
                    'Unable to check the transaction.'
            );
        }
    }

    /**
     * Retrieve PayWay payment-link details.
     */
    public function details(
        Request $request,
        AbaPayWayClient $payWay
    ): JsonResponse {
        $validated = $request->validate([
            'payment_link_id' => [
                'required',
                'string',
                'max:255',
            ],
        ]);

        try {
            $result = $payWay->getPaymentLinkDetails(
                paymentLinkId:
                    $validated['payment_link_id']
            );

            return response()->json([
                'success' => true,
                'data' => $result['data'] ?? null,
                'status' => $result['status'] ?? null,

                'tran_id' => isset($result['tran_id'])
                    ? (string) $result['tran_id']
                    : null,
            ]);
        } catch (Throwable $exception) {
            return $this->payWayErrorResponse(
                exception: $exception,
                publicMessage:
                    'Unable to retrieve payment-link details.'
            );
        }
    }

    private function validateMinimumAmount(
        array $data
    ): void {
        $currency = strtoupper(
            (string) $data['currency']
        );

        $amount = (float) $data['amount'];

        $minimum = $currency === 'KHR'
            ? 100.0
            : 0.01;

        if ($amount < $minimum) {
            throw ValidationException::withMessages([
                'amount' => [
                    "The minimum amount is {$minimum} {$currency}.",
                ],
            ]);
        }
    }

    private function validatePayoutTotal(
        array $data
    ): void {
        if (empty($data['payout'])) {
            return;
        }

        $payoutTotal = collect($data['payout'])
            ->sum(
                static fn (array $item): float =>
                    (float) $item['amt']
            );

        $paymentAmount = (float) $data['amount'];

        if (
            abs($payoutTotal - $paymentAmount)
            > 0.00001
        ) {
            throw ValidationException::withMessages([
                'payout' => [
                    'The total payout amount must equal '
                    .'the payment-link amount.',
                ],
            ]);
        }
    }

    private function generateMerchantReference(): string
    {
        return sprintf(
            'PAY-%s-%s',
            now()->format('YmdHis'),
            strtoupper(Str::random(8))
        );
    }

    private function payWayErrorResponse(
        Throwable $exception,
        string $publicMessage
    ): JsonResponse {
        report($exception);

        return response()->json([
            'success' => false,

            'message' => app()->isLocal()
                ? $exception->getMessage()
                : $publicMessage,
        ], 502);
    }
}

