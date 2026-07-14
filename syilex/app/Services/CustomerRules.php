<?php

namespace App\Services;

use App\Models\MasterCustomer;
use App\Models\MasterKategoriCustomer;
use App\Models\MasterTipeCustomer;

class CustomerRules
{
    public static function walkInTypeChangeBlockMessage(MasterCustomer $customer, string $requestedJenis): ?string
    {
        if ($customer->isWalkIn() && $requestedJenis !== 'walk_in') {
            return 'Customer Walk-in tidak dapat diubah menjadi Spesifik';
        }

        return null;
    }

    public static function inactiveTipeBlockMessage(?MasterTipeCustomer $tipe, ?int $currentTipeId): ?string
    {
        if ($tipe && $tipe->id !== $currentTipeId && ! $tipe->isActive()) {
            return 'Tipe Customer tidak aktif';
        }

        return null;
    }

    public static function inactiveKategoriBlockMessage(?MasterKategoriCustomer $kategori, ?int $currentKategoriId): ?string
    {
        if ($kategori && $kategori->id !== $currentKategoriId && ! $kategori->isActive()) {
            return 'Kategori Customer tidak aktif';
        }

        return null;
    }

    public static function storeInactiveTipeBlockMessage(?MasterTipeCustomer $tipe): ?string
    {
        if ($tipe && ! $tipe->isActive()) {
            return 'Tipe Customer tidak aktif';
        }

        return null;
    }

    public static function storeInactiveKategoriBlockMessage(?MasterKategoriCustomer $kategori): ?string
    {
        if ($kategori && ! $kategori->isActive()) {
            return 'Kategori Customer tidak aktif';
        }

        return null;
    }

    public static function deactivationBlockMessage(MasterCustomer $customer): ?string
    {
        if ($customer->isWalkIn() && $customer->status === 'active') {
            return 'Customer Walk-in tidak dapat dinonaktifkan';
        }

        $terminalCount = $customer->posTerminals()->count();
        if ($terminalCount > 0) {
            return "Tidak dapat menonaktifkan Customer karena masih digunakan sebagai default oleh {$terminalCount} terminal POS";
        }

        return null;
    }

    public static function deletionBlockMessage(MasterCustomer $customer): ?string
    {
        if ($customer->isWalkIn()) {
            return 'Customer Walk-in tidak dapat dihapus';
        }

        $terminalCount = $customer->posTerminals()->count();
        if ($terminalCount > 0) {
            return "Tidak dapat menghapus Customer karena masih digunakan sebagai default oleh {$terminalCount} terminal POS";
        }

        $salesCount = $customer->sales()->count();
        if ($salesCount > 0) {
            return "Tidak dapat menghapus Customer karena memiliki {$salesCount} transaksi penjualan";
        }

        return null;
    }
}
