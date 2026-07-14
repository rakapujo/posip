<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — penanda produk dilacak per-unit (nomor seri).
 * Additive: produk lama = false (qty/retail), perilaku tak berubah.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('master_produk', function (Blueprint $table) {
            $table->boolean('is_serial')->default(false)->after('barcode')->index();
        });
    }

    public function down(): void
    {
        Schema::table('master_produk', function (Blueprint $table) {
            $table->dropColumn('is_serial');
        });
    }
};
