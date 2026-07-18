<?php

declare(strict_types=1);

use App\Jobs\QueueHeartbeatJob;
use App\Jobs\SendStatsSummaryJob;
use App\Models\PackageTransaction;
use App\Models\TelegramPayment;
use App\Services\TelegramBotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
|--------------------------------------------------------------------------
| Schedule Timezone
|--------------------------------------------------------------------------
|
| All scheduled tasks use the application timezone.
|
| Recommended .env:
|
| APP_TIMEZONE=Asia/Phnom_Penh
|
*/

$timezone = config('app.timezone', 'Asia/Phnom_Penh');

/*
|--------------------------------------------------------------------------
| System Heartbeats
|--------------------------------------------------------------------------
|
| These heartbeats are used by the admin system health dashboard to verify
| that the Laravel scheduler and queue worker are running normally.
|
*/

Schedule::call(function (): void {
    Cache::put(
        'heartbeat:scheduler',
        now()->timestamp,
        now()->addMinutes(5)
    );
})
    ->name('scheduler-heartbeat')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

Schedule::job(new QueueHeartbeatJob())
    ->name('queue-heartbeat')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Telegram Group Status Refresh
|--------------------------------------------------------------------------
|
| Synchronize Telegram group connection and status information.
|
*/

Schedule::command('telegram:refresh-group-statuses')
    ->name('telegram-refresh-group-statuses')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Inspire Command
|--------------------------------------------------------------------------
*/

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Telegram Test Command
|--------------------------------------------------------------------------
|
| This command can be used to confirm that scheduled Telegram messages
| are working correctly.
|
*/

Artisan::command(
    'telegram:test-schedule',
    function (TelegramBotService $telegram): int {
        $chatId = config('services.telegram.test_chat_id');

        if (! $chatId) {
            Log::warning(
                'Telegram test schedule failed: TELEGRAM_TEST_CHAT_ID is missing'
            );

            $this->error(
                'TELEGRAM_TEST_CHAT_ID is missing'
            );

            return self::FAILURE;
        }

        $message = 'Welcome to Telegram Bot';

        try {
            $telegram->sendMessage(
                (string) $chatId,
                $message
            );

            Log::info(
                'Telegram test schedule message sent',
                [
                    'chat_id' => $chatId,
                ]
            );

            $this->info(
                'Telegram test schedule message sent.'
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            Log::error(
                'Telegram test schedule failed',
                [
                    'chat_id' => $chatId,
                    'error' => $exception->getMessage(),
                ]
            );

            $this->error(
                'Failed to send Telegram message.'
            );

            return self::FAILURE;
        }
    }
)->purpose(
    'Send a scheduled Telegram test message'
);

/*
|--------------------------------------------------------------------------
| Telegram Test Schedule
|--------------------------------------------------------------------------
|
| Sends a test Telegram message every day at 8:30 PM Cambodia time.
|
| You may remove this schedule in production after testing is complete.
|
*/

Schedule::command('telegram:test-schedule')
    ->name('telegram-test-schedule')
    ->dailyAt('20:30')
    ->timezone($timezone)
    ->withoutOverlapping(10)
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Statistics Summary Schedule
|--------------------------------------------------------------------------
|
| Daily   : Every day at 11:54 PM
| Weekly  : Every Monday at 10:00 PM
| Monthly : First day of every month at 10:00 PM
|
*/

Schedule::job(
    new SendStatsSummaryJob('day')
)
    ->name('stats-summary-day')
    ->dailyAt('23:54')
    ->timezone($timezone)
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::job(
    new SendStatsSummaryJob('week')
)
    ->name('stats-summary-week')
    ->weeklyOn(1, '22:00')
    ->timezone($timezone)
    ->withoutOverlapping(30)
    ->onOneServer();

Schedule::job(
    new SendStatsSummaryJob('month')
)
    ->name('stats-summary-month')
    ->monthlyOn(1, '22:00')
    ->timezone($timezone)
    ->withoutOverlapping(30)
    ->onOneServer();

/*
|--------------------------------------------------------------------------
| Cleanup Stale Transactions
|--------------------------------------------------------------------------
|
| Prune old records from models that use Laravel's Prunable or
| MassPrunable traits.
|
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
    ->withoutOverlapping(60)
    ->onOneServer()
    ->appendOutputTo(
        storage_path('logs/model-prune.log')
    );

/*
|--------------------------------------------------------------------------
| Subscription Payment Usage Recalculation
|--------------------------------------------------------------------------
|
| Recalculate payment_used from real TelegramPayment records.
|
| Enable only when periodic reconciliation is required.
|
*/

// Schedule::job(
//     new RecalculateSubscriptionPaymentUsageJob()
// )
//     ->name('recalculate-payment-usage')
//     ->hourly()
//     ->timezone($timezone)
//     ->withoutOverlapping(30)
//     ->onOneServer();

/*
|--------------------------------------------------------------------------
| Subscription Renewal Reminders
|--------------------------------------------------------------------------
|
| Default:
| 9:00 AM Cambodia time.
|
| Configure in .env:
|
| RENEWAL_REMINDER_TIME=09:00
|
*/

Schedule::command(
    'subscriptions:send-renewal-reminders'
)
    ->name('send-renewal-reminders')
    ->dailyAt(
        config(
            'services.telegram.renewal_reminder_time',
            '09:00'
        )
    )
    ->timezone($timezone)
    ->withoutOverlapping(30)
    ->onOneServer();