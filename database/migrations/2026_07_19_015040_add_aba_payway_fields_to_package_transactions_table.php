<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('package_transactions', 'gateway')) {
            Schema::table('package_transactions', function (Blueprint $table) {
                $table->string('gateway', 50)
                    ->nullable()
                    ->after('payment_method')
                    ->index();
            });
        }

        if (! Schema::hasColumn('package_transactions', 'merchant_ref_no')) {
            Schema::table('package_transactions', function (Blueprint $table) {
                $table->string('merchant_ref_no', 100)
                    ->nullable()
                    ->after('external_transaction_id')
                    ->index();
            });
        }

        if (! Schema::hasColumn('package_transactions', 'checkout_url')) {
            Schema::table('package_transactions', function (Blueprint $table) {
                $table->text('checkout_url')
                    ->nullable()
                    ->after('merchant_ref_no');
            });
        }

        if (! Schema::hasColumn('package_transactions', 'aba_tran_id')) {
            Schema::table('package_transactions', function (Blueprint $table) {
                $table->string('aba_tran_id', 100)
                    ->nullable()
                    ->after('checkout_url')
                    ->index();
            });
        }
    }

    public function down(): void
    {
        $columns = array_filter([
            Schema::hasColumn('package_transactions', 'gateway')
                ? 'gateway'
                : null,

            Schema::hasColumn('package_transactions', 'merchant_ref_no')
                ? 'merchant_ref_no'
                : null,

            Schema::hasColumn('package_transactions', 'checkout_url')
                ? 'checkout_url'
                : null,

            Schema::hasColumn('package_transactions', 'aba_tran_id')
                ? 'aba_tran_id'
                : null,
        ]);

        if ($columns !== []) {
            Schema::table('package_transactions', function (Blueprint $table) use ($columns) {
                $table->dropColumn($columns);
            });
        }
    }
};