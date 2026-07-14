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
        Schema::create('doc_stock_opname_detail', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('opname_id')->constrained('doc_stock_opname')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('master_produk');
            $table->integer('qty_system')->default(0);
            $table->integer('qty_physical')->default(0);
            $table->integer('qty_difference')->default(0);
            $table->string('notes', 255)->nullable();

            $table->index('opname_id');
            $table->index('product_id');
            $table->unique(['opname_id', 'product_id'], 'unique_product_per_opname');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_stock_opname_detail');
    }
};
