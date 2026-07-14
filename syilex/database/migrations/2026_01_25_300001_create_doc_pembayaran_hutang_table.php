<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // HEADER
        Schema::create('doc_pembayaran_hutang', function (Blueprint $table) {
            // IDENTITAS
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique();

            // REFERENSI
            $table->date('tanggal');
            $table->foreignId('supplier_id')->constrained('master_supplier');

            // PEMBAYARAN
            $table->decimal('total_bayar_cash', 15, 2)->default(0);
            $table->decimal('total_bayar_deposit', 15, 2)->default(0);
            $table->decimal('total_pembayaran', 15, 2)->default(0); // cash + deposit

            // METODE & REFERENSI
            $table->enum('metode_pembayaran', ['cash', 'transfer'])->default('cash');
            $table->string('no_referensi', 50)->nullable(); // Free text: nomor bukti/kwitansi
            $table->string('bank_nama', 50)->nullable(); // Untuk transfer
            $table->string('bank_rekening', 30)->nullable(); // Untuk transfer

            // NOTES
            $table->text('notes')->nullable();

            // STATUS
            $table->enum('status', ['draft', 'completed'])->default('draft');
            $table->datetime('completed_at')->nullable();
            $table->foreignId('completed_by')->nullable()->constrained('users');

            // AUDIT
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            // INDEXES
            $table->index('tanggal');
            $table->index('status');
            $table->index(['supplier_id', 'tanggal']);
        });

        // DETAIL (per Hutang)
        Schema::create('doc_pembayaran_hutang_detail', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('pembayaran_id')->constrained('doc_pembayaran_hutang')->cascadeOnDelete();
            $table->foreignId('hutang_id')->constrained('supplier_hutang');
            $table->decimal('nominal_dibayar', 15, 2);
            $table->enum('sumber', ['cash', 'deposit']);

            // INDEXES
            $table->index('pembayaran_id');
            $table->index('hutang_id');
        });

        // DEPOSIT USED
        Schema::create('doc_pembayaran_hutang_deposit', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pembayaran_id')->constrained('doc_pembayaran_hutang')->cascadeOnDelete();
            $table->foreignId('deposit_id')->constrained('supplier_deposit');
            $table->decimal('nominal_digunakan', 15, 2);

            // INDEXES
            $table->index('pembayaran_id');
            $table->index('deposit_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_pembayaran_hutang_deposit');
        Schema::dropIfExists('doc_pembayaran_hutang_detail');
        Schema::dropIfExists('doc_pembayaran_hutang');
    }
};
