<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_payments', function (Blueprint $table) {

            $table->uuid('telegram_paymentID')->primary();

            $table->uuid('user_id');
            $table->uuid('telegram_group_id')->nullable();
            $table->uuid('subscription_id');

            $table->string('currency', 10);
            $table->decimal('amount', 15, 2);

            $table->string('payer_name')->nullable();
            $table->string('payer_account')->nullable();

            $table->string('merchant_name')->nullable();

            $table->string('payment_method')->nullable();
            $table->string('bank_code')->nullable();

            $table->string('trx_id')->unique();
            $table->string('apv')->nullable();

            $table->timestamp('payment_date')->nullable();

            $table->date('report_date')->nullable();
            $table->unsignedTinyInteger('report_month')->nullable();
            $table->unsignedSmallInteger('report_year')->nullable();

            $table->longText('raw_message')->nullable();

            $table->boolean('parsed_successfully')->default(false);
            $table->boolean('is_duplicate')->default(false);

            $table->enum('status', [
                'pending',
                'success',
                'failed',
                'cancelled'
            ])->default('pending');

            $table->timestamps();

            $table->foreign('user_id')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('subscription_id')
                ->references('userSubscriptionsID')
                ->on('user_subscriptions')
                ->cascadeOnDelete();

            $table->foreign('telegram_group_id')
                ->references('telegramGroupsID')
                ->on('telegram_groups')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_payments');
    }
};