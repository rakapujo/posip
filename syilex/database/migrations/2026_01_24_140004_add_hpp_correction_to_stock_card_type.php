<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Add HPP_CORRECTION to the transaction_type enum
        DB::statement("ALTER TABLE stock_card MODIFY COLUMN transaction_type ENUM(
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
            'HPP_RESET',
            'HPP_CORRECTION'
        )");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            return;
        }

        // Remove HPP_CORRECTION from the enum
        DB::statement("ALTER TABLE stock_card MODIFY COLUMN transaction_type ENUM(
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
        )");
    }
};
