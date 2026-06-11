<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_groups', function (Blueprint $table) {

            $table->uuid('telegramGroupsID')->primary();

            $table->uuid('user_id');

            $table->uuid('subscription_id');

            $table->string('group_id')->unique();

            $table->string('group_name');

            $table->enum('group_type', [
                'group',
                'supergroup'
            ])->default('group');

            $table->string('telegram_username')->nullable();

            $table->timestamp('last_payment_at')->nullable();

            $table->timestamp('bot_added_at')->nullable();

            $table->timestamp('connected_at')->nullable();

            $table->enum('status', [
                'pending',
                'connected',
                'disconnected',
                'blocked'
            ])->default('pending');

            $table->softDeletes();

            $table->timestamps();

            $table->foreign('user_id')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();

            $table->foreign('subscription_id')
                ->references('userSubscriptionsID')
                ->on('user_subscriptions')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_groups');
    }
};