<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Package;
use App\Models\User;
use App\Models\UserSubscription;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * PaymentConfirmationService
 *
 * Called by CheckKhqrPaymentStatusJob when the Bakong MD5 check
 * reports a checkout as PAID.
 *
 * Flow:
 *   1. confirm($checkout)           ← entry point
 *   2. resolveUser()                ← telegram_user_id → users.uuid
 *   3. activatePackage()            ← carry-over quota logic
 *   4. buildActivationMessage()     ← Khmer receipt
 *   5. TelegramBotService::sendMessage()
 *
 * NOTE: $checkout is the model created by BakongService::createCheckout().
 * If your model/columns are named differently, adjust ONLY the parts
 * marked with // ← ADJUST.
 */
class PaymentConfirmationService
{
    public function __construct(
        protected TelegramBotService $telegram,
    ) {}

    /**
     * Entry point — call this from CheckKhqrPaymentStatusJob
     * after the MD5 status check returns success.
     */
    public function confirm(object $checkout): ?UserSubscription
    {
        // ── Guard: already confirmed? ────────────────────────────────────
        if ($checkout->status === 'paid' && $checkout->confirmed_at !== null) {  // ← ADJUST column names
            Log::info('PaymentConfirmation: checkout already confirmed', [
                'checkout_id' => $checkout->getKey(),
            ]);

            return null;
        }

        // ── Resolve package + user ───────────────────────────────────────
        $package = Package::where('packagesID', $checkout->package_id)->first(); // ← ADJUST

        if (! $package) {
            Log::error('PaymentConfirmation: package not found', [
                'checkout_id' => $checkout->getKey(),
                'package_id'  => $checkout->package_id,
            ]);

            return null;
        }

        $user = $this->resolveUser((int) $checkout->telegram_user_id); // ← ADJUST

        if (! $user) {
            Log::error('PaymentConfirmation: user not found for telegram id', [
                'checkout_id'      => $checkout->getKey(),
                'telegram_user_id' => $checkout->telegram_user_id,
            ]);

            return null;
        }

        // ── Mark checkout paid + activate in one transaction ────────────
        $subscription = DB::transaction(function () use ($checkout, $package, $user) {
            $checkout->forceFill([                    // ← ADJUST column names
                'status'       => 'paid',
                'confirmed_at' => now(),
            ])->save();

            return $this->activatePackage(
                payment: $checkout,
                package: $package,
                userUuid: (string) $user->uuid,
            );
        });

        // ── Send Khmer receipt to the buyer's private chat ───────────────
        $this->telegram->sendMessage(
            (string) $checkout->telegram_user_id, // ← ADJUST
            $this->buildActivationMessage($subscription, $package),
            ['parse_mode' => 'Markdown']
        );

        return $subscription;
    }

    /**
     * Map a Telegram user ID to the local users row.
     * Creates the user if they don't exist yet.
     */
    protected function resolveUser(int $telegramUserId): ?User
    {
        $user = User::where('telegram_id', $telegramUserId)->first(); // ← ADJUST column name

        if ($user) {
            return $user;
        }

        // Buyer paid but has no users row yet — create a minimal one
        // so activation never fails after money was received.
        return User::create([
            'uuid'        => (string) Str::uuid(),   // ← ADJUST if HasUuids handles this
            'telegram_id' => $telegramUserId,
            'name'        => 'Telegram User ' . $telegramUserId,
        ]);
    }

    /**
     * Activate a package for the user, carrying over any remaining
     * payment quota from their current active plan.
     *
     * Example:
     *   Old plan:  effective limit 1500, payment_used 1400 → remaining 100
     *   New plan:  payment_limit 4000
     *   Result:    override_payment_limit = 4000 + 100 = 4100
     */
    protected function activatePackage(object $payment, Package $package, string $userUuid): UserSubscription
    {
        $transactionId = (string) ($payment->order_ref
            ?? $payment->external_transaction_id
            ?? $payment->getKey()); // ← ADJUST to your unique transaction column

        return DB::transaction(function () use ($payment, $package, $userUuid, $transactionId) {

            // 1. Idempotency — same transaction never activates twice
            //    (the polling job fires every 5 seconds).
            $existing = UserSubscription::query()
                ->where('transaction_id', $transactionId)
                ->first();

            if ($existing) {
                Log::info('activatePackage: transaction already activated, skipping', [
                    'transaction_id'  => $transactionId,
                    'subscription_id' => $existing->userSubscriptionsID,
                ]);

                return $existing;
            }

            // 2. Current active subscription for the SAME user
            $current = UserSubscription::query()
                ->where('user_id', $userUuid)
                ->where('status', 'active')
                ->where(function ($q) {
                    $q->whereNull('ends_at')
                        ->orWhere('ends_at', '>', now());
                })
                ->latest('starts_at')
                ->lockForUpdate()
                ->first();

            // 3. Carry-over
            $carryOver = 0;
            $groupUsed = 0;

            if ($current) {
                $oldLimit = $current->effectivePaymentLimit();

                if ($oldLimit !== null) {
                    $carryOver = max(0, $oldLimit - $current->payment_used);
                }

                // User's registered groups still exist after upgrade
                $groupUsed = (int) $current->group_used;
            }

            $overridePaymentLimit = null;

            if (! $package->isUnlimitedPayments() && $carryOver > 0) {
                $overridePaymentLimit = (int) $package->payment_limit + $carryOver;
            }

            // 4. Expiry from now (Asia/Phnom_Penh via config/app.php)
            $now = Carbon::now();

            $endsAt = match ($package->billing_cycle) {
                'monthly'  => $now->copy()->addMonth(),
                'yearly'   => $now->copy()->addYear(),
                'lifetime' => null,
                default    => $now->copy()->addMonth(),
            };

            // 5. Retire the old subscription
            if ($current) {
                $current->update(['status' => 'cancelled']);
            }

            // 6. Create the new subscription with carried-over quota
            $subscription = UserSubscription::create([
                'user_id'                => $userUuid,
                'package_id'             => $package->packagesID,
                'subscription_key'       => $this->generateSubscriptionKey(),
                'override_payment_limit' => $overridePaymentLimit,
                'override_group_limit'   => null, // package default
                'payment_used'           => 0,
                'group_used'             => $groupUsed,
                'starts_at'              => $now,
                'ends_at'                => $endsAt,
                'status'                 => 'active',
                'payment_method'         => 'khqr',
                'transaction_id'         => $transactionId,
            ]);

            Log::info('activatePackage: subscription activated', [
                'user_id'        => $userUuid,
                'package_id'     => $package->packagesID,
                'carried_over'   => $carryOver,
                'payment_limit'  => $subscription->effectivePaymentLimit() ?? 'unlimited',
                'ends_at'        => $endsAt?->toDateTimeString() ?? 'lifetime',
                'transaction_id' => $transactionId,
            ]);

            return $subscription;
        });
    }

    /**
     * Unique human-readable subscription key, e.g. SUB-20260702-8F3KQ2.
     */
    protected function generateSubscriptionKey(): string
    {
        do {
            $key = 'SUB-' . now()->format('Ymd') . '-' . strtoupper(Str::random(6));
        } while (UserSubscription::where('subscription_key', $key)->exists());

        return $key;
    }

    /**
     * Khmer receipt showing the carry-over math.
     */
    protected function buildActivationMessage(UserSubscription $sub, Package $package): string
    {
        $effectiveLimit = $sub->effectivePaymentLimit();

        $totalLabel = $effectiveLimit === null
            ? '∞'
            : \App\Helpers\KhmerDateFormatter::formatNumber($effectiveLimit);

        $baseLabel = $package->isUnlimitedPayments()
            ? '∞'
            : \App\Helpers\KhmerDateFormatter::formatNumber((int) $package->payment_limit);

        $carryOver = 0;

        if ($effectiveLimit !== null && ! $package->isUnlimitedPayments()) {
            $carryOver = max(0, $effectiveLimit - (int) $package->payment_limit);
        }

        $lines = [
            '✅ *ការទូទាត់ជោគជ័យ!*',
            '─────────────────────',
            "📦 កញ្ចប់: *{$package->name}*",
            "🔑 លេខសមាជិក: `{$sub->subscription_key}`",
            "💳 ការទូទាត់ក្នុងកញ្ចប់: {$baseLabel}",
        ];

        if ($carryOver > 0) {
            $carryLabel = \App\Helpers\KhmerDateFormatter::formatNumber($carryOver);
            $lines[] = "➕ នៅសល់ពីកញ្ចប់ចាស់: {$carryLabel}";
        }

        $lines[] = "🧮 សរុបអាចប្រើបាន: *{$totalLabel}*";

        $lines[] = $sub->isLifetime()
            ? '📅 សុពលភាព: អចិន្ត្រៃយ៍'
            : '📅 ផុតកំណត់: ' . \App\Helpers\KhmerDateFormatter::formatDate($sub->ends_at);

        return implode("\n", $lines);
    }
}