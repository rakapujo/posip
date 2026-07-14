<?php

namespace App\Services;

use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use Illuminate\Validation\ValidationException;

class PosCheckoutRules
{
    /**
     * Defense-in-depth: mirror PosController master checks so direct action calls stay safe.
     *
     * @param  array<string, mixed>  $data
     *
     * @throws ValidationException
     */
    public static function assertCheckoutMastersValid(array $data): void
    {
        $errors = [];

        $warehouse = MasterWarehouse::find($data['warehouse_id'] ?? null);
        if (! $warehouse || ! $warehouse->isActive()) {
            $errors['warehouse_id'] = ['Warehouse tidak aktif. Silakan hubungi admin.'];
        }

        $customer = MasterCustomer::find($data['customer_id'] ?? null);
        if (! $customer || (! $customer->isActive() && ! $customer->isWalkIn())) {
            $errors['customer_id'] = ['Customer tidak aktif. Silakan pilih customer lain.'];
        }

        $productIds = array_unique(array_column($data['items'] ?? [], 'product_id'));
        if ($productIds !== []) {
            $inactiveProducts = MasterProduk::whereIn('id', $productIds)
                ->where('status', '!=', 'active')
                ->pluck('nama_produk');
            if ($inactiveProducts->isNotEmpty()) {
                $errors['items'] = ['Produk tidak aktif: '.$inactiveProducts->implode(', ')];
            }
        }

        $paymentMethodIds = array_unique(array_column($data['payments'] ?? [], 'metode_pembayaran_id'));
        if ($paymentMethodIds !== []) {
            $inactiveMethods = MasterMetodePembayaran::whereIn('id', $paymentMethodIds)
                ->where('status', '!=', 'active')
                ->pluck('nama_pembayaran');
            if ($inactiveMethods->isNotEmpty()) {
                $errors['payments'] = ['Metode pembayaran tidak aktif: '.$inactiveMethods->implode(', ')];
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}
