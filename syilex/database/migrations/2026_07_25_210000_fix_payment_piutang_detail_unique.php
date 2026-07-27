<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('doc_pembayaran_piutang_detail')) {
            return;
        }

        if ($this->hasUniqueIndex(['pembayaran_id', 'piutang_id', 'sumber'])) {
            return;
        }

        Schema::table('doc_pembayaran_piutang_detail', function (Blueprint $table) {
            $table->dropForeign(['pembayaran_id']);
            $table->dropForeign(['piutang_id']);
            $table->dropUnique('payment_piutang_detail_unique');
            $table->unique(['pembayaran_id', 'piutang_id', 'sumber'], 'payment_piutang_detail_unique');
            $table->foreign('pembayaran_id')->references('id')->on('doc_pembayaran_piutang')->cascadeOnDelete();
            $table->foreign('piutang_id')->references('id')->on('customer_piutang');
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('doc_pembayaran_piutang_detail')) {
            return;
        }

        if ($this->hasUniqueIndex(['pembayaran_id', 'piutang_id']) && ! $this->hasUniqueIndex(['pembayaran_id', 'piutang_id', 'sumber'])) {
            return;
        }

        Schema::table('doc_pembayaran_piutang_detail', function (Blueprint $table) {
            $table->dropForeign(['pembayaran_id']);
            $table->dropForeign(['piutang_id']);
            $table->dropUnique('payment_piutang_detail_unique');
            $table->unique(['pembayaran_id', 'piutang_id'], 'payment_piutang_detail_unique');
            $table->foreign('pembayaran_id')->references('id')->on('doc_pembayaran_piutang')->cascadeOnDelete();
            $table->foreign('piutang_id')->references('id')->on('customer_piutang');
        });
    }

    private function hasUniqueIndex(array $columns): bool
    {
        return collect(Schema::getIndexes('doc_pembayaran_piutang_detail'))->contains(function (array $index) use ($columns) {
            return ($index['unique'] ?? false) && $index['columns'] === $columns;
        });
    }
};
