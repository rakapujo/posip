<?php

namespace App\Services;

use App\Models\MasterWarehouse;

class WarehouseRules
{
    public static function deactivationBlockMessage(MasterWarehouse $warehouse): ?string
    {
        $terminalCount = $warehouse->posTerminals()->count();
        if ($terminalCount > 0) {
            return "Tidak dapat menonaktifkan Gudang karena masih digunakan oleh {$terminalCount} terminal POS";
        }

        if ($warehouse->inventoryStocks()->where('qty', '!=', 0)->exists()) {
            return 'Tidak dapat menonaktifkan Gudang karena masih memiliki stok.';
        }

        return null;
    }
}
