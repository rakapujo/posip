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
        Schema::create('doc_price_change_detail', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_change_id')->constrained('doc_price_change')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('master_produk')->cascadeOnDelete();

            // Harga lama (captured saat create/update)
            $table->decimal('harga_1_lama', 15, 2)->default(0);
            $table->decimal('harga_2_lama', 15, 2)->default(0);
            $table->decimal('harga_3_lama', 15, 2)->default(0);
            $table->decimal('harga_4_lama', 15, 2)->default(0);

            // Harga baru (input user)
            $table->decimal('harga_1_baru', 15, 2);
            $table->decimal('harga_2_baru', 15, 2);
            $table->decimal('harga_3_baru', 15, 2);
            $table->decimal('harga_4_baru', 15, 2);

            // Alasan & notes
            $table->enum('alasan', [
                'PENYESUAIAN_PASAR',
                'KENAIKAN_BIAYA',
                'PROMO',
                'KOREKSI_DATA',
                'LAINNYA'
            ]);
            $table->text('notes')->nullable();
            $table->timestamps();

            // Indexes
            $table->index('product_id');
            $table->unique(['price_change_id', 'product_id'], 'price_change_product_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_price_change_detail');
    }
};
