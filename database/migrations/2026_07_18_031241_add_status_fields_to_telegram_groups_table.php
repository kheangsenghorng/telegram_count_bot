<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('telegram_groups', function (Blueprint $table) {
            $table
                ->string('connection_status', 20)
                ->default('offline')
                ->index();

            $table
                ->string('activity_status', 20)
                ->default('inactive')
                ->index();

            $table
                ->timestamp('last_activity_at')
                ->nullable()
                ->index();

            $table
                ->timestamp('last_heartbeat_at')
                ->nullable()
                ->index();
        });
    }

    public function down(): void
    {
        Schema::table('telegram_groups', function (Blueprint $table) {
            $table->dropColumn([
                'connection_status',
                'activity_status',
                'last_activity_at',
                'last_heartbeat_at',
            ]);
        });
    }
};