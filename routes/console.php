<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Jobs\SendStatsSummaryJob;
use App\Models\PackageTransaction;
use App\Models\TelegramPayment;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');


// ថ្ងៃ — daily summary at 10:00 PM Cambodia time
Schedule::job(new SendStatsSummaryJob('day'))
    ->dailyAt('22:00')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();


// សប្តាហ៍ — weekly summary, Monday at 10:00 PM Cambodia time
Schedule::job(new SendStatsSummaryJob('week'))
    ->weeklyOn(1, '22:00')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();


// ខែ — monthly summary, 1st day of month at 10:00 PM Cambodia time
Schedule::job(new SendStatsSummaryJob('month'))
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
    ->dailyAt('02:00')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();