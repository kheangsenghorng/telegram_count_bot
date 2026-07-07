<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class RecalculateSubscriptionPaymentUsageJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;

    public function __construct(
        public ?string $subscriptionId = null,
        public bool $includeInactive = false,
    ) {}

    public function handle(): void
    {
        $query = UserSubscription::query();

        if ($this->subscriptionId) {
            $query->where('userSubscriptionsID', $this->subscriptionId);
        } elseif (! $this->includeInactive) {
            // Only active subs by default — cancelled/expired quotas
            // are frozen history, no need to touch them.
            $query->where('status', 'active');
        }

        $query
            ->select(['userSubscriptionsID'])
            ->chunkById(
                100,
                function (Collection $subscriptions): void {
                    foreach ($subscriptions as $subscription) {
                        $this->recalculateOne($subscription->userSubscriptionsID);
                    }
                },
                column: 'userSubscriptionsID'
            );
    }

    private function recalculateOne(string $subscriptionId): void
    {
        DB::transaction(function () use ($subscriptionId): void {
            // Lock the row first, so a concurrent consumePayment()
            // increment can't slip between our count and our write.
            $subscription = UserSubscription::query()
                ->where('userSubscriptionsID', $subscriptionId)
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                return;
            }

            $realPaymentCount = TelegramPayment::query()
                ->where('subscription_id', $subscriptionId)
                ->where('status', 'success')
                ->where('parsed_successfully', true)
                ->where(function ($query) {
                    $query->where('is_duplicate', false)
                        ->orWhereNull('is_duplicate');
                })
                ->count();

            if ((int) $subscription->payment_used === $realPaymentCount) {
                return;
            }

            $old = (int) $subscription->payment_used;

            $subscription->forceFill([
                'payment_used' => $realPaymentCount,
            ])->save();

            Log::info('Subscription payment_used recalculated', [
                'subscription_id'  => $subscriptionId,
                'old_payment_used' => $old,
                'new_payment_used' => $realPaymentCount,
                'drift'            => $realPaymentCount - $old,
            ]);
        });
    }
}