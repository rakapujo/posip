<?php

namespace App\Actions\Sales\Concerns;

use App\Models\MasterProduk;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Kembalikan unit serial yang TERJUAL menjadi TERSEDIA (Void / Retur Penjualan).
 * Kunci unit, validasi (milik produk, status terjual, dari penjualan ini, opsional
 * dari sales_detail tertentu), lalu reset status + putus tautan sale + catat movement IN.
 * Plus helper rekalkulasi avg_cost agregat (Metode A). Dipakai bersama (DRY).
 */
trait RevertsSerialUnits
{
    /**
     * @param  array     $ulids                 ulid unit yang dikembalikan.
     * @param  int       $saleId                Penjualan asal (validasi sale_id unit).
     * @param  int       $productId             Produk yang diharapkan.
     * @param  int       $warehouseId           Gudang tujuan stok kembali (unit di-set ke sini).
     * @param  string    $docType               doc_type movement (VOID / SALES_RETURN).
     * @param  int|null  $docId                 id dokumen pembalik.
     * @param  string|null $docNo               nomor dokumen pembalik.
     * @param  mixed     $tanggal               tanggal movement.
     * @param  int|null  $expectedSaleDetailId  Bila diisi, unit wajib dari sales_detail ini.
     * @return Collection<int, SerialUnit>
     *
     * @throws ValidationException
     */
    protected function revertSoldUnits(
        array $ulids,
        int $saleId,
        int $productId,
        int $warehouseId,
        string $docType,
        ?int $docId,
        ?string $docNo,
        $tanggal,
        ?int $expectedSaleDetailId = null
    ): Collection {
        $ulids = array_values(array_unique(array_filter($ulids, fn ($u) => $u !== null && $u !== '')));

        if (count($ulids) === 0) {
            throw ValidationException::withMessages([
                'serial_unit_ids' => ['Belum ada unit serial yang dipilih untuk dikembalikan.'],
            ]);
        }

        $units = SerialUnit::whereIn('ulid', $ulids)
            ->lockForUpdate()
            ->get()
            ->keyBy('ulid');

        $errors = [];
        $missing = array_diff($ulids, $units->keys()->all());
        if (count($missing) > 0) {
            $errors[] = 'Sebagian unit serial tidak ditemukan.';
        }

        foreach ($units as $unit) {
            if ((int) $unit->product_id !== $productId) {
                $errors[] = "Unit {$unit->kode_internal} (SN {$unit->serial_number}) bukan milik produk ini.";
            } elseif ($unit->status !== SerialUnit::STATUS_TERJUAL) {
                $errors[] = "Unit {$unit->kode_internal} (SN {$unit->serial_number}) tidak berstatus terjual (sudah dikembalikan?).";
            } elseif ((int) $unit->sale_id !== $saleId) {
                $errors[] = "Unit {$unit->kode_internal} (SN {$unit->serial_number}) bukan dari penjualan ini.";
            } elseif ($expectedSaleDetailId !== null && (int) $unit->sale_detail_id !== $expectedSaleDetailId) {
                $errors[] = "Unit {$unit->kode_internal} (SN {$unit->serial_number}) bukan dari baris penjualan yang diretur.";
            }
        }

        if (count($errors) > 0) {
            throw ValidationException::withMessages(['serial_unit_ids' => array_values(array_unique($errors))]);
        }

        foreach ($units as $unit) {
            $unit->update([
                'status' => SerialUnit::STATUS_TERSEDIA,
                'sale_id' => null,
                'sale_detail_id' => null,
                'sold_at' => null,
                'warehouse_id' => $warehouseId,
            ]);

            SerialUnitMovement::record([
                'serial_unit_id' => $unit->id,
                'doc_type' => $docType,
                'doc_id' => $docId,
                'doc_no' => $docNo,
                'movement_type' => 'IN',
                'from_warehouse_id' => null,
                'to_warehouse_id' => $warehouseId,
                'from_status' => SerialUnit::STATUS_TERJUAL,
                'to_status' => SerialUnit::STATUS_TERSEDIA,
                'tanggal' => $tanggal,
                'notes' => null,
            ]);
        }

        return collect($ulids)->map(fn ($u) => $units->get($u))->values();
    }

    /**
     * Rekalkulasi avg_cost agregat produk serial = rata cost_per_unit unit TERSEDIA (0 bila habis).
     * Simpan + sync ke inventory_stock. Kembalikan avg baru.
     */
    protected function recomputeSerialAvgCost(MasterProduk $product): float
    {
        $tersedia = SerialUnit::byProduct($product->id)->tersedia()->get(['cost_per_unit']);
        $count = $tersedia->count();
        $newAvg = $count > 0 ? round((float) $tersedia->sum(fn ($u) => (float) $u->cost_per_unit) / $count, 4) : 0.0;

        $product->avg_cost = $newAvg;
        $product->save();
        $product->syncAvgCostToInventoryStocks();

        return $newAvg;
    }
}
