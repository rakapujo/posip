<?php

namespace App\Traits;

use App\Models\InventoryStock;

/**
 * Trait for actions that need inventory stock operations.
 */
trait HasInventoryStock
{
    /**
     * Get current stock for a product in a warehouse.
     */
    protected function getCurrentStock(int $productId, int $warehouseId): int
    {
        $stock = InventoryStock::where('product_id', $productId)
            ->where('warehouse_id', $warehouseId)
            ->first();

        return $stock ? (int) $stock->qty : 0;
    }
}
