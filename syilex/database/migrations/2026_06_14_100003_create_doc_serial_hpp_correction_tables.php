<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — Koreksi HPP Serial (per-unit).
 * Mengoreksi harga_modal & cost_per_unit tiap unit (biaya pokok per unit), terpisah
 * dari avg_cost agregat produk (default: TIDAK mengubah avg_cost — lihat §2B).
 * Alur draft → approved (apply saat approve), audit nilai lama→baru via `before` JSON.
 * Prefix dokumen: HPS.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_serial_hpp_correction', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique(); // prefix HPS
            $table->dateTime('tanggal');
            $table->foreignId('product_id')->constrained('master_produk');
            $table->unsignedInteger('total_unit')->default(0);
            $table->string('status', 20)->default('draft'); // draft | approved | cancelled
            $table->dateTime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->text('notes')->nullable(); // alasan koreksi
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            $table->index('tanggal');
            $table->index('product_id');
            $table->index('status');
        });

        Schema::create('doc_serial_hpp_correction_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correction_id')->constrained('doc_serial_hpp_correction')->cascadeOnDelete();
            $table->foreignId('serial_unit_id')->constrained('serial_units');

            // Nilai BARU (diterapkan ke unit saat approve)
            $table->decimal('harga_modal_baru', 15, 2);
            $table->decimal('cost_per_unit_baru', 15, 4);

            // Snapshot nilai LAMA (audit) — { harga_modal, cost_per_unit }
            $table->json('before')->nullable();

            $table->timestamps();

            $table->index('correction_id');
            $table->index('serial_unit_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_serial_hpp_correction_detail');
        Schema::dropIfExists('doc_serial_hpp_correction');
    }
};
