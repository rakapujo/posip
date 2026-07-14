<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adjustment-keluar (kredit) serial: status fate per unit dipilih user (Rusak/Hilang).
 * Map JSON {ulid: 'rusak'|'hilang'}. Null untuk non-serial / opname (opname → semua 'hilang').
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_adjustment_detail', function (Blueprint $table) {
            $table->json('serial_unit_statuses')->nullable()->after('serial_unit_ids');
        });
    }

    public function down(): void
    {
        Schema::table('doc_adjustment_detail', function (Blueprint $table) {
            $table->dropColumn('serial_unit_statuses');
        });
    }
};
