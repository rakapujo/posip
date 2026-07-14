<?php

namespace App\Observers;

use App\Models\InventoryStock;
use App\Models\StockCard;
use Illuminate\Support\Facades\Auth;

class InventoryStockObserver
{
    /**
     * Handle the InventoryStock "created" event.
     * Records initial stock entry in stock_card.
     */
    public function created(InventoryStock $inventoryStock): void
    {
        // Only record if qty > 0 (initial stock)
        if ($inventoryStock->qty != 0) {
            $this->recordStockCard(
                $inventoryStock,
                $inventoryStock->qty > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT',
                abs($inventoryStock->qty),
                'Stok Awal'
            );
        }
    }

    /**
     * Handle the InventoryStock "updated" event.
     * Records stock changes in stock_card when qty changes directly.
     */
    public function updated(InventoryStock $inventoryStock): void
    {
        // Check if qty was changed
        if ($inventoryStock->isDirty('qty')) {
            $oldQty = $inventoryStock->getOriginal('qty') ?? 0;
            $newQty = $inventoryStock->qty;
            $diff = $newQty - $oldQty;

            if ($diff != 0) {
                $this->recordStockCard(
                    $inventoryStock,
                    $diff > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT',
                    abs($diff),
                    'Penyesuaian Stok'
                );
            }
        }
    }

    /**
     * Record entry to stock_card.
     */
    private function recordStockCard(
        InventoryStock $inventoryStock,
        string $transactionType,
        int $qty,
        string $notes
    ): void {
        // Check if this change was triggered by StockCard::record() to avoid duplicate entries
        // We use a static flag to prevent recursion
        if (StockCard::$skipObserver ?? false) {
            return;
        }

        // Get GLOBAL avg_cost from product (not per-warehouse)
        $product = $inventoryStock->product;
        $globalAvgCost = $product ? (float) $product->avg_cost : 0;

        $qtyIn = in_array($transactionType, StockCard::TYPES_IN) ? $qty : 0;
        $qtyOut = in_array($transactionType, StockCard::TYPES_OUT) ? $qty : 0;

        StockCard::create([
            'product_id' => $inventoryStock->product_id,
            'warehouse_id' => $inventoryStock->warehouse_id,
            'transaction_type' => $transactionType,
            'transaction_id' => null,
            'transaction_no' => null,
            'tanggal' => now(),
            'qty_in' => $qtyIn,
            'qty_out' => $qtyOut,
            'qty_balance' => $inventoryStock->qty,
            'cost_per_unit' => $globalAvgCost,
            'total_cost' => $qty * $globalAvgCost,
            'avg_cost_before' => $globalAvgCost,
            'avg_cost_after' => $globalAvgCost,
            'notes' => $notes,
            'created_by' => Auth::id(),
        ]);
    }
}
