<?php

namespace App\Services;

use App\Models\DocSales;
use App\Models\DocSalesReturnDetail;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Kalkulasi retur jual BO.
 * Mode linked: parity rumus POS — pool = total_setelah_diskon × (1+pajak%),
 * tanpa biaya/pembulatan nota asal; pembulatan baru di dokumen retur (applyRounding sales).
 */
class SalesReturnCalculationService
{
    /** Status yang mengunci qty returnable (termasuk draft = soft reserve). */
    private const RETURNED_STATUSES = ['draft', 'lock', 'approved'];

    public static function validateReturnable(DocSales $sales, array $items, ?int $excludeReturnId = null): void
    {
        $sales->loadMissing('details.product');
        $detailMap = $sales->details->keyBy('id');
        $detailIds = array_column($items, 'sales_detail_id');

        if (count($detailIds) !== count(array_unique($detailIds))) {
            throw ValidationException::withMessages(['details' => ['Satu detail penjualan hanya boleh diretur sekali.']]);
        }

        $returned = DocSalesReturnDetail::whereIn('sales_detail_id', $detailIds)
            ->when($excludeReturnId, fn ($query) => $query->where('return_id', '!=', $excludeReturnId))
            ->whereHas('salesReturn', fn ($query) => $query->whereIn('status', self::RETURNED_STATUSES))
            ->selectRaw('sales_detail_id, SUM(qty_base) total')
            ->groupBy('sales_detail_id')
            ->pluck('total', 'sales_detail_id');

        $errors = [];
        foreach ($items as $item) {
            $detail = $detailMap->get($item['sales_detail_id']);
            $qty = (float) ($item['qty_base'] ?? 0);
            if (! $detail) {
                $errors[] = 'Detail penjualan tidak berasal dari nota yang dipilih.';

                continue;
            }

            $available = (float) $detail->qty_base - (float) ($returned[$detail->id] ?? 0);
            if ($qty <= 0 || $qty > $available) {
                $errors[] = "{$detail->product->nama_produk}: qty retur {$qty} melebihi sisa {$available}.";
            }

            if ($detail->product->is_serial) {
                $ulids = array_values(array_unique($item['serial_unit_ids'] ?? []));
                if (count($ulids) !== (int) $qty) {
                    $errors[] = "{$detail->product->nama_produk}: jumlah unit serial harus sama dengan qty retur.";

                    continue;
                }

                $validCount = SerialUnit::whereIn('ulid', $ulids)
                    ->where('sale_id', $sales->id)
                    ->where('sale_detail_id', $detail->id)
                    ->where('status', SerialUnit::STATUS_TERJUAL)
                    ->count();
                if ($validCount !== count($ulids)) {
                    $errors[] = "{$detail->product->nama_produk}: unit serial harus masih terjual dari baris nota ini.";
                }
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['details' => array_values(array_unique($errors))]);
        }
    }

    /**
     * Pool kredit retur (parity POS ProcessSalesReturnAction).
     * Exclude biaya_kirim/biaya_lain (+ tax-on-biaya) dan pembulatan nota asal.
     */
    public static function returnPool(DocSales $sales): float
    {
        $totalSetelahDiskon = (float) $sales->total_setelah_diskon;
        $pajakPersen = (float) $sales->pajak_persen;

        return $totalSetelahDiskon * (1 + $pajakPersen / 100);
    }

    /**
     * Harga per base unit per baris nota — rumus POS murni (tanpa remainder grand_total).
     *
     * @return array<int, float> detail_id => harga per base
     */
    public static function unitPrices(DocSales $sales): array
    {
        $sales->loadMissing('details');
        $salesSubtotal = (float) $sales->subtotal;
        $pool = self::returnPool($sales);
        $allocations = [];

        foreach ($sales->details as $detail) {
            $qtyBase = (float) $detail->qty_base;
            $lineJumlah = (float) $detail->jumlah;
            $proporsi = $salesSubtotal > 0 ? $lineJumlah / $salesSubtotal : 0;
            $totalPembelian = $proporsi * $pool;
            $allocations[$detail->id] = $qtyBase > 0 ? $totalPembelian / $qtyBase : 0;
        }

        return $allocations;
    }

    /**
     * @return array{details: array, subtotal: float, pembulatan: float, grand_total: float}
     */
    public static function calculate(DocSales $sales, array $items): array
    {
        $sales->loadMissing('details');
        $detailMap = $sales->details->keyBy('id');
        $allocations = self::unitPrices($sales);

        $calculated = [];
        foreach ($items as $item) {
            $detail = $detailMap->get($item['sales_detail_id']);
            if (! $detail) {
                throw ValidationException::withMessages([
                    'details' => ['Detail penjualan tidak berasal dari nota yang dipilih.'],
                ]);
            }

            $qtyBase = (float) ($item['qty_base'] ?? 0);
            if ($qtyBase <= 0) {
                throw ValidationException::withMessages(['details' => ['Qty retur harus lebih dari 0.']]);
            }

            $hargaPerBase = $allocations[$detail->id] ?? 0;
            $calculated[] = [
                'sales_detail_id' => $detail->id,
                'product_id' => $detail->product_id,
                'unit' => $detail->unit,
                'konversi' => 1,
                'qty' => $qtyBase,
                'qty_base' => $qtyBase,
                'harga_satuan' => $hargaPerBase,
                'jumlah' => round($qtyBase * $hargaPerBase, 2),
                'hpp_at_time' => (float) $detail->hpp_at_time,
                'serial_unit_ids' => $item['serial_unit_ids'] ?? null,
            ];
        }

        return self::finalizeTotals($calculated);
    }

    /**
     * Free-mode validation.
     * Serial: selalu terjual+customer+WH+manual.
     * Non-serial: cap sold/returned hanya jika sales_free_require_sold ON.
     *
     * @param  bool  $qtyAlreadyBase  true saat lock (qty_base sudah tersimpan); false saat create/update (qty in unit).
     */
    public static function validateFree(
        array $items,
        int $customerId,
        int $warehouseId,
        ?int $excludeReturnId = null,
        bool $qtyAlreadyBase = false
    ): void {
        $errors = [];
        $productIds = collect($items)->pluck('product_id')->map(fn ($id) => (int) $id)->filter()->unique()->values();
        $products = MasterProduk::whereIn('id', $productIds)->get()->keyBy('id');
        $requireSold = SettingService::isSalesFreeRequireSold();

        $soldBaseByProduct = $requireSold
            ? self::soldBaseByProduct($customerId, $warehouseId, $productIds->all())
            : [];
        $returnedBaseByProduct = $requireSold
            ? self::freeReturnedBaseByProduct($customerId, $warehouseId, $productIds->all(), $excludeReturnId)
            : [];

        $claimedBase = [];

        foreach ($items as $i => $item) {
            $qtyInput = (float) ($item['qty_base'] ?? 0);
            $harga = (float) ($item['harga_satuan'] ?? 0);
            $productId = (int) ($item['product_id'] ?? 0);
            if ($productId <= 0) {
                $errors[] = 'Baris #'.($i + 1).': produk wajib.';

                continue;
            }
            if ($qtyInput <= 0) {
                $errors[] = 'Baris #'.($i + 1).': qty harus > 0.';
            }
            if ($harga < 0) {
                $errors[] = 'Baris #'.($i + 1).': harga tidak boleh negatif.';
            }

            $product = $products->get($productId);
            if (! $product) {
                $errors[] = 'Baris #'.($i + 1).': produk tidak ditemukan.';

                continue;
            }

            if ($product->is_serial) {
                $ulids = array_values(array_unique($item['serial_unit_ids'] ?? []));
                $qtyCheck = $qtyAlreadyBase ? $qtyInput : $qtyInput;
                if (count($ulids) !== (int) $qtyCheck || abs($qtyCheck - (int) $qtyCheck) > 0.0001) {
                    $errors[] = "{$product->nama_produk}: jumlah unit serial harus sama dengan qty retur (bilangan bulat).";

                    continue;
                }

                $validCount = SerialUnit::whereIn('ulid', $ulids)
                    ->where('product_id', $productId)
                    ->where('status', SerialUnit::STATUS_TERJUAL)
                    ->whereHas('sale', function ($q) use ($customerId, $warehouseId) {
                        $q->where('source', 'manual')
                            ->where('status', 'completed')
                            ->where('customer_id', $customerId)
                            ->where('warehouse_id', $warehouseId);
                    })
                    ->count();
                if ($validCount !== count($ulids)) {
                    $errors[] = "{$product->nama_produk}: unit serial harus terjual dari penjualan BO customer/gudang ini.";
                }

                continue;
            }

            if (! $requireSold) {
                continue;
            }

            $konversi = self::resolveKonversi($product, $item['unit'] ?? null);
            $qtyBase = $qtyAlreadyBase ? $qtyInput : ($qtyInput * $konversi);
            $claimedBase[$productId] = ($claimedBase[$productId] ?? 0) + $qtyBase;
            $available = ($soldBaseByProduct[$productId] ?? 0) - ($returnedBaseByProduct[$productId] ?? 0);
            if ($claimedBase[$productId] > $available + 0.0001) {
                $errors[] = "{$product->nama_produk}: qty retur melebihi sisa terjual {$available} (base).";
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages(['details' => array_values(array_unique($errors))]);
        }
    }

    /**
     * Free-mode: harga dari payload; HPP dari avg_cost / serial cost; pembulatan dokumen sales.
     * Input qty_base dari FE = qty dalam satuan dipilih; disimpan sebagai qty * konversi.
     *
     * @return array{details: array, subtotal: float, pembulatan: float, grand_total: float}
     */
    public static function calculateFree(
        array $items,
        int $customerId,
        int $warehouseId,
        ?int $excludeReturnId = null
    ): array {
        self::validateFree($items, $customerId, $warehouseId, $excludeReturnId);
        $calculated = [];
        foreach ($items as $item) {
            $product = MasterProduk::findOrFail($item['product_id']);
            $unit = $item['unit'] ?? ($product->unit_1 ?: 'PCS');
            $konversi = $product->is_serial ? 1 : self::resolveKonversi($product, $unit);
            $qtyInUnit = (float) $item['qty_base'];
            $qtyBase = $product->is_serial ? $qtyInUnit : ($qtyInUnit * $konversi);
            $harga = (float) ($item['harga_satuan'] ?? 0);
            $hpp = (float) $product->avg_cost;
            if ($product->is_serial && ! empty($item['serial_unit_ids'])) {
                $units = SerialUnit::whereIn('ulid', $item['serial_unit_ids'])->get(['cost_per_unit']);
                if ($units->count() > 0) {
                    $hpp = (float) $units->sum(fn ($u) => (float) $u->cost_per_unit) / $units->count();
                }
            }

            $calculated[] = [
                'sales_detail_id' => null,
                'product_id' => $product->id,
                'unit' => $unit,
                'konversi' => $konversi,
                'qty' => $qtyInUnit,
                'qty_base' => $qtyBase,
                'harga_satuan' => $harga,
                'jumlah' => round($qtyInUnit * $harga, 2),
                'hpp_at_time' => round($hpp, 4),
                'serial_unit_ids' => $item['serial_unit_ids'] ?? null,
            ];
        }

        return self::finalizeTotals($calculated);
    }

    /**
     * @param  array<int, array>  $calculated
     * @return array{details: array, subtotal: float, pembulatan: float, grand_total: float}
     */
    private static function finalizeTotals(array $calculated): array
    {
        $subtotal = round(array_sum(array_column($calculated, 'jumlah')), 2);
        $grandTotal = SettingService::applyRounding($subtotal, 'sales');
        $pembulatan = round($grandTotal - $subtotal, 2);

        return [
            'details' => $calculated,
            'subtotal' => $subtotal,
            'pembulatan' => $pembulatan,
            'grand_total' => $grandTotal,
        ];
    }

    private static function resolveKonversi(MasterProduk $product, ?string $unit): int
    {
        $unit = $unit ?: ($product->unit_1 ?: 'PCS');
        for ($i = 1; $i <= 4; $i++) {
            if ($product->{"unit_{$i}"} === $unit) {
                return max(1, (int) ($product->{"konversi_{$i}"} ?: 1));
            }
        }

        return 1;
    }

    /**
     * @param  array<int>  $productIds
     * @return array<int, float>
     */
    private static function soldBaseByProduct(int $customerId, int $warehouseId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->join('master_produk as p', 'p.id', '=', 'd.product_id')
            ->where('s.source', 'manual')
            ->where('s.status', 'completed')
            ->where('s.customer_id', $customerId)
            ->where('s.warehouse_id', $warehouseId)
            ->where('p.is_serial', 0)
            ->whereIn('d.product_id', $productIds)
            ->groupBy('d.product_id')
            ->selectRaw('d.product_id, SUM(d.qty_base) as sold_qty')
            ->pluck('sold_qty', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }

    /**
     * Free returns: sum qty_base by product across draft/lock/approved (exclude self).
     *
     * @param  array<int>  $productIds
     * @return array<int, float>
     */
    private static function freeReturnedBaseByProduct(
        int $customerId,
        int $warehouseId,
        array $productIds,
        ?int $excludeReturnId
    ): array {
        if ($productIds === []) {
            return [];
        }

        return DocSalesReturnDetail::query()
            ->whereIn('product_id', $productIds)
            ->when($excludeReturnId, fn ($q) => $q->where('return_id', '!=', $excludeReturnId))
            ->whereHas('salesReturn', function ($q) use ($customerId, $warehouseId) {
                $q->where('source', 'manual')
                    ->whereIn('status', self::RETURNED_STATUSES)
                    ->where('customer_id', $customerId)
                    ->where('warehouse_id', $warehouseId);
            })
            ->selectRaw('product_id, SUM(qty_base) as total')
            ->groupBy('product_id')
            ->pluck('total', 'product_id')
            ->map(fn ($v) => (float) $v)
            ->all();
    }
}
