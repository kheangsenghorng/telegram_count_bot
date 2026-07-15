<?php

declare(strict_types=1);

namespace App\Services;

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
     * This method is idempotent:
     * repeated callbacks return the existing subscription instead of
     * creating another subscription for the same transaction.
     */
    public function activateFromPackageTransaction(
        PackageTransaction $transaction
    ): UserSubscription {
        return DB::transaction(
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
                        ['paid', 'completed'],
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
                | Return already attached subscription
                |--------------------------------------------------------------------------
                */

                $attachedSubscription =
                    $this->findAttachedSubscription($transaction);

                if ($attachedSubscription) {
                    return $attachedSubscription;
                }

                /*
                |--------------------------------------------------------------------------
                | Prevent duplicate activation by transaction ID
                |--------------------------------------------------------------------------
                */

                $existingSubscription =
                    UserSubscription::query()
                        ->where(
                            'transaction_id',
                            (string) $transaction->getKey()
                        )
                        ->lockForUpdate()
                        ->first();

                if ($existingSubscription) {
                    $transaction->forceFill([
                        'subscription_id' =>
                            $existingSubscription->userSubscriptionsID,
                    ])->save();

                    return $existingSubscription;
                }

                /*
                |--------------------------------------------------------------------------
                | Resolve user and package
                |--------------------------------------------------------------------------
                */

                $user = $this->resolveUser($transaction);
                $package = $this->resolvePackage($transaction);

                /*
                |--------------------------------------------------------------------------
                | Find current active subscription
                |--------------------------------------------------------------------------
                */

                $currentSubscription =
                    $this->findCurrentSubscription(
                        (string) $user->uuid
                    );

                /*
                |--------------------------------------------------------------------------
                | Carry-over rules
                |--------------------------------------------------------------------------
                |
                | Payments:
                | Add remaining payments from the old subscription to the
                | new package limit.
                |
                | Groups:
                | Do not add remaining group quota. Keep the number of
                | groups already being used.
                |
                */

                $paymentCarryOver =
                    $currentSubscription?->carryOverPayments()
                    ?? 0;

                $groupsAlreadyUsed =
                    $currentSubscription?->carryOverGroupsUsed()
                    ?? 0;

                $overridePaymentLimit =
                    $this->calculatePaymentLimit(
                        package: $package,
                        carryOver: $paymentCarryOver,
                    );

                $this->ensureGroupLimitSupportsExistingGroups(
                    package: $package,
                    groupsAlreadyUsed: $groupsAlreadyUsed,
                );

                /*
                |--------------------------------------------------------------------------
                | Calculate subscription dates
                |--------------------------------------------------------------------------
                */

                $startsAt = now();

                $endsAt = $this->calculateEndDate(
                    startsAt: $startsAt,
                    billingCycle:
                        (string) $package->billing_cycle,
                );

                /*
                |--------------------------------------------------------------------------
                | Create subscription
                |--------------------------------------------------------------------------
                */

                $subscription =
                    UserSubscription::query()->create([
                        'user_id' =>
                            $user->uuid,

                        'package_id' =>
                            $package->packagesID,

                        'transaction_id' =>
                            (string) $transaction->getKey(),

                        'payment_method' =>
                            $transaction->payment_method
                            ?: 'aba_payway',

                        'status' => 'active',

                        'starts_at' => $startsAt,
                        'ends_at' => $endsAt,

                        /*
                         * The new subscription starts with no newly
                         * consumed payments. Remaining old payments are
                         * added to override_payment_limit instead.
                         */
                        'payment_used' => 0,

                        /*
                         * Existing groups remain counted.
                         */
                        'group_used' => $groupsAlreadyUsed,

                        /*
                         * Null means use the package's original limit.
                         *
                         * An override is only required when old remaining
                         * payment quota is carried forward.
                         */
                        'override_payment_limit' =>
                            $overridePaymentLimit,

                        /*
                         * Use the new package's normal group limit.
                         */
                        'override_group_limit' => null,

                        'renewal_reminded_at' => null,
                    ]);

                /*
                |--------------------------------------------------------------------------
                | Link package transaction to subscription
                |--------------------------------------------------------------------------
                */

                $transaction->forceFill([
                    'subscription_id' =>
                        $subscription->userSubscriptionsID,
                ])->save();

                /*
                |--------------------------------------------------------------------------
                | Deactivate previous subscription
                |--------------------------------------------------------------------------
                */

                if ($currentSubscription) {
                    $this->deactivatePreviousSubscription(
                        $currentSubscription
                    );
                }

                Log::info(
                    'Package subscription activated',
                    [
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

                        'group_used_carry_over' =>
                            $groupsAlreadyUsed,

                        'override_payment_limit' =>
                            $overridePaymentLimit,
                    ]
                );

                return $subscription;
            },
            attempts: 3
        );
    }

    /**
     * Find a subscription already linked to this transaction.
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
            ->where(
                'userSubscriptionsID',
                $subscriptionId
            )
            ->first();
    }

    /**
     * Resolve the transaction owner.
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
            ->where('uuid', $userUuid)
            ->first();

        if (! $user) {
            Log::error(
                'PayWay activation: user not found',
                [
                    'transaction' =>
                        $transaction->getKey(),

                    'user_id' => $userUuid,
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
     * Resolve the purchased package.
     *
     * Important:
     * PackageTransaction uses package_id.
     * Package uses packagesID.
     */
    private function resolvePackage(
        PackageTransaction $transaction
    ): Package {
        $packageId = trim(
            (string) $transaction->package_id
        );

        if ($packageId === '') {
            Log::error(
                'PayWay activation: package_id missing',
                [
                    'transaction' =>
                        $transaction->getKey(),

                    'package_id' =>
                        $transaction->package_id,
                ]
            );

            throw new RuntimeException(
                sprintf(
                    'Package ID is missing for transaction %s.',
                    $transaction->getKey()
                )
            );
        }

        $package = Package::query()
            ->where('packagesID', $packageId)
            ->first();

        if (! $package) {
            Log::error(
                'PayWay activation: package not found',
                [
                    'transaction' =>
                        $transaction->getKey(),

                    'package_id' => $packageId,
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
     * Find and lock the current active subscription.
     */
    private function findCurrentSubscription(
        string $userUuid
    ): ?UserSubscription {
        return UserSubscription::query()
            ->with('package')
            ->forUser($userUuid)
            ->active()
            ->latest('starts_at')
            ->lockForUpdate()
            ->first();
    }

    /**
     * Calculate the effective payment limit for the new subscription.
     *
     * Null means unlimited.
     *
     * When there is no carry-over, null is returned so the subscription
     * uses the package's normal payment_limit.
     */
    private function calculatePaymentLimit(
        Package $package,
        int $carryOver
    ): ?int {
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

        if ($carryOver <= 0) {
            /*
             * No override is needed. effectivePaymentLimit() will use
             * the package's payment_limit.
             */
            return null;
        }

        return max(
            0,
            (int) $package->payment_limit
        ) + $carryOver;
    }

    /**
     * Ensure the new package can support groups already connected.
     */
    private function ensureGroupLimitSupportsExistingGroups(
        Package $package,
        int $groupsAlreadyUsed
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

        $newGroupLimit = (int) $package->group_limit;

        if ($groupsAlreadyUsed > $newGroupLimit) {
            throw new RuntimeException(
                sprintf(
                    'The selected package allows %d groups, but the user already uses %d groups.',
                    $newGroupLimit,
                    $groupsAlreadyUsed
                )
            );
        }
    }

    /**
     * Calculate the subscription expiration date.
     *
     * Null means lifetime.
     */
    private function calculateEndDate(
        Carbon $startsAt,
        string $billingCycle
    ): ?Carbon {
        return match (
            strtolower(trim($billingCycle))
        ) {
            'daily' =>
                $startsAt->copy()->addDay(),

            'weekly' =>
                $startsAt->copy()->addWeek(),

            'monthly' =>
                $startsAt->copy()->addMonthNoOverflow(),

            'quarterly' =>
                $startsAt->copy()->addMonthsNoOverflow(3),

            'semiannual',
            'semi_annually',
            'half_year' =>
                $startsAt->copy()->addMonthsNoOverflow(6),

            'yearly',
            'annual' =>
                $startsAt->copy()->addYearNoOverflow(),

            'lifetime' => null,

            default => throw new RuntimeException(
                sprintf(
                    'Unsupported package billing cycle: %s',
                    $billingCycle
                )
            ),
        };
    }

    /**
     * Deactivate the previous active subscription after its remaining
     * payment quota and existing group usage have been transferred.`
     */
    private function deactivatePreviousSubscription(
        UserSubscription $subscription
    ): void {
        UserSubscription::query()
            ->whereKey($subscription->getKey())
            ->where('status', 'active')
            ->update([
                'status' => 'expired',
                'ends_at' => now(),
            ]);
    }
}

