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
        Schema::create('doc_purchase_return_detail', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('retur_id')->constrained('doc_purchase_return')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('master_produk');
            $table->foreignId('po_detail_id')->nullable()->constrained('doc_purchase_order_detail');

            // Unit & Qty
            $table->string('unit_used', 30);
            $table->integer('unit_konversi')->default(1);
            $table->decimal('qty_in_unit', 15, 4);
            $table->decimal('qty_in_base', 15, 4);

            // Harga
            $table->decimal('harga_per_unit', 15, 2);
            $table->decimal('harga_per_base', 15, 4);
            $table->decimal('harga_bruto', 15, 2);

            // Diskon Item (5 Line)
            $table->enum('diskon_1_tipe', ['none', 'percent', 'nominal'])->default('none');
            $table->decimal('diskon_1_nilai', 15, 2)->default(0);
            $table->decimal('diskon_1_hasil', 15, 2)->default(0);
            $table->enum('diskon_2_tipe', ['none', 'percent', 'nominal'])->default('none');
            $table->decimal('diskon_2_nilai', 15, 2)->default(0);
            $table->decimal('diskon_2_hasil', 15, 2)->default(0);
            $table->enum('diskon_3_tipe', ['none', 'percent', 'nominal'])->default('none');
            $table->decimal('diskon_3_nilai', 15, 2)->default(0);
            $table->decimal('diskon_3_hasil', 15, 2)->default(0);
            $table->enum('diskon_4_tipe', ['none', 'percent', 'nominal'])->default('none');
            $table->decimal('diskon_4_nilai', 15, 2)->default(0);
            $table->decimal('diskon_4_hasil', 15, 2)->default(0);
            $table->enum('diskon_5_tipe', ['none', 'percent', 'nominal'])->default('none');
            $table->decimal('diskon_5_nilai', 15, 2)->default(0);
            $table->decimal('diskon_5_hasil', 15, 2)->default(0);
            $table->decimal('total_diskon_item', 15, 2)->default(0);
            $table->decimal('subtotal', 15, 2);

            $table->string('alasan', 255)->nullable();

            // Indexes
            $table->index('retur_id');
            $table->index('product_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_purchase_return_detail');
    }
};
