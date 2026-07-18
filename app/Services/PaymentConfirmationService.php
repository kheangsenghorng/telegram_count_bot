<?php

declare(strict_types=1);

namespace App\Services;

use App\Http\Controllers\Api\Telegram\LimitHandler;
use App\Http\Controllers\Api\Telegram\PackageHandler;
use App\Models\Package;
use App\Models\PackageTransaction;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class PaymentConfirmationService
{
    /**
     * Activate a paid package transaction.
     *
     * Business rule:
     *
     * - One user keeps one UserSubscription row.
     * - Every purchase remains stored in PackageTransaction.
     * - Buying another package updates the existing subscription.
     * - Remaining payment quota is carried over when the old
     *   subscription is still active.
     * - The subscription ID never changes, so existing Telegram
     *   groups remain connected.
     */
    public function activateFromPackageTransaction(
        PackageTransaction $transaction
    ): UserSubscription {
        $subscription = DB::transaction(
            function () use ($transaction): UserSubscription {
                /*
                |--------------------------------------------------------------------------
                | Lock and reload transaction
                |--------------------------------------------------------------------------
                */
                $transaction = PackageTransaction::query()
                    ->whereKey($transaction->getKey())
                    ->lockForUpdate()
                    ->first();

                if (! $transaction) {
                    throw new RuntimeException(
                        'Package transaction not found.'
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Validate payment status
                |--------------------------------------------------------------------------
                */
                if (
                    ! in_array(
                        (string) $transaction->status,
                        [
                            'paid',
                            'completed',
                        ],
                        true
                    )
                ) {
                    throw new RuntimeException(
                        sprintf(
                            'Package transaction %s is not paid. Status: %s',
                            $transaction->getKey(),
                            (string) $transaction->status
                        )
                    );
                }

                /*
                |--------------------------------------------------------------------------
                | Idempotency
                |--------------------------------------------------------------------------
                |
                | If this transaction was already activated, simply return
                | the subscription linked to this transaction.
                |
                | This protects against duplicate PayWay callbacks.
                |
                */
                $attachedSubscription = $this
                    ->findAttachedSubscription(
                        $transaction
                    );

                if ($attachedSubscription) {
                    return $attachedSubscription;
                }

                /*
                |--------------------------------------------------------------------------
                | Resolve user and purchased package
                |--------------------------------------------------------------------------
                */
                $user = $this->resolveUser(
                    $transaction
                );

                $package = $this->resolvePackage(
                    $transaction
                );

                /*
                |--------------------------------------------------------------------------
                | Find the user's subscription row
                |--------------------------------------------------------------------------
                |
                | IMPORTANT:
                |
                | Do NOT filter only active subscriptions here.
                |
                | Even when the previous subscription is expired, we want
                | to reuse the same database row instead of creating
                | another UserSubscription record.
                |
                */
                $subscription = $this->findSubscriptionForUser(
                    (string) $user->uuid
                );

                /*
                |--------------------------------------------------------------------------
                | Calculate payment carry-over
                |--------------------------------------------------------------------------
                |
                | Only carry unused payments when the current subscription
                | is still active.
                |
                | Example:
                |
                | Current remaining: 7,000
                | New package limit:    500
                |
                | New total limit:    7,500
                |
                */
                $paymentCarryOver = 0;

                if (
                    $subscription
                    && $subscription->isActive()
                ) {
                    $paymentCarryOver =
                        $subscription->carryOverPayments();
                }

                /*
                |--------------------------------------------------------------------------
                | Keep existing group usage
                |--------------------------------------------------------------------------
                |
                | Because the subscription ID stays the same, existing
                | Telegram groups can continue using this subscription.
                |
                */
                $groupsAlreadyUsed = $subscription
                    ? $subscription->carryOverGroupsUsed()
                    : 0;

                /*
                |--------------------------------------------------------------------------
                | Calculate effective payment limit
                |--------------------------------------------------------------------------
                */
                $overridePaymentLimit =
                    $this->calculatePaymentLimit(
                        package: $package,
                        carryOver: $paymentCarryOver
                    );

                /*
                |--------------------------------------------------------------------------
                | Warn when existing groups exceed new package limit
                |--------------------------------------------------------------------------
                |
                | Do not fail activation after the customer has already paid.
                |
                | Example:
                |
                | Current connected groups = 10
                | New package limit        = 5
                |
                | The subscription is still activated, but no additional
                | groups should be allowed until usage is within the limit.
                |
                */
                $this->warnIfGroupLimitExceeded(
                    package: $package,
                    groupsAlreadyUsed: $groupsAlreadyUsed,
                    userUuid: (string) $user->uuid
                );

                /*
                |--------------------------------------------------------------------------
                | Calculate new subscription dates
                |--------------------------------------------------------------------------
                |
                | Every successful purchase starts a new package period.
                |
                */
                $startsAt = now();

                $endsAt = $this->calculateEndDate(
                    startsAt: $startsAt,
                    billingCycle: (string) $package->billing_cycle
                );

                /*
                |--------------------------------------------------------------------------
                | Create first subscription
                |--------------------------------------------------------------------------
                |
                | Only happens when the user has never had a subscription.
                |
                */
                if (! $subscription) {
                    $subscription = UserSubscription::query()
                        ->create([
                            'user_id' =>
                                $user->uuid,

                            'package_id' =>
                                $package->packagesID,

                            'transaction_id' =>
                                (string) $transaction->getKey(),

                            'payment_method' =>
                                $transaction->payment_method
                                ?: 'aba_payway',

                            'status' =>
                                'active',

                            'starts_at' =>
                                $startsAt,

                            'ends_at' =>
                                $endsAt,

                            'payment_used' =>
                                0,

                            'group_used' =>
                                0,

                            'override_payment_limit' =>
                                $overridePaymentLimit,

                            'override_group_limit' =>
                                null,

                            'renewal_reminded_at' =>
                                null,
                        ]);

                    $action = 'created';
                } else {
                    /*
                    |--------------------------------------------------------------------------
                    | Update existing subscription
                    |--------------------------------------------------------------------------
                    |
                    | No new UserSubscription record is created.
                    |
                    | userSubscriptionsID stays exactly the same.
                    |
                    */
                    $subscription->update([
                        'package_id' =>
                            $package->packagesID,

                        /*
                         * Keep only the latest purchase transaction here.
                         *
                         * Full purchase history remains available in the
                         * package_transactions table.
                         */
                        'transaction_id' =>
                            (string) $transaction->getKey(),

                        'payment_method' =>
                            $transaction->payment_method
                            ?: 'aba_payway',

                        'status' =>
                            'active',

                        'starts_at' =>
                            $startsAt,

                        'ends_at' =>
                            $endsAt,

                        /*
                         * Old remaining quota has already been included
                         * inside override_payment_limit.
                         *
                         * Therefore usage starts again from zero.
                         */
                        'payment_used' =>
                            0,

                        /*
                         * Existing groups stay counted.
                         */
                        'group_used' =>
                            $groupsAlreadyUsed,

                        'override_payment_limit' =>
                            $overridePaymentLimit,

                        /*
                         * Use the newly purchased package's group limit.
                         */
                        'override_group_limit' =>
                            null,

                        /*
                         * Allow the new subscription period to receive
                         * another renewal reminder.
                         */
                        'renewal_reminded_at' =>
                            null,
                    ]);

                    $action = 'updated';
                }

                /*
                |--------------------------------------------------------------------------
                | Link this purchase transaction to the subscription
                |--------------------------------------------------------------------------
                |
                | Example:
                |
                | Transaction 1 ─┐
                | Transaction 2 ─┼──> Same UserSubscription
                | Transaction 3 ─┘
                |
                | This keeps every purchase in package_transactions while
                | maintaining only one current subscription row per user.
                |
                */
                $transaction->forceFill([
                    'subscription_id' =>
                        $subscription->userSubscriptionsID,
                ])->save();

                /*
                |--------------------------------------------------------------------------
                | Reload subscription with the newly purchased package
                |--------------------------------------------------------------------------
                */
                $subscription = $subscription
                    ->refresh()
                    ->load('package');

                /*
                |--------------------------------------------------------------------------
                | Log activation
                |--------------------------------------------------------------------------
                */
                Log::info(
                    'Package subscription activated',
                    [
                        'action' =>
                            $action,

                        'package_transaction_id' =>
                            $transaction->getKey(),

                        'subscription_id' =>
                            $subscription->userSubscriptionsID,

                        'user_id' =>
                            $user->uuid,

                        'package_id' =>
                            $package->packagesID,

                        'payment_carry_over' =>
                            $paymentCarryOver,

                        'group_used' =>
                            $groupsAlreadyUsed,

                        'override_payment_limit' =>
                            $overridePaymentLimit,

                        'starts_at' =>
                            $startsAt,

                        'ends_at' =>
                            $endsAt,
                    ]
                );

                return $subscription;
            },
            attempts: 3
        );

        /*
        |--------------------------------------------------------------------------
        | Clear caches after database commit
        |--------------------------------------------------------------------------
        */
        $userUuid = (string) $subscription->user_id;

        if ($userUuid !== '') {
            LimitHandler::invalidateForUser(
                $userUuid
            );

            PackageHandler::invalidateSubscription(
                $userUuid
            );
        }

        return $subscription;
    }

    /**
     * Find subscription already attached to this payment transaction.
     *
     * Used for duplicate callback protection.
     */
    private function findAttachedSubscription(
        PackageTransaction $transaction
    ): ?UserSubscription {
        $subscriptionId = trim(
            (string) $transaction->subscription_id
        );

        if ($subscriptionId === '') {
            return null;
        }

        return UserSubscription::query()
            ->with('package')
            ->where(
                'userSubscriptionsID',
                $subscriptionId
            )
            ->first();
    }

    /**
     * Find the user's single subscription row.
     *
     * We intentionally do not use scopeActive().
     *
     * An expired subscription row should also be reused when
     * the same user purchases another package.
     */
    private function findSubscriptionForUser(
        string $userUuid
    ): ?UserSubscription {
        return UserSubscription::query()
            ->with('package')
            ->forUser($userUuid)
            ->latest('starts_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Resolve transaction owner.
     */
    private function resolveUser(
        PackageTransaction $transaction
    ): User {
        $userUuid = trim(
            (string) $transaction->user_id
        );

        if ($userUuid === '') {
            throw new RuntimeException(
                sprintf(
                    'User ID is missing for transaction %s.',
                    $transaction->getKey()
                )
            );
        }

        $user = User::query()
            ->where(
                'uuid',
                $userUuid
            )
            ->first();

        if (! $user) {
            Log::error(
                'Package activation: user not found',
                [
                    'package_transaction_id' =>
                        $transaction->getKey(),

                    'user_id' =>
                        $userUuid,
                ]
            );

            throw new RuntimeException(
                sprintf(
                    'User not found for transaction %s.',
                    $transaction->getKey()
                )
            );
        }

        return $user;
    }

    /**
     * Resolve purchased package.
     */
    private function resolvePackage(
        PackageTransaction $transaction
    ): Package {
        $packageId = trim(
            (string) $transaction->package_id
        );

        if ($packageId === '') {
            throw new RuntimeException(
                sprintf(
                    'Package ID is missing for transaction %s.',
                    $transaction->getKey()
                )
            );
        }

        $package = Package::query()
            ->where(
                'packagesID',
                $packageId
            )
            ->first();

        if (! $package) {
            Log::error(
                'Package activation: package not found',
                [
                    'package_transaction_id' =>
                        $transaction->getKey(),

                    'package_id' =>
                        $packageId,
                ]
            );

            throw new RuntimeException(
                sprintf(
                    'Package %s was not found for transaction %s.',
                    $packageId,
                    $transaction->getKey()
                )
            );
        }

        return $package;
    }

    /**
     * Calculate new effective payment limit.
     *
     * NULL means use the package's own payment limit,
     * including unlimited packages.
     *
     * Example:
     *
     * Package limit = 500
     * Carry-over    = 7,000
     *
     * Override      = 7,500
     */
    private function calculatePaymentLimit(
        Package $package,
        int $carryOver
    ): ?int {
        /*
        |--------------------------------------------------------------------------
        | Unlimited package
        |--------------------------------------------------------------------------
        */
        if (
            method_exists(
                $package,
                'isUnlimitedPayments'
            )
            && $package->isUnlimitedPayments()
        ) {
            return null;
        }

        if ($package->payment_limit === null) {
            return null;
        }

        /*
        |--------------------------------------------------------------------------
        | No carry-over
        |--------------------------------------------------------------------------
        |
        | No override is needed.
        |
        | effectivePaymentLimit() will read the limit directly from Package.
        |
        */
        if ($carryOver <= 0) {
            return null;
        }

        return max(
            0,
            (int) $package->payment_limit
        ) + $carryOver;
    }

    /**
     * Warn when a user already uses more groups than the newly
     * purchased package allows.
     *
     * We do not throw an exception because the payment is already complete.
     */
    private function warnIfGroupLimitExceeded(
        Package $package,
        int $groupsAlreadyUsed,
        string $userUuid
    ): void {
        if ($groupsAlreadyUsed <= 0) {
            return;
        }

        if (
            method_exists(
                $package,
                'isUnlimitedGroups'
            )
            && $package->isUnlimitedGroups()
        ) {
            return;
        }

        if ($package->group_limit === null) {
            return;
        }

        $newGroupLimit = max(
            0,
            (int) $package->group_limit
        );

        if ($groupsAlreadyUsed <= $newGroupLimit) {
            return;
        }

        Log::warning(
            'User subscription activated above new package group limit',
            [
                'user_id' =>
                    $userUuid,

                'package_id' =>
                    $package->packagesID,

                'package_group_limit' =>
                    $newGroupLimit,

                'groups_already_used' =>
                    $groupsAlreadyUsed,
            ]
        );
    }

    /**
     * Calculate subscription expiration.
     *
     * NULL means lifetime.
     */
    private function calculateEndDate(
        Carbon $startsAt,
        string $billingCycle
    ): ?Carbon {
        return match (
            strtolower(
                trim($billingCycle)
            )
        ) {
            'daily' =>
                $startsAt
                    ->copy()
                    ->addDay(),

            'weekly' =>
                $startsAt
                    ->copy()
                    ->addWeek(),

            'monthly' =>
                $startsAt
                    ->copy()
                    ->addMonthNoOverflow(),

            'quarterly' =>
                $startsAt
                    ->copy()
                    ->addMonthsNoOverflow(3),

            'semiannual',
            'semi_annually',
            'half_year' =>
                $startsAt
                    ->copy()
                    ->addMonthsNoOverflow(6),

            'yearly',
            'annual' =>
                $startsAt
                    ->copy()
                    ->addYearNoOverflow(),

            'lifetime' =>
                null,

            default =>
                throw new RuntimeException(
                    sprintf(
                        'Unsupported package billing cycle: %s',
                        $billingCycle
                    )
                ),
        };
    }
}

