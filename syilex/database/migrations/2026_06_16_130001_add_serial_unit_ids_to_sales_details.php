<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Integrasi serial ke Penjualan (Fase A): kolom serial_unit_ids (JSON) untuk
 * menaut unit (SN) yang dijual / diretur. Untuk produk serial, qty = count(serial_unit_ids).
 * Produk retail: kolom null (perilaku tak berubah). Pola sama dgn doc_transfer_detail dll.
 *
 * SQLite-safe: tanpa ->after(). Lihat docs/modules/serial.md (§ Penjualan).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_sales_detail', function (Blueprint $table) {
            $table->json('serial_unit_ids')->nullable();
        });

        Schema::table('doc_sales_return_detail', function (Blueprint $table) {
            $table->json('serial_unit_ids')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('doc_sales_detail', function (Blueprint $table) {
            $table->dropColumn('serial_unit_ids');
        });

        Schema::table('doc_sales_return_detail', function (Blueprint $table) {
            $table->dropColumn('serial_unit_ids');
        });
    }
};
