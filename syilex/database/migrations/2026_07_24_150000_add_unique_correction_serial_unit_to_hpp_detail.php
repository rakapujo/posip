<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * UNIQUE (correction_id, serial_unit_id) — cegah duplikat unit dalam satu koreksi HPP.
 */
return new class extends Migration
{
    public function up(): void
    {
        $indexes = Schema::getIndexes('doc_serial_hpp_correction_detail');
        $exists = collect($indexes)->contains(function (array $index) {
            return ($index['unique'] ?? false)
                && $index['columns'] === ['correction_id', 'serial_unit_id'];
        });

        if ($exists) {
            return;
        }

        Schema::table('doc_serial_hpp_correction_detail', function (Blueprint $table) {
            $table->unique(['correction_id', 'serial_unit_id'], 'hpp_serial_correction_unit_unique');
        });
    }

    public function down(): void
    {
        Schema::table('doc_serial_hpp_correction_detail', function (Blueprint $table) {
            $table->dropUnique('hpp_serial_correction_unit_unique');
        });
    }
};
