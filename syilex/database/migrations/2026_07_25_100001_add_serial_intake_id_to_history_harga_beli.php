<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * History harga beli bisa bersumber dari Pembelian Serial (per unit), bukan hanya PO.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('history_harga_beli', function (Blueprint $table) {
            $table->foreignId('po_id')->nullable()->change();
            $table->foreignId('po_detail_id')->nullable()->change();
        });
        Schema::table('history_harga_beli', function (Blueprint $table) {
            $table->foreignId('serial_intake_id')->nullable()->after('po_detail_id')->constrained('doc_serial_intake');
        });
    }

    public function down(): void
    {
        Schema::table('history_harga_beli', function (Blueprint $table) {
            $table->dropConstrainedForeignId('serial_intake_id');
        });
    }
};
