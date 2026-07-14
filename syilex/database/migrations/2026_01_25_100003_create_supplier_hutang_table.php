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
        Schema::create('supplier_hutang', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('supplier_id')->constrained('master_supplier');
            $table->foreignId('po_id')->constrained('doc_purchase_order');

            $table->date('tanggal');
            $table->date('tanggal_jatuh_tempo')->nullable();

            $table->decimal('nominal_awal', 15, 2);
            $table->decimal('nominal_terbayar', 15, 2)->default(0);
            $table->decimal('sisa_hutang', 15, 2);

            $table->enum('status', ['unpaid', 'partial', 'paid'])->default('unpaid');

            $table->timestamp('created_at')->useCurrent();

            // INDEXES
            $table->index('supplier_id');
            $table->index('po_id');
            $table->index('status');
            $table->index('tanggal_jatuh_tempo');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('supplier_hutang');
    }
};
