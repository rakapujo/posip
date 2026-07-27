<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mirror battery_cycle_count pada detail Serial Change (nilai baru + before JSON).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doc_serial_change_detail', function (Blueprint $table) {
            $table->unsignedInteger('battery_cycle_count')->nullable()->after('battery_health');
        });
    }

    public function down(): void
    {
        Schema::table('doc_serial_change_detail', function (Blueprint $table) {
            $table->dropColumn('battery_cycle_count');
        });
    }
};
