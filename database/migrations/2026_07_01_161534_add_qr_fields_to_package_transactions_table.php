<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('package_transactions', function (Blueprint $table) {
            $table->longText('qr_code')->nullable()->after('external_transaction_id');
            $table->longText('qr_image_url')->nullable()->after('qr_code');
            $table->timestamp('expires_at')->nullable()->after('qr_image_url');
        });
    }

    public function down(): void
    {
        Schema::table('package_transactions', function (Blueprint $table) {
            $table->dropColumn([
                'qr_code',
                'qr_image_url',
                'expires_at',
            ]);
        });
    }
};