<?php

namespace App\Actions\Repack;

use App\Models\DocRepack;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\StockCard;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class ApproveRepackAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(DocRepack $repack): DocRepack
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$repack->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya repack dengan status draft yang dapat disetujui.'],
            ]);
        }

        return DB::transaction(function () use ($repack) {
            // Load inputs and outputs with products
            $repack->load(['inputs.product', 'outputs.product']);

            // Get all product IDs (inputs + outputs)
            $inputProductIds = $repack->inputs->pluck('product_id')->toArray();
            $outputProductIds = $repack->outputs->pluck('product_id')->toArray();
            $allProductIds = array_unique(array_merge($inputProductIds, $outputProductIds));

            // Lock inventory_stock rows for this warehouse (prevent race condition)
            $stocks = InventoryStock::where('warehouse_id', $repack->warehouse_id)
                ->whereIn('product_id', $allProductIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock master_produk rows for update (to get avg_cost)
            $products = MasterProduk::whereIn('id', $allProductIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // ==================== VALIDATE INPUT STOCK ====================
            $negativeStockAllowed = SettingService::isNegativeStockAllowed();
            $errors = [];

            foreach ($repack->inputs as $input) {
                $currentStock = $stocks[$input->product_id]->qty ?? 0;
                $newStock = $currentStock - $input->qty;

                if ($newStock < 0 && !$negativeStockAllowed) {
                    $productName = $input->product->nama_produk ?? 'Unknown';
                    $errors[] = "Stok {$productName} tidak mencukupi. Tersedia: {$currentStock}, Dibutuhkan: {$input->qty}";
                }
            }

            if (!empty($errors)) {
                throw ValidationException::withMessages([
                    'stock' => $errors,
                ]);
            }

            // Skip InventoryStock observer to prevent duplicate stock_card entries
            StockCard::$skipObserver = true;

            // Track running stock per product (to handle multiple lines of same product)
            $runningStocks = [];
            foreach ($stocks as $productId => $stock) {
                $runningStocks[$productId] = (float) $stock->qty;
            }

            try {
                // ==================== PROCESS INPUT (BAHAN) ====================
                $totalCostInput = 0;

                foreach ($repack->inputs as $input) {
                    $product = $products[$input->product_id];
                    $avgCost = (float) $product->avg_cost;

                    // Snapshot HPP
                    $costPerUnit = $avgCost;
                    $totalCost = $input->qty * $costPerUnit;
                    $totalCostInput += $totalCost;

                    // Update input record with HPP values
                    $input->update([
                        'cost_per_unit' => $costPerUnit,
                        'total_cost' => $totalCost,
                    ]);

                    // Update inventory_stock (reduce qty)
                    $currentStock = $runningStocks[$input->product_id] ?? 0;
                    $newStock = $currentStock - $input->qty;

                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $input->product_id,
                            'warehouse_id' => $repack->warehouse_id,
                        ],
                        [
                            'qty' => $newStock,
                            'avg_cost' => $avgCost,
                        ]
                    );

                    // Update running stock for next iteration of same product
                    $runningStocks[$input->product_id] = $newStock;

                    // Record stock card for REPACK_OUT
                    StockCard::record([
                        'product_id' => $input->product_id,
                        'warehouse_id' => $repack->warehouse_id,
                        'transaction_type' => 'REPACK_OUT',
                        'transaction_id' => $repack->id,
                        'transaction_no' => $repack->nomor_dokumen,
                        'tanggal' => $repack->tanggal,
                        'qty_in' => 0,
                        'qty_out' => $input->qty,
                        'cost_per_unit' => $costPerUnit,
                        'avg_cost_before' => $avgCost, // HPP sebelum (tidak berubah untuk OUT)
                        'avg_cost_after' => $avgCost, // HPP tidak berubah untuk input
                        'notes' => $repack->notes,
                    ]);

                    // Check and reset HPP if global stock becomes 0
                    $product->checkAndResetHppIfStockEmpty(
                        $repack->warehouse_id,
                        $repack->id,
                        $repack->nomor_dokumen,
                        $repack->tanggal
                    );
                }

                // ==================== CALCULATE OUTPUT HPP ====================
                $biayaRepack = (float) $repack->biaya_repack;
                $totalCostOutput = $totalCostInput + $biayaRepack;
                $totalOutputQty = $repack->outputs->sum('qty');

                // ==================== PROCESS OUTPUT (HASIL) ====================
                foreach ($repack->outputs as $output) {
                    $product = $products[$output->product_id];

                    // Distribute cost proportionally based on qty
                    $outputTotalCost = $totalCostOutput * ($output->qty / $totalOutputQty);
                    $outputCostPerUnit = $outputTotalCost / $output->qty;

                    // Update output record with calculated HPP
                    $output->update([
                        'cost_per_unit' => $outputCostPerUnit,
                        'total_cost' => $outputTotalCost,
                    ]);

                    // Get current stock for this output product
                    $currentStock = $runningStocks[$output->product_id] ?? 0;
                    $newStock = $currentStock + $output->qty;

                    // Get avg_cost before recalculation
                    $avgCostBefore = (float) $product->avg_cost;

                    // Recalculate global avg_cost for output product
                    $newAvgCost = $product->recalculateAvgCost($output->qty, $outputCostPerUnit);

                    // Sync to all inventory_stocks
                    $product->syncAvgCostToInventoryStocks();

                    // Update inventory_stock (increase qty)
                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $output->product_id,
                            'warehouse_id' => $repack->warehouse_id,
                        ],
                        [
                            'qty' => $newStock,
                            'avg_cost' => $newAvgCost,
                        ]
                    );

                    // Update running stock for next iteration of same product
                    $runningStocks[$output->product_id] = $newStock;

                    // Record stock card for REPACK_IN
                    StockCard::record([
                        'product_id' => $output->product_id,
                        'warehouse_id' => $repack->warehouse_id,
                        'transaction_type' => 'REPACK_IN',
                        'transaction_id' => $repack->id,
                        'transaction_no' => $repack->nomor_dokumen,
                        'tanggal' => $repack->tanggal,
                        'qty_in' => $output->qty,
                        'qty_out' => 0,
                        'cost_per_unit' => $outputCostPerUnit,
                        'avg_cost_before' => $avgCostBefore, // HPP sebelum recalculate
                        'avg_cost_after' => $newAvgCost, // HPP setelah recalculate
                        'notes' => $repack->notes,
                    ]);
                }

                // ==================== UPDATE HEADER ====================
                $repack->update([
                    'total_cost_input' => $totalCostInput,
                    'total_cost_output' => $totalCostOutput,
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => Auth::id(),
                ]);

            } finally {
                // Always reset the observer flag
                StockCard::$skipObserver = false;
            }

            // Reload with relations
            $repack->load(['warehouse', 'inputs.product', 'outputs.product', 'createdBy', 'approvedBy']);

            return $repack;
        });
    }
}
