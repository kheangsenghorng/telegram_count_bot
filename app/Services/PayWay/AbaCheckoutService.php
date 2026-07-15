<?php

declare(strict_types=1);

namespace App\Services\PayWay;

use App\Models\Package;
use App\Models\PackageTransaction;
use App\Models\PayWayPayment;
use App\Models\User;
use App\Services\Telegram\TelegramMessageCleanup as TelegramTelegramMessageCleanup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class AbaCheckoutService
{
    public function __construct(
        private readonly AbaPayWayClient $client,
    ) {}

    /**
     * Create an ABA PayWay payment link for a Telegram package.
     *
     * Writes both:
     *  - pay_way_payments       (gateway record, PAY-... ref)
     *  - package_transactions   (package/subscription record)
     */
    public function createCheckout(
        Package $package,
        int|string $telegramUserId,
        string $requestedBy,
        ?string $telegramChatId = null,
        ?int $telegramMessageId = null,
    ): PackageTransaction {
        $user = User::query()
            ->where('telegram_id', (string) $telegramUserId)
            ->first();

        if (! $user) {
            throw new RuntimeException(
                "No user was found for Telegram ID {$telegramUserId}. "
                .'The user must start the bot before purchasing a package.'
            );
        }

        if (empty($package->packagesID)) {
            throw new RuntimeException(
                'The selected package does not have a valid package ID.'
            );
        }

        $currency = strtoupper(
            trim((string) config(
                'payway.default_currency',
                'USD'
            ))
        );

        if (! in_array($currency, ['USD', 'KHR'], true)) {
            throw new RuntimeException(
                'PAYWAY_DEFAULT_CURRENCY must be USD or KHR.'
            );
        }

        $amount = $this->normalizeAmount(
            amount: $package->price,
            currency: $currency,
        );

        $callbackUrl = trim(
            (string) config('payway.callback_url')
        );

        if (
            $callbackUrl === ''
            || filter_var(
                $callbackUrl,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            throw new RuntimeException(
                'A valid PayWay callback URL must be configured.'
            );
        }

        $lifetimeMinutes = max(
            1,
            (int) config(
                'payway.payment_link_lifetime_minutes',
                1440
            )
        );

        $expiresAt = now()->addMinutes($lifetimeMinutes);

        $merchantReference =
            $this->generateMerchantReference();

        /*
         * ABA's KHQR renderer fails on non-ASCII (Khmer) in the
         * payment-link title — send ASCII only. The Khmer package
         * name is still shown in the Telegram messages.
         */
        $title = $this->asciiPackageLabel(
            $package,
            $merchantReference
        );

        $description = mb_substr(
            $this->asciiPackageLabel($package, $merchantReference),
            0,
            250
        );

        $response = $this->client->createPaymentLink([
            'title' => mb_substr($title, 0, 250),

            'amount' => $amount,

            'currency' => $currency,

            'description' => $description,

            'payment_limit' => 1,

            /*
             * Unix timestamp in seconds.
             */
            'expired_date' =>
                $expiresAt->getTimestamp(),

            'return_url' => $callbackUrl,

            'merchant_ref_no' =>
                $merchantReference,
        ]);

        $data = $response['data'] ?? null;

        if (! is_array($data)) {
            throw new RuntimeException(
                'ABA PayWay returned invalid payment-link data.'
            );
        }

        $paymentLinkId = trim(
            (string) ($data['id'] ?? '')
        );

        $checkoutUrl = trim(
            (string) ($data['payment_link'] ?? '')
        );

        $createLogId = trim(
            (string) ($response['tran_id'] ?? '')
        );

        if ($paymentLinkId === '') {
            throw new RuntimeException(
                'ABA PayWay did not return a payment-link ID.'
            );
        }

        if (
            $checkoutUrl === ''
            || filter_var(
                $checkoutUrl,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            throw new RuntimeException(
                'ABA PayWay did not return a valid payment URL.'
            );
        }

        /*
         * Persist both records atomically. If either insert fails,
         * nothing is stored and the user can retry (the ABA link
         * simply expires unused).
         */
        return DB::transaction(
            function () use (
                $user,
                $package,
                $amount,
                $currency,
                $merchantReference,
                $paymentLinkId,
                $checkoutUrl,
                $createLogId,
                $telegramChatId,
                $telegramMessageId,
                $expiresAt,
                $title,
                $description,
                $data,
                $response
            ): PackageTransaction {
                PayWayPayment::query()->create([
                    'merchant_ref_no' =>
                        $merchantReference,

                    'payment_link_id' =>
                        $paymentLinkId,

                    'create_log_id' =>
                        $createLogId !== ''
                            ? $createLogId
                            : null,

                    'tran_id' => null,

                    'title' => $title,

                    'amount' => $amount,

                    'currency' => $currency,

                    'description' => $description,

                    'payment_limit' => 1,

                    'expired_date' =>
                        $expiresAt->getTimestamp(),

                    'payment_link' =>
                        $checkoutUrl,

                    'status' => 'pending',

                    'gateway_status' =>
                        (string) ($data['status'] ?? 'OPEN'),

                    'create_response' => $response,
                ]);

                return PackageTransaction::query()->create([
                    'user_id' => $user->uuid,

                    'package_id' =>
                        $package->packagesID,

                    'amount' => $amount,
                    'currency' => $currency,

                    'payment_method' => 'aba_payway',
                    'gateway' => 'aba_payway',
                    'status' => 'pending',

                    /*
                     * Same PAY-... reference as the
                     * pay_way_payments row — this is the join key.
                     */
                    'merchant_ref_no' =>
                        $merchantReference,

                    'external_transaction_id' =>
                        $paymentLinkId,

                    'create_log_id' =>
                        $createLogId !== ''
                            ? $createLogId
                            : null,

                    'aba_tran_id' => null,

                    /*
                     * Stored at creation — never regenerated.
                     */
                    'checkout_url' => $checkoutUrl,

                    'telegram_chat_id' =>
                        $telegramChatId,

                    'telegram_message_id' =>
                        $telegramMessageId,

                    'expires_at' => $expiresAt,

                    'gateway_status' =>
                        (string) ($data['status'] ?? 'OPEN'),

                    'create_response' => $response,
                ]);
            }
        );
    }

    /**
     * Return the PayWay URL stored during creation.
     */
    public function checkoutUrl(
        PackageTransaction $payment
    ): string {
        $checkoutUrl = trim(
            (string) $payment->checkout_url
        );

        if ($checkoutUrl === '') {
            throw new RuntimeException(
                'This transaction does not have a checkout URL.'
            );
        }

        if (
            filter_var(
                $checkoutUrl,
                FILTER_VALIDATE_URL
            ) === false
        ) {
            throw new RuntimeException(
                'The stored checkout URL is invalid.'
            );
        }

        return $checkoutUrl;
    }

   /**
     * Verify using the paid ABA tran_id when available.
     *
     * Payment-link purchases use an ABA-generated tran_id that the
     * merchant check-transaction API sometimes cannot see (error 6,
     * "tran_id not found") — especially in sandbox or immediately
     * after the callback. In that case we fall back to the
     * payment-link details, which isPaid() also understands
     * (payment_status / total_trxn).
     */
    public function verifyTransaction(
        PackageTransaction $payment
    ): array {
        if (
            (string) $payment->gateway !== 'aba_payway'
            && (string) $payment->payment_method !== 'aba_payway'
        ) {
            throw new RuntimeException(
                'The transaction is not an ABA PayWay transaction.'
            );
        }

        $paymentLinkId = trim(
            (string) $payment->external_transaction_id
        );

        $abaTransactionId = trim(
            (string) ($payment->aba_tran_id ?? '')
        );

        if ($abaTransactionId !== '') {
            try {
                return $this->client->checkTransaction(
                    $abaTransactionId
                );
            } catch (\Throwable $exception) {
                // Error 6: tran_id not found — fall through to
                // the payment-link details when we can.
                if ($paymentLinkId === '') {
                    throw $exception;
                }

                \Illuminate\Support\Facades\Log::info(
                    'ABA checkTransaction failed, '
                    .'falling back to payment-link details',
                    [
                        'merchant_ref_no' =>
                            (string) $payment->merchant_ref_no,

                        'aba_tran_id' => $abaTransactionId,

                        'error' => $exception->getMessage(),
                    ]
                );
            }
        }

        if ($paymentLinkId === '') {
            throw new RuntimeException(
                'The ABA payment-link ID is missing.'
            );
        }

        return $this->client->getPaymentLinkDetails(
            $paymentLinkId
        );
    }
    /**
     * Determine whether the payment is complete.
     */
    public function isPaid(
        array $verification
    ): bool {
        $data = is_array(
            $verification['data'] ?? null
        )
            ? $verification['data']
            : [];

        $statusCandidates = [
            $data['payment_status'] ?? null,
            $data['tran_status'] ?? null,
            $data['transaction_status'] ?? null,
        ];

        foreach ($statusCandidates as $candidate) {
            $status = strtoupper(
                trim((string) $candidate)
            );

            if (
                in_array(
                    $status,
                    [
                        'APPROVED',
                        'SUCCESS',
                        'SUCCESSFUL',
                        'COMPLETED',
                        'PAID',
                    ],
                    true
                )
            ) {
                return true;
            }
        }

        return (int) ($data['total_trxn'] ?? 0) >= 1;
    }

    /**
     * ASCII-safe label sent to ABA.
     */
    private function asciiPackageLabel(
        Package $package,
        string $merchantReference
    ): string {
        $name = trim((string) $package->name);

        if (
            $name !== ''
            && mb_check_encoding($name, 'ASCII')
        ) {
            return $name;
        }

        return sprintf(
            'Telegram Package %s',
            $merchantReference
        );
    }

    /**
     * Generate a unique PAY-... merchant_ref_no.
     *
     * Checked against BOTH tables since the reference is shared.
     */
    private function generateMerchantReference(): string
    {
        do {
            $reference = sprintf(
                'PAY-%s-%s',
                now()->format('YmdHis'),
                strtoupper(Str::random(4))
            );

            $exists =
                PayWayPayment::query()
                    ->where(
                        'merchant_ref_no',
                        $reference
                    )
                    ->exists()
                || PackageTransaction::query()
                    ->where(
                        'merchant_ref_no',
                        $reference
                    )
                    ->exists();
        } while ($exists);

        return $reference;
    }

    /**
     * Normalize the amount sent to ABA.
     */
    private function normalizeAmount(
        mixed $amount,
        string $currency
    ): string {
        if (! is_numeric($amount)) {
            throw new RuntimeException(
                'The package price must be numeric.'
            );
        }

        $numericAmount = (float) $amount;

        $minimum = $currency === 'KHR'
            ? 100
            : 0.01;

        if ($numericAmount < $minimum) {
            throw new RuntimeException(
                "The minimum amount is {$minimum} {$currency}."
            );
        }

        if ($currency === 'KHR') {
            return number_format(
                $numericAmount,
                0,
                '.',
                ''
            );
        }

        return number_format(
            $numericAmount,
            2,
            '.',
            ''
        );
    }
    /**
     * Mark the transaction paid, atomically and idempotently.
     *
     * Updates:
     *  - package_transactions → status 'paid', paid_at, aba_tran_id,
     *                           subscription_id (userSubscriptionsID)
     *  - pay_way_payments     → status 'approved', tran_id, paid_at
     *
     * Returns the fresh PackageTransaction when THIS call won the
     * pending → paid transition, or null when it was already paid
     * (duplicate callback / race lost).
     */
    public function markPaid(
        PackageTransaction $payment,
        ?string $abaTranId = null,
        ?string $subscriptionId = null,
    ): ?PackageTransaction {
        $abaTranId = trim((string) $abaTranId);

        $paid = DB::transaction(
            function () use (
                $payment,
                $abaTranId,
                $subscriptionId
            ): ?PackageTransaction {
                // Atomic claim — only one caller wins pending → paid.
                $updates = [
                    'status' => 'paid',
                    'paid_at' => now(),
                ];

                if ($abaTranId !== '') {
                    $updates['aba_tran_id'] = $abaTranId;
                }

                if ($subscriptionId !== null) {
                    // FK → user_subscriptions.userSubscriptionsID
                    $updates['subscription_id'] = $subscriptionId;
                }

                $claimed = PackageTransaction::query()
                    ->whereKey($payment->getKey())
                    ->where('status', 'pending')
                    ->update($updates);

                // Gateway record — 'approved' matches
                // PayWayPayment::isApproved().
                if (! empty($payment->merchant_ref_no)) {
                    $gatewayUpdates = [
                        'status' => 'approved',
                        'gateway_status' => 'APPROVED',
                        'paid_at' => now(),
                    ];

                    if ($abaTranId !== '') {
                        $gatewayUpdates['tran_id'] = $abaTranId;
                    }

                    PayWayPayment::query()
                        ->where(
                            'merchant_ref_no',
                            $payment->merchant_ref_no
                        )
                        ->where('status', '!=', 'approved')
                        ->update($gatewayUpdates);
                }

                return $claimed === 1
                    ? $payment->fresh()
                    : null;
            }
        );

        /*
         * Delete the checkout message (QR / pay button) in Telegram.
         *
         * Runs AFTER the transaction commits, and only for the winner
         * of the pending → paid claim — so duplicate callbacks never
         * try to delete twice. Never throws: a failed delete must not
         * break payment confirmation.
         */
        if ($paid !== null) {
            app(\App\Services\Telegram\TelegramMessageCleanup::class)
                ->deleteCheckoutMessage($paid);
        }

        return $paid;
    }

    /**
     * Link the created subscription after activation, when the
     * subscription row is created AFTER markPaid() ran.
     */
    public function attachSubscription(
        PackageTransaction $payment,
        string $userSubscriptionsID,
    ): void {
        PackageTransaction::query()
            ->whereKey($payment->getKey())
            ->update([
                'subscription_id' => $userSubscriptionsID,
            ]);
    }
}