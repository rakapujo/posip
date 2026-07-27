<?php

namespace App\Services;

use App\Models\CustomerDeposit;
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

    /** Walk-in = POS only; block on Sales/Retur/Piutang/Deposit BO. */
    public static function backofficeBlockMessage(?MasterCustomer $customer): ?string
    {
        if ($customer?->isWalkIn()) {
            return 'Customer Walk-in hanya untuk POS.';
        }

        return null;
    }

    /** Laravel rule closure: active + not walk-in. */
    public static function assertActiveBackofficeCustomer(mixed $value, \Closure $fail): void
    {
        $customer = MasterCustomer::find($value);
        if (! $customer?->isActive()) {
            $fail('Customer harus aktif.');

            return;
        }
        if ($message = self::backofficeBlockMessage($customer)) {
            $fail($message);
        }
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

        $outstandingCount = $customer->piutang()->outstanding()->count();
        if ($outstandingCount > 0) {
            return "Tidak dapat menonaktifkan Customer karena masih memiliki {$outstandingCount} piutang belum lunas";
        }

        $depositBalance = CustomerDeposit::getTotalAvailableByCustomer($customer->id);
        if ($depositBalance > 0) {
            return 'Tidak dapat menonaktifkan Customer karena masih memiliki sisa deposit Rp '
                .number_format($depositBalance, 0, ',', '.');
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

        $piutangCount = $customer->piutang()->count();
        if ($piutangCount > 0) {
            return "Tidak dapat menghapus Customer karena masih memiliki {$piutangCount} catatan piutang";
        }

        $depositCount = $customer->deposits()->count();
        if ($depositCount > 0) {
            return "Tidak dapat menghapus Customer karena masih memiliki {$depositCount} deposit";
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
