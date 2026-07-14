<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Convert DATE columns to DATETIME for better audit trail.
     * Remove tanggal_lahir and jenis_kelamin from master_customer (not needed).
     */
    public function up(): void
    {
        // 1. Remove tanggal_lahir and jenis_kelamin from master_customer
        Schema::table('master_customer', function (Blueprint $table) {
            $table->dropColumn(['tanggal_lahir', 'jenis_kelamin']);
        });

        // 2. Convert doc_purchase_order.tanggal_po from DATE to DATETIME
        Schema::table('doc_purchase_order', function (Blueprint $table) {
            $table->dateTime('tanggal_po')->change();
        });

        // 3. Convert doc_purchase_return.tanggal from DATE to DATETIME
        Schema::table('doc_purchase_return', function (Blueprint $table) {
            $table->dateTime('tanggal')->change();
        });

        // 4. Convert doc_adjustment.tanggal from DATE to DATETIME
        Schema::table('doc_adjustment', function (Blueprint $table) {
            $table->dateTime('tanggal')->change();
        });

        // 5. Convert doc_transfer.tanggal from DATE to DATETIME
        Schema::table('doc_transfer', function (Blueprint $table) {
            $table->dateTime('tanggal')->change();
        });

        // 6. Convert doc_repack.tanggal from DATE to DATETIME
        Schema::table('doc_repack', function (Blueprint $table) {
            $table->dateTime('tanggal')->change();
        });

        // 7. Convert doc_stock_opname.tanggal_opname from DATE to DATETIME
        Schema::table('doc_stock_opname', function (Blueprint $table) {
            $table->dateTime('tanggal_opname')->change();
        });

        // 8. Convert doc_hpp_correction.tanggal_koreksi from DATE to DATETIME
        Schema::table('doc_hpp_correction', function (Blueprint $table) {
            $table->dateTime('tanggal_koreksi')->change();
        });

        // 9. Convert stock_card.tanggal from DATE to DATETIME
        Schema::table('stock_card', function (Blueprint $table) {
            $table->dateTime('tanggal')->change();
        });

        // 10. Convert supplier_hutang.tanggal from DATE to DATETIME
        Schema::table('supplier_hutang', function (Blueprint $table) {
            $table->dateTime('tanggal')->change();
            // tanggal_jatuh_tempo stays as DATE (only need date, not time)
        });

        // 11. Convert supplier_deposit.tanggal from DATE to DATETIME
        Schema::table('supplier_deposit', function (Blueprint $table) {
            $table->dateTime('tanggal')->change();
        });

        // 12. Convert history_harga_beli.tanggal from DATE to DATETIME
        Schema::table('history_harga_beli', function (Blueprint $table) {
            $table->dateTime('tanggal')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Restore tanggal_lahir and jenis_kelamin
        Schema::table('master_customer', function (Blueprint $table) {
            $table->enum('jenis_kelamin', ['L', 'P'])->nullable()->after('alamat');
            $table->date('tanggal_lahir')->nullable()->after('jenis_kelamin');
        });

        // Revert DATETIME back to DATE
        Schema::table('doc_purchase_order', function (Blueprint $table) {
            $table->date('tanggal_po')->change();
        });

        Schema::table('doc_purchase_return', function (Blueprint $table) {
            $table->date('tanggal')->change();
        });

        Schema::table('doc_adjustment', function (Blueprint $table) {
            $table->date('tanggal')->change();
        });

        Schema::table('doc_transfer', function (Blueprint $table) {
            $table->date('tanggal')->change();
        });

        Schema::table('doc_repack', function (Blueprint $table) {
            $table->date('tanggal')->change();
        });

        Schema::table('doc_stock_opname', function (Blueprint $table) {
            $table->date('tanggal_opname')->change();
        });

        Schema::table('doc_hpp_correction', function (Blueprint $table) {
            $table->date('tanggal_koreksi')->change();
        });

        Schema::table('stock_card', function (Blueprint $table) {
            $table->date('tanggal')->change();
        });

        Schema::table('supplier_hutang', function (Blueprint $table) {
            $table->date('tanggal')->change();
        });

        Schema::table('supplier_deposit', function (Blueprint $table) {
            $table->date('tanggal')->change();
        });

        Schema::table('history_harga_beli', function (Blueprint $table) {
            $table->date('tanggal')->change();
        });
    }
};
