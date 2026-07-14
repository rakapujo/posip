<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modul Serial (A+) — Perubahan Data Serial (header).
 * Koreksi data unit TERSEDIA suatu produk serial: harga jual + SN + atribut (grade/baterai/akun/catatan).
 * harga_modal DIKECUALIKAN (memengaruhi HPP). Alur draft → approved (apply saat approve), audit lama→baru.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doc_serial_change', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique(); // prefix PDS
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
    }

    public function down(): void
    {
        Schema::dropIfExists('doc_serial_change');
    }
};
