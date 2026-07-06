<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_notifications', function (Blueprint $table) {
            $table->uuid('paymentNotificationsID')->primary();
        
            $table->uuid('telegram_group_id');
            $table->uuid('telegram_payment_id')->nullable();
        
            $table->string('telegram_message_id')->nullable();
            $table->text('raw_message');
            $table->boolean('processed')->default(false);
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('created_at')->nullable();
        
            $table->foreign('telegram_group_id', 'pay_notif_group_fk')
                ->references('telegramGroupsID')
                ->on('telegram_groups')
                ->cascadeOnDelete();
        
            $table->foreign('telegram_payment_id', 'pay_notif_payment_fk')
                ->references('telegram_paymentID')
                ->on('telegram_payments')
                ->nullOnDelete();
        
            $table->unique(['telegram_group_id', 'telegram_message_id'], 'pay_notif_group_msg_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_notifications');
    }
};