<?php

namespace App\Console\Commands;

use App\Jobs\SendRenewalReminderJob;
use App\Models\UserSubscription;
use Illuminate\Console\Command;

class SendRenewalReminders extends Command
{
    protected $signature = 'subscriptions:send-renewal-reminders';

    protected $description = 'Send one renewal reminder per subscription before it expires';

    public function handle(): int
    {
        UserSubscription::query()
            ->where('status', 'active')
            ->whereNotNull('ends_at')
            ->whereBetween('ends_at', [now(), now()->addDays(3)]) // ← ADJUST: how early to remind
            ->whereNull('renewal_reminded_at')                    // ← the "only once" guard
            ->select('userSubscriptionsID')
            ->chunkById(100, function ($subs) {
                foreach ($subs as $sub) {
                    SendRenewalReminderJob::dispatch($sub->userSubscriptionsID);
                }
            }, 'userSubscriptionsID');

        return self::SUCCESS;
    }
}