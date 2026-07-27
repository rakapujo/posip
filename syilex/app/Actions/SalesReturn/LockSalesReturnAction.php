<?php

namespace App\Actions\SalesReturn;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\Sales\Concerns\RevertsSerialUnits;
use App\Models\DocSales;
use App\Models\DocSalesDetail;
use App\Models\DocSalesReturn;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\StockCard;
use App\Services\SalesReturnCalculationService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LockSalesReturnAction
{
    use RequiresAuthenticatedUser;
    use RevertsSerialUnits;

    public function execute(DocSalesReturn $return): DocSalesReturn
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($return) {
            $return = DocSalesReturn::manual()->lockForUpdate()->findOrFail($return->id);
            if (! $return->canLock()) {
                throw ValidationException::withMessages(['status' => ['Hanya retur draft yang dapat dikunci.']]);
            }

            $return->load('details.product');
            $isLinked = (bool) $return->sales_id;
            $sales = null;

            if ($isLinked) {
                $sales = DocSales::manual()->lockForUpdate()->findOrFail($return->sales_id);
                if (! $sales->isCompleted()) {
                    throw ValidationException::withMessages(['sales_id' => ['Penjualan asal tidak lagi completed.']]);
                }
                DocSalesDetail::where('sales_id', $sales->id)->lockForUpdate()->get();
                $sales->load('details.product');
                $items = $return->details->map(fn ($detail) => [
                    'sales_detail_id' => $detail->sales_detail_id,
                    'product_id' => $detail->product_id,
                    'qty_base' => $detail->qty_base,
                    'serial_unit_ids' => $detail->serial_unit_ids,
                ])->all();
                SalesReturnCalculationService::validateReturnable($sales, $items, $return->id);
            } else {
                $items = $return->details->map(fn ($detail) => [
                    'product_id' => $detail->product_id,
                    'qty_base' => $detail->qty_base,
                    'harga_satuan' => $detail->harga_satuan,
                    'unit' => $detail->unit,
                    'serial_unit_ids' => $detail->serial_unit_ids,
                ])->all();
                SalesReturnCalculationService::validateFree($items);
            }

            $productIds = $return->details->pluck('product_id')->unique()->values();
            $products = MasterProduk::whereIn('id', $productIds)->lockForUpdate()->get()->keyBy('id');
            $stocks = InventoryStock::where('warehouse_id', $return->warehouse_id)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $totals = [];
            $avgAfterByProduct = [];

            foreach ($return->details as $detail) {
                $product = $products->get($detail->product_id);
                if (! $product) {
                    throw ValidationException::withMessages(['details' => ['Produk retur tidak ditemukan.']]);
                }

                $hppBefore = (float) $product->avg_cost;
                $cost = (float) $detail->hpp_at_time;
                $hppAfter = $hppBefore;

                if ($product->is_serial) {
                    if ($isLinked) {
                        $units = $this->revertSoldUnits(
                            $detail->serial_unit_ids ?? [],
                            $sales->id,
                            $detail->product_id,
                            $return->warehouse_id,
                            'SALES_RETURN',
                            $return->id,
                            $return->nomor_dokumen,
                            $return->tanggal,
                            $detail->sales_detail_id,
                        );
                    } else {
                        $units = $this->revertSoldUnitsFree(
                            $detail->serial_unit_ids ?? [],
                            $detail->product_id,
                            $return->warehouse_id,
                            'SALES_RETURN',
                            $return->id,
                            $return->nomor_dokumen,
                            $return->tanggal,
                            $return->customer_id,
                        );
                    }
                    $cost = $units->count() > 0
                        ? (float) $units->sum(fn ($unit) => (float) $unit->cost_per_unit) / $units->count()
                        : $cost;
                    $detail->update(['hpp_at_time' => round($cost, 4)]);
                    // Metode A — parity POS void/retur serial
                    $hppAfter = $this->recomputeSerialAvgCost($product);
                    $products->put($product->id, $product->fresh());
                } elseif ($hppBefore == 0.0 && $cost > 0) {
                    // Mirror POS: restore avg bila sempat 0 setelah stok habis
                    $product->avg_cost = $cost;
                    $product->save();
                    $product->syncAvgCostToInventoryStocks();
                    $hppAfter = $cost;
                    $products->put($product->id, $product->fresh());
                }

                $avgAfterByProduct[$detail->product_id] = $hppAfter;

                $qty = (float) $detail->qty_base;
                $totals[$detail->product_id]['qty'] = ($totals[$detail->product_id]['qty'] ?? 0) + $qty;
                $totals[$detail->product_id]['cost'] = ($totals[$detail->product_id]['cost'] ?? 0) + ($qty * $cost);
                $totals[$detail->product_id]['hpp_before'] ??= $hppBefore;
            }

            $note = $isLinked
                ? "Retur backoffice dari {$sales->nomor_dokumen}"
                : 'Retur bebas (tanpa nota)';

            StockCard::$skipObserver = true;
            try {
                foreach ($totals as $productId => $total) {
                    $product = $products->get($productId);
                    $currentQty = (float) ($stocks->get($productId)?->qty ?? 0);
                    $avgBefore = (float) ($total['hpp_before'] ?? $product->avg_cost);
                    $avgAfter = (float) ($avgAfterByProduct[$productId] ?? $product->avg_cost);

                    InventoryStock::updateOrCreate(
                        ['product_id' => $productId, 'warehouse_id' => $return->warehouse_id],
                        ['qty' => $currentQty + $total['qty'], 'avg_cost' => $avgAfter],
                    );
                    StockCard::record([
                        'product_id' => $productId,
                        'warehouse_id' => $return->warehouse_id,
                        'transaction_type' => 'SALES_RETURN',
                        'transaction_id' => $return->id,
                        'transaction_no' => $return->nomor_dokumen,
                        'tanggal' => $return->tanggal,
                        'qty_in' => $total['qty'],
                        'qty_out' => 0,
                        'cost_per_unit' => $total['qty'] > 0 ? $total['cost'] / $total['qty'] : $avgAfter,
                        'avg_cost_before' => $avgBefore,
                        'avg_cost_after' => $avgAfter,
                        'notes' => $note,
                    ]);
                }
            } finally {
                StockCard::$skipObserver = false;
            }

            $return->update([
                'status' => 'lock',
                'locked_at' => now(),
                'locked_by' => Auth::id(),
            ]);

            return $return->fresh(['sales', 'warehouse', 'customer', 'details.product', 'lockedBy']);
        });
    }
}
