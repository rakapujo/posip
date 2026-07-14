<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — atribut kondisi per-unit (elektronik bekas/grade).
 * Semua nullable & additive: unit lama tetap valid (kosong).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->string('grade', 5)->nullable()->after('harga_jual');            // A..F
            $table->string('battery_condition', 30)->nullable()->after('grade');    // Original/Replacement/Service Center/Refurbished
            $table->decimal('battery_health', 5, 2)->nullable()->after('battery_condition'); // 0..100 (%) — honor format persen global
            $table->string('account_status', 20)->nullable()->after('battery_health'); // locked/unlocked
        });
    }

    public function down(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->dropColumn(['grade', 'battery_condition', 'battery_health', 'account_status']);
        });
    }
};
