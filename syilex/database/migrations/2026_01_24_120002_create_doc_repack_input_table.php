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
        Schema::create('doc_repack_input', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('repack_id')->constrained('doc_repack')->onDelete('cascade');
            $table->foreignId('product_id')->constrained('master_produk');
            $table->unsignedInteger('qty');
            $table->decimal('cost_per_unit', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);

            $table->index('repack_id');
            $table->index('product_id');
            $table->unique(['repack_id', 'product_id'], 'unique_input_product_per_repack');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('doc_repack_input');
    }
};
