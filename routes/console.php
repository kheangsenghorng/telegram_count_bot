<?php

use App\Jobs\RecalculateSubscriptionPaymentUsageJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SendStatsSummaryJob;
use App\Models\PackageTransaction;
use App\Models\TelegramPayment;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// ថ្ងៃ — daily summary at 3:30 PM Cambodia time (TESTING — change to 22:00 for production)
Schedule::job(new SendStatsSummaryJob('day'))
    ->name('stats-summary-day')
    ->dailyAt('15:20')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();


// សប្តាហ៍ — weekly summary, Monday at 3:30 PM Cambodia time (TESTING — change to 22:00)
Schedule::job(new SendStatsSummaryJob('week'))
    ->name('stats-summary-week')
    ->weeklyOn(1, '22:00')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();


// ខែ — monthly summary, 1st day of month at 3:30 PM Cambodia time (TESTING — change to 22:00)
Schedule::job(new SendStatsSummaryJob('month'))
    ->name('stats-summary-month')
    ->monthlyOn(1, '22:00')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();


// Auto delete pending transactions older than 7 days
Schedule::command('model:prune', [
    '--model' => [
        PackageTransaction::class,
        TelegramPayment::class,
    ],
])
    ->name('prune-stale-transactions')
    ->dailyAt('00:00')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();


Schedule::job(new RecalculateSubscriptionPaymentUsageJob())
->name('recalculate-payment-usage')
->hourly()
->withoutOverlapping()
->onOneServer(); 

Schedule::command('subscriptions:send-renewal-reminders')
    ->hourly()
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();