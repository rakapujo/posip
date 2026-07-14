<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — landed cost per unit (modal + alokasi diskon/biaya/pajak header).
 * harga_modal = harga beli kotor (input); cost_per_unit = HPP riil per unit (dipakai saat
 * approve untuk weighted-average & basis laporan laba Fase 5). Dihitung via
 * PurchaseOrderCalculationService (sama seperti cost_per_unit detail PO).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->decimal('cost_per_unit', 15, 4)->default(0)->after('harga_modal');
        });
    }

    public function down(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->dropColumn('cost_per_unit');
        });
    }
};
