<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_usage_logs', function (Blueprint $table) {

            $table->uuid('usageLogID')->primary();

            $table->uuid('subscription_id');

            $table->uuid('user_id');

            $table->enum('type', [
                'subscription',
                'group',
                'payment',
                'telegram'
            ]);

            $table->string('action');

            $table->integer('value')->default(1);

            $table->text('description')->nullable();

            $table->json('metadata')->nullable();

            $table->timestamps();

            $table->foreign('subscription_id')
                ->references('userSubscriptionsID')
                ->on('user_subscriptions')
                ->cascadeOnDelete();

            $table->foreign('user_id')
                ->references('uuid')
                ->on('users')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_usage_logs');
    }
};