<?php

namespace App\Services;

use App\Models\DocPurchaseOrder;
use App\Models\DocPurchaseReturn;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;

class PurchaseMasterRules
{
    /**
     * @return array<string, list<string>>|null
     */
    public static function supplierAndWarehouseErrors(int $supplierId, int $warehouseId): ?array
    {
        $errors = array_merge(
            self::supplierErrors($supplierId) ?? [],
            self::warehouseErrors($warehouseId) ?? [],
        );

        return $errors !== [] ? $errors : null;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public static function supplierErrors(int $supplierId): ?array
    {
        $supplier = MasterSupplier::find($supplierId);
        if ($message = SupplierRules::purchaseBlockMessage($supplier)) {
            return ['supplier_id' => [$message]];
        }

        return null;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public static function warehouseErrors(int $warehouseId, string $field = 'warehouse_id'): ?array
    {
        $warehouse = MasterWarehouse::find($warehouseId);
        if (! $warehouse || ! $warehouse->isActive()) {
            return [$field => ['Warehouse tidak aktif.']];
        }

        return null;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public static function transferWarehouseErrors(int $warehouseFromId, int $warehouseToId): ?array
    {
        $errors = array_merge(
            self::warehouseErrors($warehouseFromId, 'warehouse_from_id') ?? [],
            self::warehouseErrors($warehouseToId, 'warehouse_to_id') ?? [],
        );

        return $errors !== [] ? $errors : null;
    }

    /**
     * @param  list<array{product_id: int}>  $details
     * @return array<string, list<string>>|null
     */
    public static function poDetailProductErrors(array $details): ?array
    {
        return self::detailProductErrors($details, blockSerial: true);
    }

    /**
     * @param  list<array{product_id: int}>  $details
     * @return array<string, list<string>>|null
     */
    public static function returDetailProductErrors(array $details): ?array
    {
        return self::detailProductErrors($details, blockSerial: false);
    }

    /**
     * @return array<string, list<string>>|null
     */
    public static function poDocumentErrors(DocPurchaseOrder $po): ?array
    {
        $errors = self::supplierAndWarehouseErrors($po->supplier_id, $po->warehouse_id) ?? [];

        $po->loadMissing('details');
        $details = $po->details->map(fn ($detail) => ['product_id' => $detail->product_id])->all();

        return array_merge($errors, self::poDetailProductErrors($details) ?? []) ?: null;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public static function purchaseReturnDocumentErrors(DocPurchaseReturn $retur): ?array
    {
        $errors = self::supplierAndWarehouseErrors($retur->supplier_id, $retur->warehouse_id) ?? [];

        $retur->loadMissing('details');
        $details = $retur->details->map(fn ($detail) => ['product_id' => $detail->product_id])->all();

        return array_merge($errors, self::returDetailProductErrors($details) ?? []) ?: null;
    }

    /**
     * Validasi produk aktif untuk modul inventory (adjustment, transfer, opname, repack, HPP).
     *
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, list<string>>|null
     */
    public static function inventoryProductLinesErrors(array $lines, string $prefix = 'details', string $field = 'product_id'): ?array
    {
        $details = [];
        foreach ($lines as $line) {
            $details[] = ['product_id' => $line[$field]];
        }

        $baseErrors = self::returDetailProductErrors($details);
        if ($baseErrors === null) {
            return null;
        }

        $remapped = [];
        foreach ($baseErrors as $key => $messages) {
            if (preg_match('/^details\.(\d+)\.product_id$/', $key, $matches)) {
                $remapped["{$prefix}.{$matches[1]}.{$field}"] = $messages;
            }
        }

        return $remapped !== [] ? $remapped : null;
    }

    /**
     * @param  list<array{product_id: int}>  $details
     * @return array<string, list<string>>|null
     */
    private static function detailProductErrors(array $details, bool $blockSerial): ?array
    {
        $errors = [];
        $productIds = array_unique(array_column($details, 'product_id'));

        if ($productIds === []) {
            return null;
        }

        $products = MasterProduk::whereIn('id', $productIds)->get()->keyBy('id');

        foreach ($details as $index => $detail) {
            $productId = $detail['product_id'];
            $product = $products->get($productId);
            $field = "details.{$index}.product_id";

            if (! $product) {
                $errors[$field] = ['Produk tidak ditemukan.'];

                continue;
            }

            if (! $product->isActive()) {
                $errors[$field] = ['Produk tidak aktif.'];

                continue;
            }

            if ($blockSerial && $product->is_serial) {
                $errors[$field] = ['Produk serial hanya dapat dibeli melalui PO Serial.'];
            }
        }

        return $errors !== [] ? $errors : null;
    }
}
