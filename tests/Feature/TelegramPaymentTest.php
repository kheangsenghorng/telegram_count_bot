<?php

namespace Tests\Feature;

use App\Models\Package;
use App\Models\TelegramPayment;
use App\Models\User;
use App\Models\UserSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class TelegramPaymentTest extends TestCase
{
    use RefreshDatabase;

    private User $user;
    private Package $package;
    private UserSubscription $subscription;

    protected function setUp(): void
    {
        parent::setUp();
    
        $this->user = User::query()->create([
            'uuid' => (string) Str::uuid(),
            'name' => 'Test User',
            'telegram_id' => random_int(100000000, 999999999),
        ]);
    
        $this->package = Package::query()->create([
            'packagesID' => (string) Str::uuid(),
            'name' => 'Test Package',
            'price' => 1,
            'currency' => 'USD',
            'billing_cycle' => 'lifetime',
            'payment_limit' => 24001,
            'group_limit' => 5,
            'status' => 'active',
        ]);
    
        $this->subscription = UserSubscription::query()->create([
            'user_id' => $this->user->uuid,
            'package_id' => $this->package->packagesID,
            'override_payment_limit' => 24001,
            'override_group_limit' => null,
            'payment_used' => 0,
            'group_used' => 0,
            'starts_at' => now(),
            'ends_at' => null,
            'status' => 'active',
            'payment_method' => 'test',
            'transaction_id' => 'TEST-SUB-' . now()->format('YmdHis'),
        ]);
    }

    public function test_real_telegram_payment_increases_payment_used(): void
    {
        $before = $this->subscription->fresh()->payment_used;

        $payment = TelegramPayment::create([
            'user_id' => $this->user->uuid,
            'telegram_group_id' => null,
            'subscription_id' => $this->subscription->userSubscriptionsID,

            'currency' => 'KHR',
            'amount' => 23999,

            'payer_name' => 'TEST USER',
            'payer_account' => '*999',
            'merchant_name' => 'CHEN KHEANG',
            'payment_method' => 'ABA PAY',
            'bank_code' => 'ABA',

            'trx_id' => 'TEST-' . now()->format('YmdHis'),
            'apv' => '999999',

            'payment_date' => now(),
            'report_date' => today(),
            'report_month' => now()->month,
            'report_year' => now()->year,

            'raw_message' => '៛23,999 paid by TEST USER (*999) via ABA PAY at CHEN KHEANG.',
            'parsed_successfully' => true,
            'is_duplicate' => false,
            'status' => 'success',
        ]);

        $after = $this->subscription->fresh()->payment_used;

        $this->assertDatabaseHas('telegram_payments', [
            'telegram_paymentID' => $payment->telegram_paymentID,
            'amount' => 23999,
            'parsed_successfully' => true,
            'is_duplicate' => false,
        ]);

        $this->assertTrue($payment->countsTowardQuota());
        $this->assertEquals($before + 1, $after);
        $this->assertEquals(24000, $this->subscription->fresh()->remainingPayments());
    }

    public function test_duplicate_payment_does_not_increase_payment_used(): void
    {
        $before = $this->subscription->fresh()->payment_used;

        $payment = TelegramPayment::create([
            'user_id' => $this->user->uuid,
            'telegram_group_id' => null,
            'subscription_id' => $this->subscription->userSubscriptionsID,

            'currency' => 'KHR',
            'amount' => 23999,

            'payer_name' => 'TEST DUPLICATE',
            'payer_account' => '*999',
            'merchant_name' => 'CHEN KHEANG',
            'payment_method' => 'ABA PAY',
            'bank_code' => 'ABA',

            'trx_id' => 'DUP-' . now()->format('YmdHis'),
            'apv' => '888888',

            'payment_date' => now(),
            'report_date' => today(),
            'report_month' => now()->month,
            'report_year' => now()->year,

            'raw_message' => 'Duplicate test payment',
            'parsed_successfully' => true,
            'is_duplicate' => true,
            'status' => 'success',
        ]);

        $after = $this->subscription->fresh()->payment_used;

        $this->assertFalse($payment->countsTowardQuota());
        $this->assertEquals($before, $after);
    }

    public function test_parse_failed_payment_does_not_increase_payment_used(): void
    {
        $before = $this->subscription->fresh()->payment_used;

        $payment = TelegramPayment::create([
            'user_id' => $this->user->uuid,
            'telegram_group_id' => null,
            'subscription_id' => $this->subscription->userSubscriptionsID,

            'currency' => 'KHR',
            'amount' => 23999,

            'payer_name' => null,
            'payer_account' => null,
            'merchant_name' => null,
            'payment_method' => null,
            'bank_code' => null,

            'trx_id' => null,
            'apv' => null,

            'payment_date' => now(),
            'report_date' => today(),
            'report_month' => now()->month,
            'report_year' => now()->year,

            'raw_message' => 'Cannot parse this message',
            'parsed_successfully' => false,
            'is_duplicate' => false,
            'status' => 'failed',
        ]);

        $after = $this->subscription->fresh()->payment_used;

        $this->assertFalse($payment->countsTowardQuota());
        $this->assertEquals($before, $after);
    }

    public function test_deleting_counted_payment_refunds_payment_used(): void
    {
        $payment = TelegramPayment::create([
            'user_id' => $this->user->uuid,
            'telegram_group_id' => null,
            'subscription_id' => $this->subscription->userSubscriptionsID,

            'currency' => 'KHR',
            'amount' => 23999,

            'payer_name' => 'TEST USER',
            'payer_account' => '*999',
            'merchant_name' => 'CHEN KHEANG',
            'payment_method' => 'ABA PAY',
            'bank_code' => 'ABA',

            'trx_id' => 'TEST-DELETE-' . now()->format('YmdHis'),
            'apv' => '777777',

            'payment_date' => now(),
            'report_date' => today(),
            'report_month' => now()->month,
            'report_year' => now()->year,

            'raw_message' => 'Delete refund test',
            'parsed_successfully' => true,
            'is_duplicate' => false,
            'status' => 'success',
        ]);

        $this->assertEquals(1, $this->subscription->fresh()->payment_used);

        $payment->delete();

        $this->assertEquals(0, $this->subscription->fresh()->payment_used);
    }

    public function test_orphan_old_payment_is_prunable(): void
    {
        TelegramPayment::create([
            'user_id' => null,
            'telegram_group_id' => null,
            'subscription_id' => null,

            'currency' => 'KHR',
            'amount' => 23999,

            'payer_name' => 'ORPHAN TEST',
            'payer_account' => '*000',
            'merchant_name' => 'CHEN KHEANG',
            'payment_method' => 'ABA PAY',
            'bank_code' => 'ABA',

            'trx_id' => 'ORPHAN-' . now()->format('YmdHis'),
            'apv' => '111111',

            'payment_date' => now()->subDays(2),
            'report_date' => now()->subDays(2)->toDateString(),
            'report_month' => now()->subDays(2)->month,
            'report_year' => now()->subDays(2)->year,

            'raw_message' => 'Old orphan test payment',
            'parsed_successfully' => true,
            'is_duplicate' => false,
            'status' => 'success',

            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
        ]);

        $count = (new TelegramPayment())->prunable()->count();

        $this->assertEquals(1, $count);
    }
}