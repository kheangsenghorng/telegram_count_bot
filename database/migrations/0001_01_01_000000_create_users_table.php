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
        Schema::create('users', function (Blueprint $table) {
            $table->id();
        
            $table->string('first_name');
            $table->string('last_name')->nullable();
        
            $table->string('email')->unique()->nullable();
            $table->string('phone')->unique()->nullable();
        
            $table->timestamp('email_verified_at')->nullable();
        
            $table->string('password');
        
            // Telegram
            $table->string('telegram_id')->nullable()->unique();
            $table->string('telegram_username')->nullable();
            $table->string('telegram_first_name')->nullable();
            $table->string('telegram_last_name')->nullable();
            $table->text('telegram_photo_url')->nullable();
        
            // Role
            $table->enum('role', [
                'admin',
                'user'
            ])->default('user');
        
            // Status
            $table->enum('status', [
                'active',
                'inactive',
                'blocked'
            ])->default('active');
        
            $table->timestamp('last_login_at')->nullable();
        
            $table->rememberToken();
        
            $table->softDeletes();
        
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
