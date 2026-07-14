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
        Schema::create('doc_adjustment_detail', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('adjustment_id')->constrained('doc_adjustment')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('master_produk');
            $table->enum('jenis', ['debit', 'kredit']);
            $table->integer('stok_sistem');
            $table->unsignedInteger('qty');
            $table->integer('stok_akhir');
            $table->string('notes', 255)->nullable();

            $table->index('adjustment_id');
            $table->index('product_id');
            $table->unique(['adjustment_id', 'product_id'], 'unique_product_per_adjustment');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_adjustment_detail');
    }
};
