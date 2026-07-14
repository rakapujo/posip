<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ledger histori unit serial — paralel `stock_card` tapi level UNIT.
 * Tiap perpindahan/perubahan state unit (transfer, adjustment-keluar, opname, retur,
 * koreksi HPP serial) dicatat satu baris di sini saat dokumen di-approve/lock.
 * Append-only (tanpa softDeletes). doc_id = id header dokumen sumber (bukan FK polimorfik).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_unit_movements', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();

            $table->foreignId('serial_unit_id')->constrained('serial_units')->cascadeOnDelete();

            // Dokumen sumber (polimorfik ringan — ditulis dalam transaksi yang sama)
            $table->string('doc_type', 30);          // TRANSFER|ADJUSTMENT|PURCHASE_RETURN|STOCK_OPNAME|HPP_SERIAL|SERIAL_INTAKE
            $table->unsignedBigInteger('doc_id');
            $table->string('doc_no', 50)->nullable();

            $table->string('movement_type', 20);     // TRANSFER_OUT|TRANSFER_IN|OUT|IN|STATUS_CHANGE

            $table->foreignId('from_warehouse_id')->nullable()->constrained('master_warehouse')->nullOnDelete();
            $table->foreignId('to_warehouse_id')->nullable()->constrained('master_warehouse')->nullOnDelete();
            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20)->nullable();

            $table->dateTime('tanggal');
            $table->text('notes')->nullable();

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            $table->index('serial_unit_id');
            $table->index(['doc_type', 'doc_id']);
            $table->index('tanggal');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('serial_unit_movements');
    }
};
