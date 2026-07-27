<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wave B: UNIQUE nullable retur_id on supplier_deposit;
 * UNIQUE (pembayaran_id, deposit_id) on doc_pembayaran_hutang_deposit.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('supplier_deposit') && ! $this->hasUniqueIndex('supplier_deposit', ['retur_id'])) {
            Schema::table('supplier_deposit', function (Blueprint $table) {
                $table->unique('retur_id', 'supplier_deposit_retur_id_unique');
            });
        }

        if (Schema::hasTable('doc_pembayaran_hutang_deposit')
            && ! $this->hasUniqueIndex('doc_pembayaran_hutang_deposit', ['pembayaran_id', 'deposit_id'])) {
            Schema::table('doc_pembayaran_hutang_deposit', function (Blueprint $table) {
                $table->unique(['pembayaran_id', 'deposit_id'], 'payment_hutang_deposit_unique');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('supplier_deposit')) {
            Schema::table('supplier_deposit', function (Blueprint $table) {
                $table->dropUnique('supplier_deposit_retur_id_unique');
            });
        }

        if (Schema::hasTable('doc_pembayaran_hutang_deposit')) {
            Schema::table('doc_pembayaran_hutang_deposit', function (Blueprint $table) {
                $table->dropUnique('payment_hutang_deposit_unique');
            });
        }
    }

    private function hasUniqueIndex(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        return collect(Schema::getIndexes($table))->contains(function (array $index) use ($columns) {
            return ($index['unique'] ?? false) && $index['columns'] === $columns;
        });
    }
};
