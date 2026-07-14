<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — kolom finansial Pembelian Serial (sama seperti Purchase Order).
 * Diskon header (3 line) + biaya tambahan + pajak + pembulatan + grand total + tempo.
 * Dihitung via PurchaseOrderCalculationService; landed cost dialokasikan ke HPP per-unit saat approve.
 * tipe diskon/biaya = string ('percent'|'nominal'|'none') agar cross-DB aman.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_serial_intake', function (Blueprint $table) {
            // Subtotal (sum modal unit setelah diskon item — serial: = total_modal)
            $table->decimal('subtotal', 15, 2)->default(0)->after('total_modal');

            // Diskon header (3 line bertingkat)
            $table->string('diskon_1_tipe', 10)->default('none')->after('subtotal');
            $table->decimal('diskon_1_nilai', 15, 2)->default(0)->after('diskon_1_tipe');
            $table->decimal('diskon_1_hasil', 15, 2)->default(0)->after('diskon_1_nilai');
            $table->string('diskon_2_tipe', 10)->default('none')->after('diskon_1_hasil');
            $table->decimal('diskon_2_nilai', 15, 2)->default(0)->after('diskon_2_tipe');
            $table->decimal('diskon_2_hasil', 15, 2)->default(0)->after('diskon_2_nilai');
            $table->string('diskon_3_tipe', 10)->default('none')->after('diskon_2_hasil');
            $table->decimal('diskon_3_nilai', 15, 2)->default(0)->after('diskon_3_tipe');
            $table->decimal('diskon_3_hasil', 15, 2)->default(0)->after('diskon_3_nilai');
            $table->decimal('total_diskon_header', 15, 2)->default(0)->after('diskon_3_hasil');
            $table->decimal('total_setelah_diskon', 15, 2)->default(0)->after('total_diskon_header');

            // Biaya tambahan (kirim + lain, % atau Rp)
            $table->string('biaya_kirim_tipe', 10)->default('none')->after('total_setelah_diskon');
            $table->decimal('biaya_kirim_nilai', 15, 2)->default(0)->after('biaya_kirim_tipe');
            $table->decimal('biaya_kirim_hasil', 15, 2)->default(0)->after('biaya_kirim_nilai');
            $table->string('biaya_lain_nama', 100)->nullable()->after('biaya_kirim_hasil');
            $table->string('biaya_lain_tipe', 10)->default('none')->after('biaya_lain_nama');
            $table->decimal('biaya_lain_nilai', 15, 2)->default(0)->after('biaya_lain_tipe');
            $table->decimal('biaya_lain_hasil', 15, 2)->default(0)->after('biaya_lain_nilai');
            $table->decimal('total_biaya_tambahan', 15, 2)->default(0)->after('biaya_lain_hasil');

            // Pajak
            $table->decimal('dpp', 15, 2)->default(0)->after('total_biaya_tambahan');
            $table->string('pajak_nama', 50)->nullable()->after('dpp');
            $table->decimal('pajak_persen', 5, 2)->default(0)->after('pajak_nama');
            $table->decimal('pajak_nominal', 15, 2)->default(0)->after('pajak_persen');

            // Pembulatan + grand total
            $table->decimal('pembulatan', 15, 2)->default(0)->after('pajak_nominal');
            $table->decimal('grand_total', 15, 2)->default(0)->after('pembulatan');

            // Tempo (hutang dibuat saat approve)
            $table->integer('tempo_hari')->default(0)->after('grand_total');
            $table->date('tanggal_jatuh_tempo')->nullable()->after('tempo_hari');
        });
    }

    public function down(): void
    {
        Schema::table('doc_serial_intake', function (Blueprint $table) {
            $table->dropColumn([
                'subtotal',
                'diskon_1_tipe', 'diskon_1_nilai', 'diskon_1_hasil',
                'diskon_2_tipe', 'diskon_2_nilai', 'diskon_2_hasil',
                'diskon_3_tipe', 'diskon_3_nilai', 'diskon_3_hasil',
                'total_diskon_header', 'total_setelah_diskon',
                'biaya_kirim_tipe', 'biaya_kirim_nilai', 'biaya_kirim_hasil',
                'biaya_lain_nama', 'biaya_lain_tipe', 'biaya_lain_nilai', 'biaya_lain_hasil',
                'total_biaya_tambahan',
                'dpp', 'pajak_nama', 'pajak_persen', 'pajak_nominal',
                'pembulatan', 'grand_total',
                'tempo_hari', 'tanggal_jatuh_tempo',
            ]);
        });
    }
};
