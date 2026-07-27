<?php

namespace App\Actions\Sales\Concerns;

use App\Actions\Serial\Concerns\ResolvesSelectedUnits;
use App\Models\DocSales;
use App\Models\DocSalesDetail;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use App\Models\StockCard;
use App\Services\SettingService;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

trait PostsSalesInventory
{
    use ResolvesSelectedUnits;

    /**
     * Details may contain detail_id to update an existing draft row.
     */
    protected function postSalesInventory(DocSales $sales, array $items): Collection
    {
        $productIds = array_values(array_unique(array_column($items, 'product_id')));
        $stocks = InventoryStock::where('warehouse_id', $sales->warehouse_id)
            ->whereIn('product_id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('product_id');
        $products = MasterProduk::whereIn('id', $productIds)
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
        $serialIds = collect($items)->flatMap(fn ($item) => $item['serial_unit_ids'] ?? [])->filter()->unique();
        $lockedSerialUnits = $serialIds->isEmpty()
            ? collect()
            : SerialUnit::whereIn('ulid', $serialIds)->lockForUpdate()->get()->keyBy('ulid');

        $errors = [];
        foreach ($items as $item) {
            $product = $products->get($item['product_id']);
            if (! $product) {
                $errors[] = 'Produk tidak ditemukan.';

                continue;
            }
            $stock = (float) ($stocks->get($item['product_id'])?->qty ?? 0);
            if ($stock < (float) $item['qty_base'] && ! SettingService::isNegativeStockAllowed()) {
                $errors[] = "Stok {$product->nama_produk} tidak mencukupi. Tersedia: {$stock}, Dibutuhkan: {$item['qty_base']}";
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages(['stock' => $errors]);
        }

        $runningStocks = $stocks->mapWithKeys(fn ($stock, $id) => [$id => (float) $stock->qty])->all();
        $posted = collect();
        StockCard::$skipObserver = true;

        try {
            foreach ($items as $item) {
                $product = $products[$item['product_id']];
                $currentStock = $runningStocks[$item['product_id']] ?? 0;
                $isSerial = SettingService::isElektronikEnabled() && (bool) $product->is_serial;
                $oldAvg = (float) $product->avg_cost;
                $serialUnits = null;

                if ($isSerial) {
                    $serialUnits = $this->resolveSelectedUnits(
                        $item['serial_unit_ids'] ?? [],
                        (int) $item['product_id'],
                        (int) $sales->warehouse_id,
                        (int) $item['qty_base'],
                        lockedUnits: $lockedSerialUnits,
                    );
                    $hpp = round((float) $serialUnits->sum(fn ($unit) => (float) $unit->cost_per_unit) / $serialUnits->count(), 4);
                } else {
                    $hpp = $oldAvg;
                }

                $values = [
                    'sales_id' => $sales->id,
                    'product_id' => $item['product_id'],
                    'unit' => $item['unit'],
                    'konversi' => $item['konversi'],
                    'qty' => $item['qty'],
                    'qty_base' => $item['qty_base'],
                    'harga_satuan' => $item['harga_satuan'],
                    'diskon_total' => $item['diskon_total'],
                    'jumlah' => $item['jumlah'],
                    'promo_id' => $item['promo_id'] ?? null,
                    'hpp_at_time' => $hpp,
                    'serial_unit_ids' => $isSerial ? $serialUnits->pluck('ulid')->all() : null,
                ];
                for ($slot = 1; $slot <= 5; $slot++) {
                    foreach (['tipe', 'nilai', 'hasil'] as $field) {
                        $values["diskon_{$slot}_{$field}"] = $item["diskon_{$slot}_{$field}"];
                    }
                }
                if (isset($item['detail_id'])) {
                    $detail = DocSalesDetail::where('sales_id', $sales->id)->findOrFail($item['detail_id']);
                    $detail->update($values);
                } else {
                    $detail = DocSalesDetail::create($values);
                }
                $posted->push($detail);

                $newStock = $currentStock - (int) $item['qty_base'];
                $runningStocks[$item['product_id']] = $newStock;
                $avgAfter = $isSerial ? $oldAvg : $hpp;

                if ($isSerial) {
                    foreach ($serialUnits as $unit) {
                        $unit->update([
                            'status' => SerialUnit::STATUS_TERJUAL,
                            'sale_id' => $sales->id,
                            'sale_detail_id' => $detail->id,
                            'sold_at' => $sales->tanggal,
                        ]);
                        SerialUnitMovement::record([
                            'serial_unit_id' => $unit->id,
                            'doc_type' => 'SALES',
                            'doc_id' => $sales->id,
                            'doc_no' => $sales->nomor_dokumen,
                            'movement_type' => 'OUT',
                            'from_warehouse_id' => $sales->warehouse_id,
                            'to_warehouse_id' => null,
                            'from_status' => SerialUnit::STATUS_TERSEDIA,
                            'to_status' => SerialUnit::STATUS_TERJUAL,
                            'tanggal' => $sales->tanggal,
                            'notes' => null,
                        ]);
                    }
                    $remaining = SerialUnit::byProduct((int) $item['product_id'])->tersedia()->get(['cost_per_unit']);
                    $avgAfter = $remaining->isEmpty()
                        ? 0.0
                        : round((float) $remaining->sum(fn ($unit) => (float) $unit->cost_per_unit) / $remaining->count(), 4);
                    $product->update(['avg_cost' => $avgAfter]);
                    $product->syncAvgCostToInventoryStocks();
                }

                InventoryStock::updateOrCreate(
                    ['product_id' => $item['product_id'], 'warehouse_id' => $sales->warehouse_id],
                    ['qty' => $newStock, 'avg_cost' => $avgAfter],
                );
                StockCard::record([
                    'product_id' => $item['product_id'],
                    'warehouse_id' => $sales->warehouse_id,
                    'transaction_type' => 'SALES',
                    'transaction_id' => $sales->id,
                    'transaction_no' => $sales->nomor_dokumen,
                    'tanggal' => $sales->tanggal,
                    'qty_in' => 0,
                    'qty_out' => $item['qty_base'],
                    'cost_per_unit' => $hpp,
                    'avg_cost_before' => $oldAvg,
                    'avg_cost_after' => $avgAfter,
                    'notes' => null,
                ]);

                if (! $isSerial) {
                    $product->checkAndResetHppIfStockEmpty(
                        $sales->warehouse_id,
                        $sales->id,
                        $sales->nomor_dokumen,
                        $sales->tanggal,
                    );
                }
            }
        } finally {
            StockCard::$skipObserver = false;
        }

        return $posted;
    }
}
