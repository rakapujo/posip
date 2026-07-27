<?php

namespace App\Services;

use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use Illuminate\Support\Collection;
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

        $terminal = MasterPosTerminal::find($data['terminal_id'] ?? null);
        if (! $terminal) {
            $errors['terminal_id'] = ['Terminal tidak ditemukan.'];
        } else {
            $expectedWh = (int) $terminal->warehouse_id;
            if ((int) ($data['warehouse_id'] ?? 0) !== $expectedWh) {
                $errors['warehouse_id'] = ['Warehouse harus sama dengan warehouse terminal.'];
            }
        }

        $warehouse = MasterWarehouse::find($data['warehouse_id'] ?? null);
        if (! $warehouse || ! $warehouse->isActive() || ! $warehouse->isSaleable()) {
            $errors['warehouse_id'] = ['Warehouse tidak aktif atau tidak dapat digunakan untuk POS. Silakan hubungi admin.'];
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

            if ($terminal) {
                $allowed = $terminal->allowedPaymentMethods()->allRelatedIds();
                if ($allowed->isEmpty()) {
                    $errors['payments'] = ['Terminal tidak memiliki metode pembayaran yang diizinkan.'];
                } elseif (collect($paymentMethodIds)->diff($allowed)->isNotEmpty()) {
                    $errors['payments'] = ['Metode pembayaran tidak diizinkan pada terminal ini.'];
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Anti-fraud: rebuild konversi / qty_base / harga_satuan from master (and serial harga_jual).
     *
     * @param  array<int, array<string, mixed>>  $items
     * @param  Collection<int|string, MasterProduk>  $products
     *
     * @throws ValidationException
     */
    public static function rebuildTrustedLineFields(array &$items, Collection $products): void
    {
        $serialUlids = collect($items)->flatMap(fn ($item) => $item['serial_unit_ids'] ?? [])->filter()->unique()->values();
        $serialUnits = $serialUlids->isEmpty()
            ? collect()
            : SerialUnit::whereIn('ulid', $serialUlids)->get()->keyBy('ulid');

        foreach ($items as &$item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                throw ValidationException::withMessages([
                    'items' => ['Produk tidak ditemukan.'],
                ]);
            }

            $resolved = self::resolveUnitFromMaster($product, (string) ($item['unit'] ?? ''));
            if ($resolved === null) {
                throw ValidationException::withMessages([
                    'items' => ["Unit '{$item['unit']}' tidak valid untuk {$product->nama_produk}."],
                ]);
            }

            $item['konversi'] = $resolved['konversi'];
            $item['qty_base'] = round((float) $item['qty'] * $resolved['konversi'], 4);

            $serialIds = $item['serial_unit_ids'] ?? null;
            if (is_array($serialIds) && $serialIds !== []) {
                $prices = [];
                foreach ($serialIds as $ulid) {
                    $unit = $serialUnits->get($ulid);
                    if (! $unit || $unit->harga_jual === null || (float) $unit->harga_jual < 1) {
                        throw ValidationException::withMessages([
                            'items' => ["Unit serial {$ulid} belum punya harga jual valid."],
                        ]);
                    }
                    $prices[] = (float) $unit->harga_jual;
                }
                $item['harga_satuan'] = round(array_sum($prices) / count($prices), 2);
            } else {
                if ($resolved['harga'] < 1) {
                    throw ValidationException::withMessages([
                        'items' => ["Harga {$product->nama_produk} belum diatur."],
                    ]);
                }
                $item['harga_satuan'] = $resolved['harga'];
            }
        }
        unset($item);
    }

    /**
     * @return array{konversi: int, harga: float}|null
     */
    public static function resolveUnitFromMaster(MasterProduk $product, string $unit): ?array
    {
        for ($i = 1; $i <= 4; $i++) {
            $unitName = $product->{"unit_{$i}"};
            if ($unitName === null || $unitName === '') {
                continue;
            }
            if (strcasecmp((string) $unitName, $unit) === 0) {
                return [
                    'konversi' => max(1, (int) ($product->{"konversi_{$i}"} ?? 1)),
                    'harga' => (float) ($product->{"harga_{$i}"} ?? 0),
                ];
            }
        }

        return null;
    }
}
