<?php

namespace App\Services;

use App\Models\MasterProduk;
use Illuminate\Validation\ValidationException;

/**
 * Service for handling all PurchaseReturn calculations.
 *
 * Reuses discount calculation methods from PurchaseOrderCalculationService.
 * Simplified version without biaya kirim and biaya lain.
 */
class PurchaseReturnCalculationService
{
    /**
     * Calculate item discounts (5 lines, supports recursive and sum modes).
     * Reuses method from PurchaseOrderCalculationService.
     */
    public static function calculateItemDiscounts(float $hargaBruto, array $discounts): array
    {
        return PurchaseOrderCalculationService::calculateItemDiscounts($hargaBruto, $discounts);
    }

    /**
     * Calculate header discounts (3 lines, supports recursive and sum modes).
     * Reuses method from PurchaseOrderCalculationService.
     */
    public static function calculateHeaderDiscounts(float $subtotal, array $discounts): array
    {
        return PurchaseOrderCalculationService::calculateHeaderDiscounts($subtotal, $discounts);
    }

    /**
     * Calculate tax based on settings.
     * Reuses method from PurchaseOrderCalculationService.
     */
    public static function calculateTax(float $dpp): array
    {
        return PurchaseOrderCalculationService::calculateTax($dpp);
    }

    /**
     * Apply rounding to total based on purchase settings.
     */
    public static function applyRounding(float $amount): float
    {
        return SettingService::applyRounding($amount, 'purchase');
    }

    /**
     * Free-mode: non-serial cap net purchased from supplier+WH bila require_purchased ON.
     * Serial: selalu tersedia@WH; bila require ON harus intake.supplier_id match.
     * Input details: product_id, qty_in_base (atau qty_in_unit×konversi), serial_unit_ids.
     */
    public static function validateFreeHistory(
        array $details,
        int $supplierId,
        int $warehouseId,
        ?int $excludeReturnId = null
    ): void {
        if (! SettingService::isPurchaseFreeRequirePurchased()) {
            // Serial identity tetap dicek di prepare/lock; non-serial longgar.
            self::assertSerialUnitsAvailable($details, $warehouseId, null);

            return;
        }

        $errors = [];
        $productIds = collect($details)->pluck('product_id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $products = MasterProduk::whereIn('id', $productIds)->get()->keyBy('id');

        $purchased = self::purchasedBaseByProduct($supplierId, $warehouseId, $productIds->all());
        $returned = self::freeReturnedBaseByProduct($supplierId, $warehouseId, $productIds->all(), $excludeReturnId);
        $claimed = [];

        foreach ($details as $i => $detail) {
            $productId = (int) ($detail['product_id'] ?? 0);
            $product = $products->get($productId);
            if (! $product) {
                $errors[] = 'Baris #'.($i + 1).': produk tidak ditemukan.';

                continue;
            }

            if ($product->is_serial) {
                continue; // handled below
            }

            $qtyBase = (float) ($detail['qty_in_base'] ?? 0);
            if ($qtyBase <= 0 && isset($detail['qty_in_unit'], $detail['unit_konversi'])) {
                $qtyBase = (float) $detail['qty_in_unit'] * max(1, (int) $detail['unit_konversi']);
            }
            $claimed[$productId] = ($claimed[$productId] ?? 0) + $qtyBase;
            $available = ($purchased[$productId] ?? 0) - ($returned[$productId] ?? 0);
            if ($claimed[$productId] > $available + 0.0001) {
                $errors[] = "{$product->nama_produk}: qty retur melebihi sisa dibeli {$available} (base).";
            }
        }

        self::assertSerialUnitsAvailable($details, $warehouseId, $supplierId);

        if ($errors !== []) {
            throw ValidationException::withMessages(['details' => array_values(array_unique($errors))]);
        }
    }

    private static function assertSerialUnitsAvailable(array $details, int $warehouseId, ?int $supplierId): void
    {
        $errors = [];
        foreach ($details as $i => $detail) {
            $ulids = array_values(array_unique(array_filter($detail['serial_unit_ids'] ?? [])));
            if ($ulids === []) {
                continue;
            }
            $productId = (int) ($detail['product_id'] ?? 0);
            $q = \App\Models\SerialUnit::whereIn('ulid', $ulids)
                ->where('product_id', $productId)
                ->where('warehouse_id', $warehouseId)
                ->where('status', \App\Models\SerialUnit::STATUS_TERSEDIA);
            if ($supplierId) {
                $q->whereHas('intake', fn ($iq) => $iq->where('supplier_id', $supplierId));
            }
            if ($q->count() !== count($ulids)) {
                $errors[] = 'Baris #'.($i + 1).': unit serial harus tersedia di gudang'
                    .($supplierId ? ' dan asal supplier ini' : '').'.';
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['details' => array_values(array_unique($errors))]);
        }
    }

    /**
     * Product IDs with net purchased qty > 0 for free picker (require_purchased ON).
     *
     * @return array<int>
     */
    public static function purchasedProductIdsForPicker(int $supplierId, int $warehouseId): array
    {
        $purchased = self::purchasedBaseAll($supplierId, $warehouseId);
        $returned = self::freeReturnedBaseByProduct($supplierId, $warehouseId, array_keys($purchased), null);
        $ids = [];
        foreach ($purchased as $productId => $qty) {
            if (($qty - ($returned[$productId] ?? 0)) > 0.0001) {
                $ids[] = (int) $productId;
            }
        }

        return $ids;
    }

    /** @return array<int, float> */
    private static function purchasedBaseAll(int $supplierId, int $warehouseId): array
    {
        return \Illuminate\Support\Facades\DB::table('doc_purchase_order_detail as d')
            ->join('doc_purchase_order as po', 'po.id', '=', 'd.po_id')
            ->where('po.status', 'approved')
            ->where('po.supplier_id', $supplierId)
            ->where('po.warehouse_id', $warehouseId)
            ->groupBy('d.product_id')
            ->selectRaw('d.product_id, SUM(d.qty_in_base) as purchased_qty')
            ->pluck('purchased_qty', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** @param  array<int>  $productIds @return array<int, float> */
    private static function purchasedBaseByProduct(int $supplierId, int $warehouseId, array $productIds): array
    {
        if ($productIds === []) {
            return self::purchasedBaseAll($supplierId, $warehouseId);
        }

        return \Illuminate\Support\Facades\DB::table('doc_purchase_order_detail as d')
            ->join('doc_purchase_order as po', 'po.id', '=', 'd.po_id')
            ->where('po.status', 'approved')
            ->where('po.supplier_id', $supplierId)
            ->where('po.warehouse_id', $warehouseId)
            ->whereIn('d.product_id', $productIds)
            ->groupBy('d.product_id')
            ->selectRaw('d.product_id, SUM(d.qty_in_base) as purchased_qty')
            ->pluck('purchased_qty', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /** @param  array<int>  $productIds @return array<int, float> */
    private static function freeReturnedBaseByProduct(
        int $supplierId,
        int $warehouseId,
        array $productIds,
        ?int $excludeReturnId
    ): array {
        if ($productIds === []) {
            return [];
        }

        return \App\Models\DocPurchaseReturnDetail::query()
            ->whereIn('product_id', $productIds)
            ->when($excludeReturnId, fn ($q) => $q->where('retur_id', '!=', $excludeReturnId))
            ->whereHas('purchaseReturn', function ($q) use ($supplierId, $warehouseId) {
                $q->whereIn('status', ['draft', 'lock', 'approved'])
                    ->where('supplier_id', $supplierId)
                    ->where('warehouse_id', $warehouseId);
            })
            ->selectRaw('product_id, SUM(qty_in_base) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Calculate complete Purchase Return totals.
     *
     * @param array $data - Full return data including details and header discounts
     * @return array - Calculated totals
     */
    public static function calculateTotals(array $data): array
    {
        $details = $data['details'] ?? [];
        $productIds = collect($details)->pluck('product_id')->filter()->unique()->values()->all();
        $products = MasterProduk::whereIn('id', $productIds)->get()->keyBy('id');

        // Calculate each detail's discounts and subtotals
        $calculatedDetails = [];
        $totalSubtotal = 0;

        foreach ($details as $index => $detail) {
            $product = $products->get($detail['product_id'] ?? null);
            if (! $product) {
                throw ValidationException::withMessages([
                    "details.{$index}.product_id" => ['Produk tidak ditemukan.'],
                ]);
            }

            $qtyInUnit = (float) ($detail['qty_in_unit'] ?? 0);
            $unitUsed = (string) ($detail['unit_used'] ?? '');
            $unitKonversi = PurchaseMasterRules::resolveUnitKonversi(
                $product,
                $unitUsed,
                "details.{$index}.unit_used"
            );
            $qtyInBase = $qtyInUnit * $unitKonversi;

            // Calculate harga_bruto
            $hargaPerUnit = (float) ($detail['harga_per_unit'] ?? 0);
            $hargaBruto = $qtyInUnit * $hargaPerUnit;

            // Calculate harga_per_base
            $hargaPerBase = $unitKonversi > 0 ? $hargaPerUnit / $unitKonversi : 0;

            // Calculate item discounts
            $discounts = [];
            for ($i = 1; $i <= 5; $i++) {
                $discounts[] = [
                    'tipe' => $detail["diskon_{$i}_tipe"] ?? 'none',
                    'nilai' => $detail["diskon_{$i}_nilai"] ?? 0,
                ];
            }
            $discountResult = self::calculateItemDiscounts($hargaBruto, $discounts);

            $calculatedDetail = [
                'product_id' => $detail['product_id'],
                'po_detail_id' => $detail['po_detail_id'] ?? null,
                'unit_used' => $detail['unit_used'],
                'unit_konversi' => $unitKonversi,
                'qty_in_unit' => $qtyInUnit,
                'qty_in_base' => $qtyInBase,
                'harga_per_unit' => $hargaPerUnit,
                'harga_per_base' => round($hargaPerBase, 4),
                'harga_bruto' => $discountResult['harga_bruto'],
            ];

            // Add discount fields
            for ($i = 0; $i < 5; $i++) {
                $calculatedDetail["diskon_" . ($i + 1) . "_tipe"] = $discountResult['discounts'][$i]['tipe'];
                $calculatedDetail["diskon_" . ($i + 1) . "_nilai"] = $discountResult['discounts'][$i]['nilai'];
                $calculatedDetail["diskon_" . ($i + 1) . "_hasil"] = $discountResult['discounts'][$i]['hasil'];
            }

            $calculatedDetail['total_diskon_item'] = $discountResult['total_diskon'];
            $calculatedDetail['subtotal'] = $discountResult['subtotal'];

            $calculatedDetails[] = $calculatedDetail;
            $totalSubtotal += $discountResult['subtotal'];
        }

        // Calculate header discounts
        $headerDiscounts = [];
        for ($i = 1; $i <= 3; $i++) {
            $headerDiscounts[] = [
                'tipe' => $data["diskon_{$i}_tipe"] ?? 'none',
                'nilai' => $data["diskon_{$i}_nilai"] ?? 0,
            ];
        }
        $headerDiscountResult = self::calculateHeaderDiscounts($totalSubtotal, $headerDiscounts);

        // DPP = total after header discounts (no biaya tambahan for returns)
        $dpp = $headerDiscountResult['total_setelah_diskon'];

        // Calculate tax
        $taxResult = self::calculateTax($dpp);

        // Calculate nilai_kalkulasi (grand total) before rounding
        $nilaiSebelumPembulatan = $dpp + $taxResult['nominal'];

        // Apply rounding
        $nilaiKalkulasi = self::applyRounding($nilaiSebelumPembulatan);
        $pembulatan = $nilaiKalkulasi - $nilaiSebelumPembulatan;

        return [
            'details' => $calculatedDetails,
            'subtotal' => $totalSubtotal,
            'diskon_1_tipe' => $headerDiscountResult['discounts'][0]['tipe'],
            'diskon_1_nilai' => $headerDiscountResult['discounts'][0]['nilai'],
            'diskon_1_hasil' => $headerDiscountResult['discounts'][0]['hasil'],
            'diskon_2_tipe' => $headerDiscountResult['discounts'][1]['tipe'],
            'diskon_2_nilai' => $headerDiscountResult['discounts'][1]['nilai'],
            'diskon_2_hasil' => $headerDiscountResult['discounts'][1]['hasil'],
            'diskon_3_tipe' => $headerDiscountResult['discounts'][2]['tipe'],
            'diskon_3_nilai' => $headerDiscountResult['discounts'][2]['nilai'],
            'diskon_3_hasil' => $headerDiscountResult['discounts'][2]['hasil'],
            'total_diskon_header' => $headerDiscountResult['total_diskon'],
            'dpp' => $dpp,
            'pajak_nama' => $taxResult['name'],
            'pajak_persen' => $taxResult['percent'],
            'pajak_nominal' => $taxResult['nominal'],
            'total_sebelum_pembulatan' => $nilaiSebelumPembulatan,
            'pembulatan' => $pembulatan,
            'nilai_kalkulasi' => $nilaiKalkulasi,
        ];
    }
}
