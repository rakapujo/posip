<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Koreksi HPP Serial — simpan rincian komponen biaya per unit (audit).
 * cost_per_unit_baru (landed) = harga_modal_baru + biaya_kirim_baru + biaya_lain_baru + pajak_baru.
 * Pajak dihitung otomatis dari setting pajak pembelian (0 bila tax_purchase_included_in_hpp off).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_serial_hpp_correction_detail', function (Blueprint $table) {
            $table->decimal('biaya_kirim_baru', 15, 2)->default(0);
            $table->decimal('biaya_lain_baru', 15, 2)->default(0);
            $table->decimal('pajak_baru', 15, 2)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('doc_serial_hpp_correction_detail', function (Blueprint $table) {
            $table->dropColumn(['biaya_kirim_baru', 'biaya_lain_baru', 'pajak_baru']);
        });
    }
};
