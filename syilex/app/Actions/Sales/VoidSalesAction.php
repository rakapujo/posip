<?php

namespace App\Actions\Sales;

use App\Models\DocSales;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\StockCard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\Sales\Concerns\RevertsSerialUnits;

class VoidSalesAction
{
    use RequiresAuthenticatedUser;
    use RevertsSerialUnits;

    /**
     * Void a completed sales transaction.
     * Restores stock, records SALES_RETURN stock card entries.
     */
    public function execute(DocSales $sales, string $reason): DocSales
    {
        $this->ensureAuthenticated();

        if (!$sales->canVoid()) {
            throw ValidationException::withMessages([
                'status' => ['Transaksi ini tidak dapat di-void.'],
            ]);
        }

        return DB::transaction(function () use ($sales, $reason) {
            $sales->load('details');

            $productIds = $sales->details->pluck('product_id')->toArray();
            $warehouseId = $sales->warehouse_id;

            // Lock rows
            $stocks = InventoryStock::where('warehouse_id', $warehouseId)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $products = MasterProduk::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            StockCard::$skipObserver = true;

            // Track running stock per product (to handle multiple lines of same product)
            $runningStocks = [];
            foreach ($stocks as $productId => $stock) {
                $runningStocks[$productId] = (float) $stock->qty;
            }

            try {
                // Restore stock for each detail
                foreach ($sales->details as $detail) {
                    $product = $products[$detail->product_id];
                    $currentStock = $runningStocks[$detail->product_id] ?? 0;
                    $hppBefore = (float) $product->avg_cost;
                    $isSerial = (bool) $product->is_serial && !empty($detail->serial_unit_ids);

                    if ($isSerial) {
                        // Kembalikan unit → tersedia + movement IN, lalu rekalkulasi avg (Metode A)
                        $reverted = $this->revertSoldUnits(
                            $detail->serial_unit_ids,
                            (int) $sales->id,
                            (int) $detail->product_id,
                            (int) $warehouseId,
                            'VOID',
                            (int) $sales->id,
                            $sales->nomor_dokumen,
                            now()
                        );
                        $hppAfter = $this->recomputeSerialAvgCost($product);
                        $cost = round((float) $reverted->sum(fn ($u) => (float) $u->cost_per_unit) / $reverted->count(), 4);
                    } else {
                        // Retail: restore HPP bila sempat ter-reset 0 (stok habis sesudah jual)
                        $hppSales = (float) $detail->hpp_at_time;
                        $hppAfter = $hppBefore;
                        if ($hppBefore == 0 && $hppSales > 0) {
                            $hppAfter = $hppSales;
                            $product->avg_cost = $hppAfter;
                            $product->save();
                            $product->syncAvgCostToInventoryStocks();
                        }
                        $cost = $hppAfter;
                    }

                    // Add stock back (void = return stock)
                    $newStock = $currentStock + $detail->qty_base;

                    // Update running stock for next iteration of same product
                    $runningStocks[$detail->product_id] = $newStock;

                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $detail->product_id,
                            'warehouse_id' => $warehouseId,
                        ],
                        [
                            'qty' => $newStock,
                            'avg_cost' => $hppAfter,
                        ]
                    );

                    // Record stock card — void restores stock (qty_in)
                    StockCard::record([
                        'product_id' => $detail->product_id,
                        'warehouse_id' => $warehouseId,
                        'transaction_type' => 'SALES_RETURN',
                        'transaction_id' => $sales->id,
                        'transaction_no' => $sales->nomor_dokumen,
                        'tanggal' => now(),
                        'qty_in' => $detail->qty_base,
                        'qty_out' => 0,
                        'cost_per_unit' => $cost,
                        'avg_cost_before' => $hppBefore,
                        'avg_cost_after' => $hppAfter,
                        'notes' => "VOID: {$reason}",
                    ]);
                }
            } finally {
                StockCard::$skipObserver = false;
            }

            // Mark as voided
            $sales->update([
                'status' => 'voided',
                'voided_at' => now(),
                'voided_by' => Auth::id(),
                'void_reason' => $reason,
            ]);

            return $sales;
        });
    }
}
