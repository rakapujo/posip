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
        Schema::create('inventory_stock', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('master_produk')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('master_warehouse')->cascadeOnDelete();
            $table->integer('qty')->default(0)->comment('Can be negative based on stock.negative_mode setting');
            $table->decimal('avg_cost', 15, 4)->default(0)->comment('Average cost / HPP');
            $table->timestamps();

            // Unique constraint: one stock record per product per warehouse
            $table->unique(['product_id', 'warehouse_id'], 'inventory_stock_product_warehouse_unique');

            // Indexes for common queries
            $table->index('warehouse_id');
            $table->index(['product_id', 'qty']); // For low stock queries
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_stock');
    }
};
