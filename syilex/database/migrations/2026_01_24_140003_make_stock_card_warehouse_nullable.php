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
        Schema::table('stock_card', function (Blueprint $table) {
            // Drop the existing foreign key constraint first
            $table->dropForeign(['warehouse_id']);

            // Make warehouse_id nullable
            $table->unsignedBigInteger('warehouse_id')->nullable()->change();

            // Re-add foreign key constraint with nullable support
            $table->foreign('warehouse_id')
                ->references('id')
                ->on('master_warehouse')
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stock_card', function (Blueprint $table) {
            // Drop the nullable foreign key
            $table->dropForeign(['warehouse_id']);

            // Make warehouse_id not nullable again
            $table->unsignedBigInteger('warehouse_id')->nullable(false)->change();

            // Re-add original foreign key constraint
            $table->foreign('warehouse_id')
                ->references('id')
                ->on('master_warehouse')
                ->cascadeOnDelete();
        });
    }
};
