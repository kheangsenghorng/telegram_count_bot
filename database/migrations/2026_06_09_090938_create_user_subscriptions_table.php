<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_subscriptions', function (Blueprint $table) {
            $table->uuid('userSubscriptionsID')->primary();

            $table->uuid('user_id');

            $table->foreign('user_id')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();
                

            $table->uuid('package_id');

            $table->string('subscription_key', 100)->unique();

            $table->integer('override_payment_limit')->nullable();
            $table->integer('override_group_limit')->nullable();

            $table->integer('payment_used')->default(0);
            $table->integer('group_used')->default(0);

            $table->timestamp('starts_at');
            $table->timestamp('ends_at')->nullable();

            $table->enum('status', [
                'active',
                'expired',
                'cancelled',
                'suspended'
            ])->default('active');

            $table->string('payment_method')->nullable();
            $table->string('transaction_id')->nullable();

            $table->timestamps();

            $table->foreign('package_id')
                ->references('packagesID')
                ->on('packages')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_subscriptions');
    }
};