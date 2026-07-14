<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — alur Pembelian Serial jadi draft → approved (konsisten dgn PO).
 * - status di-relax dari enum ke string (CLAUDE.md §2F: status = string di kode; enum cuma guard).
 *   doc_serial_intake: draft | approved | cancelled ; serial_units: pending | tersedia | terjual | rusak
 * - tambah approved_at / approved_by (audit approval seperti PO)
 * - data lama 'completed' (langsung-final) → 'approved' (sudah commit stok)
 * Non-destruktif (tak menghapus data).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Audit approval (additive)
        Schema::table('doc_serial_intake', function (Blueprint $table) {
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
        });

        // Relax status enum → string (cross-DB aman)
        Schema::table('doc_serial_intake', function (Blueprint $table) {
            $table->string('status', 20)->default('draft')->change();
        });
        Schema::table('serial_units', function (Blueprint $table) {
            $table->string('status', 20)->default('tersedia')->change();
        });

        // Data lama: intake 'completed' (commit langsung) → 'approved'
        DB::table('doc_serial_intake')
            ->where('status', 'completed')
            ->update(['status' => 'approved', 'approved_at' => now()]);
    }

    public function down(): void
    {
        Schema::table('doc_serial_intake', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn('approved_at');
        });
        // status dibiarkan sebagai string (revert enum tidak perlu untuk dev).
    }
};
