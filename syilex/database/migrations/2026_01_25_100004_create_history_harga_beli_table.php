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
        Schema::create('history_harga_beli', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('master_produk');
            $table->foreignId('supplier_id')->constrained('master_supplier');
            $table->foreignId('po_id')->constrained('doc_purchase_order');
            $table->foreignId('po_detail_id')->constrained('doc_purchase_order_detail');

            $table->date('tanggal');
            $table->string('unit_used', 30);
            $table->decimal('qty_in_unit', 15, 4);
            $table->decimal('qty_in_base', 15, 4);
            $table->decimal('harga_per_unit', 15, 2);
            $table->decimal('harga_per_base', 15, 4);

            $table->timestamp('created_at')->useCurrent();

            // INDEXES
            $table->index('product_id');
            $table->index('supplier_id');
            $table->index('tanggal');
            $table->index(['product_id', 'supplier_id', 'unit_used']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('history_harga_beli');
    }
};
