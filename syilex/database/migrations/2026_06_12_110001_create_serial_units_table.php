<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — Fase 2: register unit per nomor seri.
 * Satu baris = satu fisik unit. HPP (harga_modal) & harga_jual disimpan per-unit.
 * Produk tetap normal: qty agregat tetap di inventory_stock; ini ledger tambahan.
 * SN unik PER-PRODUK (di-enforce app-level non-trashed di CreateSerialIntakeAction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_units', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();

            // RELASI
            $table->foreignId('product_id')->constrained('master_produk');
            $table->foreignId('warehouse_id')->constrained('master_warehouse');
            $table->foreignId('intake_id')->nullable()->constrained('doc_serial_intake');

            // IDENTITAS UNIT
            $table->string('serial_number', 100);
            $table->decimal('harga_modal', 15, 2)->default(0); // HPP unit ini (dari intake)
            $table->decimal('harga_jual', 15, 2)->nullable();  // opsional, editable (bukan di POS)

            $table->enum('status', ['tersedia', 'terjual', 'rusak'])->default('tersedia');

            // PENJUALAN (diisi Fase 3 saat terjual; FK menyusul di Fase 3)
            $table->foreignId('sale_id')->nullable();
            $table->foreignId('sale_detail_id')->nullable();
            $table->dateTime('sold_at')->nullable();

            $table->text('catatan')->nullable();

            // AUDIT
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');
            $table->softDeletes();

            // INDEXES (SN unik per-produk di-enforce app-level)
            $table->index(['product_id', 'serial_number']);
            $table->index(['product_id', 'status']);
            $table->index('warehouse_id');
            $table->index('sale_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_units');
    }
};
