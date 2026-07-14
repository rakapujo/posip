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
        Schema::create('doc_hpp_correction_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('correction_id')->constrained('doc_hpp_correction')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('master_produk')->cascadeOnDelete();
            $table->decimal('hpp_lama', 15, 4)->default(0);
            $table->decimal('hpp_baru', 15, 4);
            $table->enum('alasan', [
                'KOREKSI_HARGA_BELI',
                'KOREKSI_DATA',
                'MIGRASI_SISTEM',
                'LAINNYA'
            ]);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('product_id');
            $table->unique(['correction_id', 'product_id'], 'hpp_correction_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_hpp_correction_detail');
    }
};
