<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Biaya kirim + biaya lain pada Transfer antar gudang (opsional masuk HPP).
 * - Header doc_transfer: nominal biaya + nama biaya lain + flag masuk_hpp.
 * - Detail doc_transfer_detail: biaya_dialokasikan (porsi biaya per baris, by-value)
 *   diisi saat approve untuk jejak audit; tampil di detail dokumen.
 *
 * SQLite-safe: tanpa ->after(). Lihat docs/modules/serial.md (Biaya Kirim Transfer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_transfer', function (Blueprint $table) {
            $table->decimal('biaya_kirim', 15, 2)->default(0);
            $table->decimal('biaya_lain', 15, 2)->default(0);
            $table->string('biaya_lain_nama', 100)->nullable();
            $table->boolean('masuk_hpp')->default(false);
        });

        Schema::table('doc_transfer_detail', function (Blueprint $table) {
            $table->decimal('biaya_dialokasikan', 15, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('doc_transfer', function (Blueprint $table) {
            $table->dropColumn(['biaya_kirim', 'biaya_lain', 'biaya_lain_nama', 'masuk_hpp']);
        });

        Schema::table('doc_transfer_detail', function (Blueprint $table) {
            $table->dropColumn('biaya_dialokasikan');
        });
    }
};
