<?php

namespace App\Console\Commands;

use App\Models\TelegramPayment;
use App\Models\UserSubscription;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GenerateTelegramPaymentRecords extends Command
{
    protected $signature = 'test:telegram-payments 
                            {count=23996 : Number of payment records to create}
                            {--subscription= : Subscription ID}
                            {--group= : Telegram group ID}';

    protected $description = 'Generate fake Telegram payment records for testing';

    public function handle(): int
    {
        $count = (int) $this->argument('count');

        $subscriptionId = $this->option('subscription')
            ?: 'e679d019-264c-4485-ac40-76f5645a70a4';

        $telegramGroupId = $this->option('group')
            ?: 'e91cbb29-e770-43a1-825f-30249db4f744';

        $subscription = UserSubscription::where('userSubscriptionsID', $subscriptionId)->first();

        if (! $subscription) {
            $this->error('Subscription not found.');
            return self::FAILURE;
        }

        $this->info("Generating {$count} telegram payment records...");
        $this->info("Subscription: {$subscription->userSubscriptionsID}");
        $this->info("Before payment_used: {$subscription->payment_used}");

        $now = now();
        $batch = [];
        $batchSize = 1000;

        for ($i = 1; $i <= $count; $i++) {
            $trx = 'TEST-BULK-' . $now->format('YmdHis') . '-' . $i;

            $batch[] = [
                'telegram_paymentID' => (string) Str::uuid(),
                'user_id' => $subscription->user_id,
                'telegram_group_id' => $telegramGroupId,
                'subscription_id' => $subscription->userSubscriptionsID,

                'currency' => 'KHR',
                'amount' => 23999,

                'payer_name' => 'TEST USER ' . $i,
                'payer_account' => '*' . str_pad((string) ($i % 1000), 3, '0', STR_PAD_LEFT),
                'merchant_name' => 'CHEN KHEANG',
                'payment_method' => 'ABA PAY',
                'bank_code' => 'ABA',

                'trx_id' => $trx,
                'apv' => (string) random_int(100000, 999999),

                'payment_date' => $now,
                'report_date' => $now->toDateString(),
                'report_month' => $now->month,
                'report_year' => $now->year,

                'raw_message' => "៛23,999 paid by TEST USER {$i} via ABA PAY at CHEN KHEANG. Trx. ID: {$trx}.",
                'parsed_successfully' => true,
                'is_duplicate' => false,
                'status' => 'success',

                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($batch) >= $batchSize) {
                TelegramPayment::insert($batch);
                $batch = [];

                $this->line("Inserted {$i} records...");
            }
        }

        if (! empty($batch)) {
            TelegramPayment::insert($batch);
        }

        /*
        |--------------------------------------------------------------------------
        | Important:
        |--------------------------------------------------------------------------
        | insert() is fast, but it does not run model created events.
        | So we manually increase payment_used by count.
        */
        $subscription->increment('payment_used', $count);

        $subscription = $subscription->fresh();

        $this->info('Done.');
        $this->info("After payment_used: {$subscription->payment_used}");
        $this->info("Remaining payments: {$subscription->remainingPayments()}");

        return self::SUCCESS;
    }
}