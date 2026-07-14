<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — Fase 2: header dokumen Input Pembelian Serial.
 * Satu intake = satu produk serial, banyak unit (nomor seri). Lihat serial_units.
 * Perlakuan stok/HPP = seperti pembelian (qty+ ke inventory_stock, avg_cost weighted-avg).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_serial_intake', function (Blueprint $table) {
            // IDENTITAS
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique(); // prefix PBS

            // REFERENSI
            $table->dateTime('tanggal');
            $table->foreignId('product_id')->constrained('master_produk');
            $table->foreignId('warehouse_id')->constrained('master_warehouse');
            $table->foreignId('supplier_id')->nullable()->constrained('master_supplier');
            $table->string('no_doc_referensi', 100)->nullable(); // no nota supplier

            // RINGKASAN (dihitung dari units)
            $table->unsignedInteger('total_unit')->default(0);
            $table->decimal('total_modal', 15, 2)->default(0);

            // NOTES + STATUS (Fase 2: intake langsung final)
            $table->text('notes')->nullable();
            $table->enum('status', ['completed', 'cancelled'])->default('completed');

            // AUDIT
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            // INDEXES
            $table->index('tanggal');
            $table->index('product_id');
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_serial_intake');
    }
};
