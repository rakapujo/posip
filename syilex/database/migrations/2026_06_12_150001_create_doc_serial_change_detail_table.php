<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — detail Perubahan Data Serial: nilai BARU per unit + snapshot lama (audit).
 * Kolom = nilai baru yang akan diterapkan; `before` (JSON) = snapshot nilai lama saat dibuat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_serial_change_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('change_id')->constrained('doc_serial_change')->cascadeOnDelete();
            $table->foreignId('serial_unit_id')->constrained('serial_units');

            // Nilai BARU (yang akan diterapkan ke unit saat approve)
            $table->string('serial_number', 100);
            $table->decimal('harga_jual', 15, 2)->nullable();
            $table->string('grade', 5)->nullable();
            $table->string('battery_condition', 30)->nullable();
            $table->decimal('battery_health', 5, 2)->nullable();
            $table->string('account_status', 20)->nullable();
            $table->text('catatan')->nullable();

            // Snapshot nilai LAMA (audit) — { serial_number, harga_jual, grade, ... }
            $table->json('before')->nullable();

            $table->timestamps();

            $table->index('change_id');
            $table->index('serial_unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_serial_change_detail');
    }
};
