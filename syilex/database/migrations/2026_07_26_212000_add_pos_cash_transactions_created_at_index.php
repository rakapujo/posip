<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Wave A: index for cash-flow date range filters on pos_cash_transactions.created_at.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pos_cash_transactions', function (Blueprint $table) {
            if (! $this->indexExists('pos_cash_transactions', 'pos_cash_transactions_created_at_index')) {
                $table->index('created_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('pos_cash_transactions', function (Blueprint $table) {
            if ($this->indexExists('pos_cash_transactions', 'pos_cash_transactions_created_at_index')) {
                $table->dropIndex('pos_cash_transactions_created_at_index');
            }
        });
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'sqlite') {
            $indexes = DB::select("PRAGMA index_list('{$table}')");
            foreach ($indexes as $index) {
                if (($index->name ?? '') === $indexName) {
                    return true;
                }
            }

            return false;
        }

        $db = Schema::getConnection()->getDatabaseName();
        $row = DB::selectOne(
            'SELECT COUNT(*) AS c FROM information_schema.statistics WHERE table_schema = ? AND table_name = ? AND index_name = ?',
            [$db, $table, $indexName]
        );

        return ((int) ($row->c ?? 0)) > 0;
    }
};
