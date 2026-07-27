<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Battery cycle count per serial unit (elektronik bekas).
 * Nullable di DB agar unit lama tetap valid; wajib di API intake / Serial Change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->unsignedInteger('battery_cycle_count')->nullable()->after('battery_health');
        });
    }

    public function down(): void
    {
        Schema::table('serial_units', function (Blueprint $table) {
            $table->dropColumn('battery_cycle_count');
        });
    }
};
