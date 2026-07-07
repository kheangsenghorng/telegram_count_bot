<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Jobs\RecalculateSubscriptionPaymentUsageJob;
use Illuminate\Console\Command;

class RecalculatePaymentUsageCommand extends Command
{
    protected $signature = 'payments:recalculate-usage
                            {subscription_id? : Optional subscription ID}
                            {--all : Include cancelled/expired subscriptions}';

    protected $description = 'Recalculate subscription payment_used from saved Telegram payments';

    public function handle(): int
    {
        $subscriptionId = $this->argument('subscription_id');

        RecalculateSubscriptionPaymentUsageJob::dispatch(
            $subscriptionId ? (string) $subscriptionId : null,
            includeInactive: (bool) $this->option('all'),
        );

        $this->info('✅ Payment usage recalculation job dispatched.');

        $this->line($subscriptionId
            ? "Subscription: {$subscriptionId}"
            : ($this->option('all')
                ? 'Mode: ALL subscriptions (including inactive)'
                : 'Mode: active subscriptions'));

        return self::SUCCESS;
    }
}