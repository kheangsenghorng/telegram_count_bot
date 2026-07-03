<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('package_transactions', function (Blueprint $table) {
            $table->uuid('packageTransactionsID')->primary();

            // Foreign keys
            $table->uuid('user_id')->nullable();
            $table->uuid('subscription_id')->nullable();
            $table->string('package_id')->nullable();

            // Payment info
            $table->decimal('amount', 12, 2);
            $table->string('currency', 3)->default('USD');
            $table->string('payment_method')->default('bakong_khqr');

            // Your external transaction reference
            $table->string('external_transaction_id')->unique();

            // Status
            $table->enum('status', [
                'pending',
                'paid',
                'failed',
                'expired',
                'cancelled',
            ])->default('pending');

            $table->timestamp('paid_at')->nullable();

            $table->timestamps();

            // Relations
            $table->foreign('user_id')
                ->references('uuid')
                ->on('users')
                ->nullOnDelete();

            $table->foreign('subscription_id')
                ->references('userSubscriptionsID')
                ->on('user_subscriptions')
                ->nullOnDelete();

            $table->foreign('package_id')
                ->references('packagesID')
                ->on('packages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('package_transactions');
    }
};