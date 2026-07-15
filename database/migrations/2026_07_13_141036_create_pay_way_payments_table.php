<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(
            'pay_way_payments',
            function (Blueprint $table): void {
                $table->id();

                $table
                    ->string('merchant_ref_no', 50)
                    ->unique();

                $table
                    ->string('payment_link_id', 255)
                    ->nullable()
                    ->index();

                $table
                    ->string('create_log_id', 100)
                    ->nullable();

                $table
                    ->string('tran_id', 100)
                    ->nullable()
                    ->index();

                $table->string('title', 250);

                $table->decimal('amount', 18, 2);

                $table->string('currency', 3);

                $table
                    ->string('description', 250)
                    ->nullable();

                $table
                    ->unsignedInteger('payment_limit')
                    ->nullable();

                $table
                    ->unsignedBigInteger('expired_date')
                    ->nullable();

                $table
                    ->text('payment_link')
                    ->nullable();

                $table
                    ->string('status', 30)
                    ->default('pending')
                    ->index();

                $table
                    ->string('gateway_status', 50)
                    ->nullable();

                $table
                    ->timestamp('paid_at')
                    ->nullable();

                $table
                    ->json('create_response')
                    ->nullable();

                $table
                    ->json('callback_payload')
                    ->nullable();

                $table
                    ->json('verification_response')
                    ->nullable();

                $table->timestamps();
            }
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('pay_way_payments');
    }
};