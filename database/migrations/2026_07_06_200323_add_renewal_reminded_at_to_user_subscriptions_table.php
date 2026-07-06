<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            // Set once when the renewal reminder is sent.
            // NULL = not reminded yet. One reminder per subscription row.
            $table->timestamp('renewal_reminded_at')->nullable()->after('ends_at');

            // Speeds up the hourly reminder query:
            // WHERE status = 'active' AND ends_at BETWEEN ...
            $table->index(['status', 'ends_at'], 'user_subscriptions_status_ends_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('user_subscriptions', function (Blueprint $table) {
            $table->dropIndex('user_subscriptions_status_ends_at_index');
            $table->dropColumn('renewal_reminded_at');
        });
    }
};