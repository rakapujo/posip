<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Opsi "Cash / Lunas langsung" pada Purchase Order & Pembelian Serial.
 * Saat dicentang, hutang tetap dibuat saat approve lalu OTOMATIS dilunasi penuh
 * (DocPembayaranHutang). Field cash_* menyimpan info pembayaran (mirror form pelunasan):
 * metode (cash/transfer), no. referensi (= bukti/kwitansi), bank nama & rekening (transfer).
 */
return new class extends Migration
{
    private array $tables = ['doc_purchase_order', 'doc_serial_intake'];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->boolean('cash_payment')->default(false)->after('status');
                $t->string('cash_metode', 20)->nullable()->after('cash_payment'); // cash | transfer
                $t->string('cash_no_referensi', 100)->nullable()->after('cash_metode');
                $t->string('cash_bank_nama', 100)->nullable()->after('cash_no_referensi');
                $t->string('cash_bank_rekening', 50)->nullable()->after('cash_bank_nama');
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn(['cash_payment', 'cash_metode', 'cash_no_referensi', 'cash_bank_nama', 'cash_bank_rekening']);
            });
        }
    }
};
