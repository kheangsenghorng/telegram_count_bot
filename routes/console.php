<?php

use App\Jobs\RecalculateSubscriptionPaymentUsageJob;
use App\Jobs\SendStatsSummaryJob;
use App\Models\PackageTransaction;
use App\Models\TelegramPayment;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule Timezone
|--------------------------------------------------------------------------
*/

$timezone = 'Asia/Phnom_Penh';

/*
|--------------------------------------------------------------------------
| Telegram test schedule
|--------------------------------------------------------------------------
| Sends a test Telegram message every day at 8:30 PM Cambodia time.
*/

Artisan::command('telegram:test-schedule', function (TelegramBotService $telegram) {
    $chatId = config('services.telegram.test_chat_id');

    if (! $chatId) {
        Log::warning('Telegram test schedule failed: TELEGRAM_TEST_CHAT_ID is missing');

        $this->error('TELEGRAM_TEST_CHAT_ID is missing');

        return self::FAILURE;
    }

    $message = 'welcome to bot telegram';

    $telegram->sendMessage($chatId, $message);

    Log::info('Telegram test schedule message sent', [
        'chat_id' => $chatId,
        'message' => $message,
    ]);

    $this->info('Telegram test schedule message sent.');

    return self::SUCCESS;
})->purpose('Send test Telegram schedule message');

Schedule::command('telegram:test-schedule')
    ->name('telegram-test-schedule')
    ->dailyAt('20:30')
    ->timezone($timezone)
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Stats summaries
|--------------------------------------------------------------------------
| Production:
| - Day: every day at 10:00 PM
| - Week: every Monday at 10:00 PM
| - Month: every 1st day at 10:00 PM
*/

Schedule::job(new SendStatsSummaryJob('day'))
    ->name('stats-summary-day')
    ->dailyAt('22:00')
    ->timezone($timezone)
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new SendStatsSummaryJob('week'))
    ->name('stats-summary-week')
    ->weeklyOn(1, '22:00')
    ->timezone($timezone)
    ->withoutOverlapping()
    ->onOneServer();

Schedule::job(new SendStatsSummaryJob('month'))
    ->name('stats-summary-month')
    ->monthlyOn(1, '22:00')
    ->timezone($timezone)
    ->withoutOverlapping()
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Cleanup stale transactions
|--------------------------------------------------------------------------
*/

Schedule::command('model:prune', [
    '--model' => [
        PackageTransaction::class,
        TelegramPayment::class,
    ],
])
    ->name('prune-stale-transactions')
    ->dailyAt('00:00')
    ->timezone($timezone)
    ->withoutOverlapping()
    ->onOneServer()
    ->appendOutputTo(storage_path('logs/model-prune.log'));

/*
|--------------------------------------------------------------------------
| Data integrity
|--------------------------------------------------------------------------
| Recalculate payment_used from real TelegramPayment rows every hour.
| Enable this only when you want hourly recalculation.
*/

// Schedule::job(new RecalculateSubscriptionPaymentUsageJob())
//     ->name('recalculate-payment-usage')
//     ->hourly()
//     ->timezone($timezone)
//     ->withoutOverlapping()
//     ->onOneServer();

/*
|--------------------------------------------------------------------------
| Renewal reminders
|--------------------------------------------------------------------------
| Production default: 9:00 AM Cambodia time.
| For local testing, change RENEWAL_REMINDER_TIME in .env.
*/

Schedule::command('subscriptions:send-renewal-reminders')
    ->name('send-renewal-reminders')
    ->dailyAt(config('services.telegram.renewal_reminder_time', '09:00'))
    ->timezone($timezone)
    ->withoutOverlapping()
    ->onOneServer();