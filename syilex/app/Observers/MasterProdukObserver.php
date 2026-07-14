<?php

namespace App\Observers;

use App\Models\InventoryStock;
use App\Models\MasterProduk;

class MasterProdukObserver
{
    /**
     * Handle the MasterProduk "created" event.
     * Create initial stock records for all active warehouses.
     */
    public function created(MasterProduk $produk): void
    {
        // Only initialize if product is active
        if ($produk->status === 'active') {
            InventoryStock::initializeForProduct($produk->id);
        }
    }

    /**
     * Handle the MasterProduk "updated" event.
     * If product becomes active, ensure stock records exist.
     */
    public function updated(MasterProduk $produk): void
    {
        // If status changed to active, initialize missing stocks
        if ($produk->isDirty('status') && $produk->status === 'active') {
            InventoryStock::initializeForProduct($produk->id);
        }
    }
}
