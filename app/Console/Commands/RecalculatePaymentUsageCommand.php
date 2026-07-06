<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecalculateSubscriptionPaymentUsageJob;
use Illuminate\Console\Command;

class RecalculatePaymentUsageCommand extends Command
{
    protected $signature = 'payments:recalculate-usage 
                            {subscription_id? : Optional subscription ID}';

    protected $description = 'Recalculate subscription payment_used from saved Telegram payments';

    public function handle(): int
    {
        $subscriptionId = $this->argument('subscription_id');

        RecalculateSubscriptionPaymentUsageJob::dispatch(
            $subscriptionId ? (string) $subscriptionId : null
        );

        $this->info('✅ Payment usage recalculation job dispatched.');

        if ($subscriptionId) {
            $this->line("Subscription: {$subscriptionId}");
        } else {
            $this->line('Mode: all subscriptions');
        }

        return self::SUCCESS;
    }
}