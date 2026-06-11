<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packages', function (Blueprint $table) {

            $table->uuid('packagesID')->primary();
        
            $table->string('name');
        
            $table->decimal('price', 10, 2);
        
            $table->enum('billing_cycle', [
                'weekly',
                'monthly',
                'yearly',
                'lifetime',
                'unlimited'
            ])->default('monthly');
        
            $table->integer('payment_limit')->nullable();
        
            $table->integer('group_limit')->nullable();
        
            $table->enum('status', [
                'active',
                'inactive'
            ])->default('active');
        
            $table->softDeletes();
        
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('packages');
    }
};