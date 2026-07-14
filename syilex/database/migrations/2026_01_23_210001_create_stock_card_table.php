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
        Schema::create('stock_card', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('product_id')->constrained('master_produk')->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained('master_warehouse')->cascadeOnDelete();
            $table->enum('transaction_type', [
                'PURCHASE',
                'SALES',
                'PURCHASE_RETURN',
                'SALES_RETURN',
                'ADJUSTMENT_IN',
                'ADJUSTMENT_OUT',
                'STOCK_OPNAME',
                'TRANSFER_IN',
                'TRANSFER_OUT',
                'REPACK_IN',
                'REPACK_OUT',
                'HPP_RESET'
            ]);
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->string('transaction_no', 50)->nullable();
            $table->dateTime('tanggal');
            $table->integer('qty_in')->default(0);
            $table->integer('qty_out')->default(0);
            $table->integer('qty_balance')->default(0);
            $table->decimal('cost_per_unit', 15, 4)->default(0);
            $table->decimal('total_cost', 15, 2)->default(0);
            $table->decimal('avg_cost_before', 15, 4)->default(0);
            $table->decimal('avg_cost_after', 15, 4)->default(0);
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();

            // Indexes
            $table->index(['product_id', 'warehouse_id', 'tanggal'], 'stock_card_product_warehouse_date_idx');
            $table->index('transaction_type');
            $table->index('transaction_no');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_card');
    }
};
