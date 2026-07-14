<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — hutang supplier bisa bersumber dari Pembelian Serial, bukan hanya PO.
 * po_id dibuat nullable + tambah serial_intake_id (salah satu terisi sesuai sumber).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supplier_hutang', function (Blueprint $table) {
            $table->foreignId('po_id')->nullable()->change();
        });
        Schema::table('supplier_hutang', function (Blueprint $table) {
            $table->foreignId('serial_intake_id')->nullable()->after('po_id')->constrained('doc_serial_intake');
        });
    }

    public function down(): void
    {
        Schema::table('supplier_hutang', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serial_intake_id');
        });
        // po_id dibiarkan nullable (revert ke NOT NULL bisa gagal bila ada baris sumber serial).
    }
};
