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
                $table->string('telegram_chat_id')->nullable();
            }

            if (! Schema::hasColumn('package_transactions', 'telegram_message_id')) {
                $table->unsignedBigInteger('telegram_message_id')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::table('package_transactions', function (Blueprint $table) {
            if (Schema::hasColumn('package_transactions', 'telegram_chat_id')) {
                $table->dropColumn('telegram_chat_id');
            }

            if (Schema::hasColumn('package_transactions', 'telegram_message_id')) {
                $table->dropColumn('telegram_message_id');
            }
        });
    }
};