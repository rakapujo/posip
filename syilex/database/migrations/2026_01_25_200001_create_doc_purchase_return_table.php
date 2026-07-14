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
        Schema::create('doc_purchase_return', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->string('nomor_dokumen', 50)->unique();
            $table->date('tanggal');
            $table->foreignId('supplier_id')->constrained('master_supplier');
            $table->foreignId('warehouse_id')->constrained('master_warehouse');
            $table->foreignId('po_id')->nullable()->constrained('doc_purchase_order');

            // Subtotal
            $table->decimal('subtotal', 15, 2)->default(0);

            // Diskon Header (3 Line)
            $table->enum('diskon_1_tipe', ['none', 'percent', 'nominal'])->default('none');
            $table->decimal('diskon_1_nilai', 15, 2)->default(0);
            $table->decimal('diskon_1_hasil', 15, 2)->default(0);
            $table->enum('diskon_2_tipe', ['none', 'percent', 'nominal'])->default('none');
            $table->decimal('diskon_2_nilai', 15, 2)->default(0);
            $table->decimal('diskon_2_hasil', 15, 2)->default(0);
            $table->enum('diskon_3_tipe', ['none', 'percent', 'nominal'])->default('none');
            $table->decimal('diskon_3_nilai', 15, 2)->default(0);
            $table->decimal('diskon_3_hasil', 15, 2)->default(0);
            $table->decimal('total_diskon_header', 15, 2)->default(0);

            // DPP & Pajak
            $table->decimal('dpp', 15, 2)->default(0);
            $table->string('pajak_nama', 20)->nullable();
            $table->decimal('pajak_persen', 5, 2)->default(0);
            $table->decimal('pajak_nominal', 15, 2)->default(0);

            // Total
            $table->decimal('nilai_kalkulasi', 15, 2)->default(0);

            // Approval
            $table->decimal('nilai_diakui', 15, 2)->nullable();
            $table->decimal('selisih', 15, 2)->nullable();
            $table->text('catatan_approval')->nullable();

            // Status
            $table->enum('status', ['draft', 'lock', 'approved'])->default('draft');
            $table->text('notes')->nullable();

            // Lock info
            $table->datetime('locked_at')->nullable();
            $table->foreignId('locked_by')->nullable()->constrained('users');

            // Approval info
            $table->datetime('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');

            // Audit
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users');
            $table->foreignId('updated_by')->nullable()->constrained('users');

            // Indexes
            $table->index('tanggal');
            $table->index('status');
            $table->index(['supplier_id', 'tanggal']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_purchase_return');
    }
};
