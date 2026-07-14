<?php

namespace App\Services;

use App\Models\MasterSupplier;
use App\Models\SupplierDeposit;

class SupplierRules
{
    public static function purchaseBlockMessage(?MasterSupplier $supplier): ?string
    {
        if (! $supplier) {
            return 'Supplier tidak valid.';
        }

        if (! $supplier->isActive()) {
            return 'Supplier tidak aktif.';
        }

        return null;
    }

    public static function deactivationBlockMessage(MasterSupplier $supplier): ?string
    {
        $outstandingCount = $supplier->hutangs()->outstanding()->count();
        if ($outstandingCount > 0) {
            return "Tidak dapat menonaktifkan Supplier karena masih memiliki {$outstandingCount} hutang belum lunas";
        }

        $depositBalance = SupplierDeposit::getTotalAvailableBySupplier($supplier->id);
        if ($depositBalance > 0) {
            return 'Tidak dapat menonaktifkan Supplier karena masih memiliki sisa deposit Rp '
                .number_format($depositBalance, 0, ',', '.');
        }

        return null;
    }

    public static function deletionBlockMessage(MasterSupplier $supplier): ?string
    {
        $poCount = $supplier->purchaseOrders()->count();
        if ($poCount > 0) {
            return "Tidak dapat menghapus Supplier karena masih memiliki {$poCount} Purchase Order";
        }

        $serialIntakeCount = $supplier->serialIntakes()->count();
        if ($serialIntakeCount > 0) {
            return "Tidak dapat menghapus Supplier karena masih memiliki {$serialIntakeCount} Pembelian Serial";
        }

        $returCount = $supplier->purchaseReturns()->count();
        if ($returCount > 0) {
            return "Tidak dapat menghapus Supplier karena masih memiliki {$returCount} Retur Pembelian";
        }

        $hutangCount = $supplier->hutangs()->count();
        if ($hutangCount > 0) {
            return "Tidak dapat menghapus Supplier karena masih memiliki {$hutangCount} catatan hutang";
        }

        $depositCount = $supplier->deposits()->count();
        if ($depositCount > 0) {
            return "Tidak dapat menghapus Supplier karena masih memiliki {$depositCount} deposit";
        }

        return null;
    }
}
