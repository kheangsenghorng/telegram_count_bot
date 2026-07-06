<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            DELETE tp1 FROM telegram_payments tp1
            INNER JOIN telegram_payments tp2
                ON tp1.trx_id = tp2.trx_id
            WHERE tp1.telegram_paymentID > tp2.telegram_paymentID
              AND tp1.trx_id IS NOT NULL
        ");

        if (! $this->indexExists('telegram_payments', 'telegram_payments_trx_id_unique')) {
            Schema::table('telegram_payments', function (Blueprint $table) {
                $table->unique('trx_id', 'telegram_payments_trx_id_unique');
            });
        }
    }

    public function down(): void
    {
        if ($this->indexExists('telegram_payments', 'telegram_payments_trx_id_unique')) {
            Schema::table('telegram_payments', function (Blueprint $table) {
                $table->dropUnique('telegram_payments_trx_id_unique');
            });
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $database = DB::getDatabaseName();

        $result = DB::selectOne("
            SELECT COUNT(1) AS count
            FROM information_schema.statistics
            WHERE table_schema = ?
              AND table_name = ?
              AND index_name = ?
        ", [$database, $table, $indexName]);

        return (int) $result->count > 0;
    }
};