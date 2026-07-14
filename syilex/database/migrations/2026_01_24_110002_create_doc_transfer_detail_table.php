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
        Schema::create('doc_transfer_detail', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('transfer_id')->constrained('doc_transfer')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('master_produk');
            $table->unsignedInteger('qty');

            $table->index('transfer_id');
            $table->index('product_id');
            $table->unique(['transfer_id', 'product_id'], 'unique_product_per_transfer');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_transfer_detail');
    }
};
