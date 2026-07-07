<?php

namespace App\Console\Commands;

use App\Jobs\SendRenewalReminderJob;
use App\Models\UserSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class SendRenewalReminders extends Command
{
    protected $signature = 'subscriptions:send-renewal-reminders';

    protected $description = 'Send one renewal reminder per subscription before it expires';

    public function handle(): int
    {
        $total = 0;
    
        UserSubscription::query()
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays(3)])
            ->whereNull('renewal_reminded_at')
            ->select('userSubscriptionsID')
            ->chunkById(100, function ($subs) use (&$total) {
                foreach ($subs as $sub) {
                    $total++;
    
                    SendRenewalReminderJob::dispatchSync($sub->userSubscriptionsID);
                }
            }, 'userSubscriptionsID');
    
        $this->info("Renewal reminder sent/dispatched: {$total}");
    
        return self::SUCCESS;
    }
}