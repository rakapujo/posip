<?php

namespace App\Observers;

use App\Models\InventoryStock;
use App\Models\MasterWarehouse;

class MasterWarehouseObserver
{
    /**
     * Handle the MasterWarehouse "created" event.
     * Create initial stock records for all active products.
     */
    public function created(MasterWarehouse $warehouse): void
    {
        // Only initialize if warehouse is active
        if ($warehouse->status === 'active') {
            InventoryStock::initializeForWarehouse($warehouse->id);
        }
    }

    /**
     * Handle the MasterWarehouse "updated" event.
     * If warehouse becomes active, ensure stock records exist.
     */
    public function updated(MasterWarehouse $warehouse): void
    {
        // If status changed to active, initialize missing stocks
        if ($warehouse->isDirty('status') && $warehouse->status === 'active') {
            InventoryStock::initializeForWarehouse($warehouse->id);
        }
    }
}
