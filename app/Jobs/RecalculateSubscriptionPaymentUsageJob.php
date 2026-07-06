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

    public function __construct(
        public ?string $subscriptionId = null,
    ) {}

    public function handle(): void
    {
        $query = UserSubscription::query();

        if ($this->subscriptionId) {
            $query->where('userSubscriptionsID', $this->subscriptionId);
        }

        $query
            ->select([
                'userSubscriptionsID',
                'user_id',
                'payment_used',
                'status',
            ])
            ->chunkById(
                100,
                function (Collection $subscriptions): void {
                    foreach ($subscriptions as $subscription) {
                        $this->recalculateOne($subscription);
                    }
                },
                column: 'userSubscriptionsID'
            );
    }

    private function recalculateOne(UserSubscription $subscription): void
    {
        $realPaymentCount = TelegramPayment::query()
            ->where('subscription_id', $subscription->userSubscriptionsID)
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

        DB::transaction(function () use ($subscription, $realPaymentCount): void {
            UserSubscription::query()
                ->where('userSubscriptionsID', $subscription->userSubscriptionsID)
                ->update([
                    'payment_used' => $realPaymentCount,
                    'updated_at' => now(),
                ]);
        });

        Log::info('Subscription payment_used recalculated', [
            'subscription_id' => $subscription->userSubscriptionsID,
            'old_payment_used' => $subscription->payment_used,
            'new_payment_used' => $realPaymentCount,
        ]);
    }
}