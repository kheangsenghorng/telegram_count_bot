<?php

use App\Jobs\RecalculateSubscriptionPaymentUsageJob;
use App\Jobs\SendStatsSummaryJob;
use App\Models\PackageTransaction;
use App\Models\TelegramPayment;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use App\Services\TelegramBotService;
use Illuminate\Support\Facades\Log;

Artisan::command('telegram:test-schedule', function (TelegramBotService $telegram) {
    $chatId = env('TELEGRAM_TEST_CHAT_ID');

    if (! $chatId) {
        Log::warning('Telegram test schedule failed: TELEGRAM_TEST_CHAT_ID is missing');
        $this->error('TELEGRAM_TEST_CHAT_ID is missing');
        return self::FAILURE;
    }

    $telegram->sendMessage($chatId, 'welcome to bot telegram');

    Log::info('Telegram test schedule message sent', [
        'chat_id' => $chatId,
        'message' => 'welcome to bot telegram',
    ]);

    $this->info('Telegram test schedule message sent.');

    return self::SUCCESS;
})->purpose('Send test Telegram schedule message');

// Test Telegram schedule message — daily at 8:30 PM Cambodia time
Schedule::command('telegram:test-schedule')
    ->name('telegram-test-schedule')
    ->dailyAt('20:30')
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();


/*
|--------------------------------------------------------------------------
| Schedule Timezone
|--------------------------------------------------------------------------
*/
$timezone = 'Asia/Phnom_Penh';

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
    ->dailyAt(env('RENEWAL_REMINDER_TIME', '09:00'))
    ->timezone('Asia/Phnom_Penh')
    ->withoutOverlapping()
    ->onOneServer();