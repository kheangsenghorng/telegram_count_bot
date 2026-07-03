<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('package_transactions', function (Blueprint $table) {
            $table->string('md5')->nullable()->after('qr_image_url');
        });
    }
    
    public function down(): void
    {
        Schema::table('package_transactions', function (Blueprint $table) {
            $table->dropColumn('md5');
        });
    }
};
