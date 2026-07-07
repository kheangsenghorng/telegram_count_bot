<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Api\Telegram\SubscriptionLinkHandler;
use App\Http\Controllers\Controller;
use App\Models\PackageTransaction;
use App\Models\SubscriptionUsageLog;
use App\Models\UserSubscription;
use App\Services\Khqr\BakongService;
use App\Services\TelegramBotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PaymentCheckoutApiController extends Controller
{
    public function show(string $transactionId): JsonResponse
    {
        $transaction = PackageTransaction::with('package')
            ->where('packageTransactionsID', $transactionId)
            ->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'status'  => 'not_found',
                'message' => 'Payment transaction not found',
            ], 404);
        }

        if (
            $transaction->status === 'pending'
            && $transaction->expires_at
            && now()->greaterThan($transaction->expires_at)
        ) {
            $transaction->update([
                'status' => 'expired',
            ]);

            $transaction->refresh();
        }

        return response()->json([
            'success' => true,
            'data' => [
                'transaction_id'          => $transaction->packageTransactionsID,
                'external_transaction_id' => $transaction->external_transaction_id,
                'invoice_no'              => $transaction->external_transaction_id,

                'package_name'  => $transaction->package?->name,
                'merchant_name' => config('services.bakong.merchant_name', 'CHEN KHEANG'),

                'amount'   => (float) $transaction->amount,
                'currency' => $transaction->currency,

                'status' => $transaction->status === 'paid'
                    ? 'success'
                    : $transaction->status,

                'qr_code'      => $transaction->qr_code,
                'qr_image_url' => $transaction->qr_image_url,
                'md5'          => $transaction->md5,

                'expires_at'       => optional($transaction->expires_at)->toISOString(),
                'duration_seconds' => 900,

                'subscription_id' => $transaction->subscription_id,
                'paid_at'         => optional($transaction->paid_at)->toISOString(),
            ],
        ]);
    }

    public function check(
        string $transactionId,
        BakongService $bakong,
        TelegramBotService $telegram,
        SubscriptionLinkHandler $subscriptionLink
    ): JsonResponse {
        $transaction = PackageTransaction::with(['package', 'user'])
            ->where('packageTransactionsID', $transactionId)
            ->first();

        if (! $transaction) {
            return response()->json([
                'success' => false,
                'status'  => 'not_found',
                'message' => 'Payment transaction not found',
            ], 404);
        }

        /**
         * Already paid.
         * Delete old Telegram payment message and send success message ONCE.
         * success_notified_at guards against duplicate messages when the
         * frontend polls check() again after payment.
         */
        if ($transaction->status === 'paid') {
            $this->deleteTelegramPaymentMessage($transaction, $telegram);

            if (
                $transaction->success_notified_at === null
                && $transaction->subscription_id
                && ! empty($transaction->telegram_chat_id)
            ) {
                $subscription = UserSubscription::with('package')
                    ->where('userSubscriptionsID', $transaction->subscription_id)
                    ->first();

                if ($subscription) {
                    $subscriptionLink->sendPaymentSuccess(
                        $subscription,
                        $transaction->telegram_chat_id
                    );

                    $transaction->update(['success_notified_at' => now()]);
                }
            }

            return response()->json([
                'success'         => true,
                'status'          => 'success',
                'message'         => 'Already paid',
                'subscription_id' => $transaction->subscription_id,
            ]);
        }

        /**
         * Expired payment.
         */
        if (
            $transaction->status === 'pending'
            && $transaction->expires_at
            && now()->greaterThan($transaction->expires_at)
        ) {
            $transaction->update([
                'status' => 'expired',
            ]);

            return response()->json([
                'success' => false,
                'status'  => 'expired',
                'message' => 'Payment expired',
            ]);
        }

        if (! $transaction->md5) {
            return response()->json([
                'success' => false,
                'status'  => $transaction->status,
                'message' => 'MD5 not found for this transaction',
            ], 422);
        }

        /**
         * Check Bakong by MD5.
         *
         * Bakong check_transaction_by_md5:
         *   responseCode 0 => transaction FOUND (paid)
         *   responseCode 1 => transaction not found yet (still pending)
         *
         * Strict: require responseCode === 0 at whichever nesting level.
         * (int) cast handles "0" as string; -1 default prevents null == 0.
         */
        $result = $bakong->checkTransactionByMd5($transaction->md5);

        $isPaid =
            (int) data_get($result, 'responseCode', -1) === 0
            || (int) data_get($result, 'data.responseCode', -1) === 0;

        if (! $isPaid) {
            return response()->json([
                'success' => false,
                'status'  => $transaction->status,
                'message' => data_get($result, 'message')
                    ?? data_get($result, 'responseMessage')
                    ?? data_get($result, 'data.responseMessage')
                    ?? 'Payment not found yet',
                'raw' => $result,
            ]);
        }

        try {
            $subscription = DB::transaction(function () use ($transaction, $result) {
                $package = $transaction->package;

                if (! $package) {
                    throw new \Exception('Package not found for this transaction.');
                }

                /**
                 * Prevent duplicate activation (by subscription_id).
                 */
                if ($transaction->subscription_id) {
                    $existingSubscription = UserSubscription::where(
                        'userSubscriptionsID',
                        $transaction->subscription_id
                    )->first();

                    if ($existingSubscription) {
                        $transaction->update([
                            'status'  => 'paid',
                            'paid_at' => $transaction->paid_at ?? now(),
                        ]);

                        return $existingSubscription;
                    }
                }

                $realTransactionId = (string) (
                    $transaction->external_transaction_id
                    ?? $transaction->packageTransactionsID
                );

                /**
                 * Prevent duplicate activation (by transaction_id).
                 */
                $existingByTransaction = UserSubscription::query()
                    ->where('transaction_id', $realTransactionId)
                    ->first();

                if ($existingByTransaction) {
                    $transaction->update([
                        'subscription_id' => $existingByTransaction->userSubscriptionsID,
                        'status'          => 'paid',
                        'paid_at'         => $transaction->paid_at ?? now(),
                    ]);

                    return $existingByTransaction;
                }

                /**
                 * Find current active subscription for same user.
                 */
                $currentSubscription = UserSubscription::query()
                    ->with('package')
                    ->where('user_id', $transaction->user_id)
                    ->where('status', 'active')
                    ->where(function ($q) {
                        $q->whereNull('ends_at')
                            ->orWhere('ends_at', '>', now());
                    })
                    ->latest('starts_at')
                    ->lockForUpdate()
                    ->first();

                /**
                 * Carry-over logic.
                 */
                $carryOverPayments   = 0;
                $carryOverGroupsUsed = 0;

                if ($currentSubscription) {
                    $carryOverPayments   = $currentSubscription->carryOverPayments();
                    $carryOverGroupsUsed = $currentSubscription->carryOverGroupsUsed();
                }

                $overridePaymentLimit = null;

                if (
                    method_exists($package, 'isUnlimitedPayments')
                    && ! $package->isUnlimitedPayments()
                    && $carryOverPayments > 0
                ) {
                    $overridePaymentLimit = (int) $package->payment_limit + $carryOverPayments;
                }

                $startsAt = now();

                $endsAt = match ($package->billing_cycle) {
                    'weekly'   => now()->addWeek(),
                    'monthly'  => now()->addMonth(),
                    'yearly'   => now()->addYear(),
                    'lifetime' => null,
                    default    => now()->addMonth(),
                };

                /**
                 * Cancel old subscription.
                 */
                if ($currentSubscription) {
                    $currentSubscription->update([
                        'status' => 'cancelled',
                    ]);
                }

                /**
                 * Create new subscription.
                 * renewal_reminded_at starts NULL so the renewal reminder
                 * scheduler will remind again 3 days before this cycle ends.
                 */
                $subscription = UserSubscription::create([
                    'user_id'    => $transaction->user_id,
                    'package_id' => $transaction->package_id,

                    'override_payment_limit' => $overridePaymentLimit,
                    'override_group_limit'   => null,

                    'payment_used' => 0,
                    'group_used'   => $carryOverGroupsUsed,

                    'starts_at' => $startsAt,
                    'ends_at'   => $endsAt,

                    'renewal_reminded_at' => null,

                    'status'         => 'active',
                    'payment_method' => $transaction->payment_method,
                    'transaction_id' => $realTransactionId,
                ]);

                $transaction->update([
                    'subscription_id' => $subscription->userSubscriptionsID,
                    'status'          => 'paid',
                    'paid_at'         => now(),
                ]);

                SubscriptionUsageLog::create([
                    'subscription_id' => $subscription->userSubscriptionsID,
                    'user_id'         => $transaction->user_id,
                    'type'            => 'payment',
                    'action'          => 'package_payment_success',
                    'value'           => (int) round((float) $transaction->amount * 100),
                    'description'     => 'Package payment completed and subscription activated.',
                    'metadata' => [
                        'package_transaction_id'  => $transaction->packageTransactionsID,
                        'package_id'              => $transaction->package_id,
                        'package_name'            => $package->name,
                        'billing_cycle'           => $package->billing_cycle,
                        'payment_method'          => $transaction->payment_method,
                        'amount'                  => $transaction->amount,
                        'currency'                => $transaction->currency,
                        'external_transaction_id' => $transaction->external_transaction_id,
                        'md5'                     => $transaction->md5,
                        'bakong_result'           => $result,

                        'old_subscription_id'         => $currentSubscription?->userSubscriptionsID,
                        'carried_over_payments'       => $carryOverPayments,
                        'carried_over_groups_used'    => $carryOverGroupsUsed,
                        'override_payment_limit'      => $overridePaymentLimit,
                        'new_effective_payment_limit' => $subscription->effectivePaymentLimit(),
                    ],
                ]);

                Log::info('Package subscription activated', [
                    'user_id'                => $transaction->user_id,
                    'package_id'             => $transaction->package_id,
                    'subscription_id'        => $subscription->userSubscriptionsID,
                    'old_subscription_id'    => $currentSubscription?->userSubscriptionsID,
                    'carried_over_payments'  => $carryOverPayments,
                    'override_payment_limit' => $overridePaymentLimit,
                    'transaction_id'         => $realTransactionId,
                ]);

                return $subscription;
            });
        } catch (Throwable $e) {
            Log::error('Package subscription activation failed', [
                'package_transaction_id' => $transaction->packageTransactionsID,
                'error'                  => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'status'  => 'error',
                'message' => 'Payment found, but subscription activation failed.',
                'error'   => $e->getMessage(),
            ], 500);
        }

        /**
         * Refresh transaction after DB transaction.
         */
        $transaction->refresh();

        /**
         * Delete old Telegram confirmation message:
         * 🛒 បញ្ជាក់ការទិញ...
         */
        $this->deleteTelegramPaymentMessage($transaction, $telegram);

        /**
         * Send success message with Add Bot to Group button (ONCE).
         */
        if (
            $transaction->success_notified_at === null
            && ! empty($transaction->telegram_chat_id)
        ) {
            $subscriptionLink->sendPaymentSuccess(
                $subscription,
                $transaction->telegram_chat_id
            );

            $transaction->update(['success_notified_at' => now()]);
        }

        return response()->json([
            'success'         => true,
            'status'          => 'success',
            'message'         => 'Payment successful and subscription activated',
            'subscription_id' => $subscription->userSubscriptionsID,
            'data'            => $result,
        ]);
    }

    private function deleteTelegramPaymentMessage(
        ?PackageTransaction $transaction,
        TelegramBotService $telegram
    ): void {
        if (! $transaction) {
            return;
        }

        if (empty($transaction->telegram_chat_id) || empty($transaction->telegram_message_id)) {
            return;
        }

        try {
            $telegram->deleteMessage(
                $transaction->telegram_chat_id,
                (int) $transaction->telegram_message_id
            );

            Log::info('Telegram payment message deleted', [
                'package_transaction_id' => $transaction->packageTransactionsID,
                'chat_id'                => $transaction->telegram_chat_id,
                'message_id'             => $transaction->telegram_message_id,
            ]);

            // Message is gone — clear the id so repeat polls skip the API call.
            $transaction->forceFill(['telegram_message_id' => null])->save();
        } catch (Throwable $e) {
            Log::warning('Failed to delete Telegram payment message', [
                'package_transaction_id' => $transaction->packageTransactionsID,
                'chat_id'                => $transaction->telegram_chat_id,
                'message_id'             => $transaction->telegram_message_id,
                'error'                  => $e->getMessage(),
            ]);
        }
    }
}