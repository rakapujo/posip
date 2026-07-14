<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simpan pilihan unit serial pada DRAFT dokumen (editable, di-replace tiap edit).
 * Untuk produk serial: qty detail = COUNT(serial_unit_ids). NULL untuk produk non-serial.
 * Opname pakai kolom terpisah (daftar SN yang DICENTANG hadir).
 * Tanpa ->after() agar aman lintas-DB (SQLite test).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_transfer_detail', function (Blueprint $table) {
            $table->json('serial_unit_ids')->nullable();
        });
        Schema::table('doc_adjustment_detail', function (Blueprint $table) {
            $table->json('serial_unit_ids')->nullable();
        });
        Schema::table('doc_purchase_return_detail', function (Blueprint $table) {
            $table->json('serial_unit_ids')->nullable();
        });
        Schema::table('doc_stock_opname_detail', function (Blueprint $table) {
            $table->json('serial_unit_ids_present')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('doc_transfer_detail', function (Blueprint $table) {
            $table->dropColumn('serial_unit_ids');
        });
        Schema::table('doc_adjustment_detail', function (Blueprint $table) {
            $table->dropColumn('serial_unit_ids');
        });
        Schema::table('doc_purchase_return_detail', function (Blueprint $table) {
            $table->dropColumn('serial_unit_ids');
        });
        Schema::table('doc_stock_opname_detail', function (Blueprint $table) {
            $table->dropColumn('serial_unit_ids_present');
        });
    }
};
