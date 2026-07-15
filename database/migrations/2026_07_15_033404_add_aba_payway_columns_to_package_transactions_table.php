<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_transactions', function (Blueprint $table) {
            if (! Schema::hasColumn('package_transactions', 'telegram_chat_id')) {
                $table->string('telegram_chat_id', 32)->nullable()->index();
            }

            if (! Schema::hasColumn('package_transactions', 'telegram_message_id')) {
                $table->unsignedBigInteger('telegram_message_id')->nullable();
            }

            if (! Schema::hasColumn('package_transactions', 'create_log_id')) {
                // PayWay payment-link creation log ID (top-level tran_id)
                $table->string('create_log_id', 64)->nullable();
            }

            if (! Schema::hasColumn('package_transactions', 'gateway_status')) {
                // Raw gateway status: OPEN / APPROVED / ...
                $table->string('gateway_status', 32)->nullable();
            }

            if (! Schema::hasColumn('package_transactions', 'create_response')) {
                // Full PayWay create-link response for audit
                $table->json('create_response')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('package_transactions', function (Blueprint $table) {
            foreach ([
                'telegram_chat_id',
                'telegram_message_id',
                'create_log_id',
                'gateway_status',
                'create_response',
            ] as $column) {
                if (Schema::hasColumn('package_transactions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};