<?php

namespace App\Actions\Transfer;

use App\Actions\Serial\Concerns\ResolvesSelectedUnits;
use App\Models\DocTransfer;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use App\Models\StockCard;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class ApproveTransferAction
{
    use RequiresAuthenticatedUser;
    use ResolvesSelectedUnits;

    /**
     * Execute the action.
     */
    public function execute(DocTransfer $transfer): DocTransfer
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$transfer->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya transfer dengan status draft yang dapat disetujui.'],
            ]);
        }

        return DB::transaction(function () use ($transfer) {
            // Load details with products
            $transfer->load('details.product');

            // Get all product IDs
            $productIds = $transfer->details->pluck('product_id')->toArray();

            // Defense-in-depth: tolak baris produk ganda (controller juga memblok). Cegah
            // double-mutate inventory_stock tanpa stock_card padanan → invariant §2C pecah.
            if (count($productIds) !== count(array_unique($productIds))) {
                throw ValidationException::withMessages([
                    'details' => ['Tidak boleh ada produk yang sama lebih dari satu baris.'],
                ]);
            }

            // Lock inventory_stock rows for source warehouse (prevent race condition)
            $stocksFrom = InventoryStock::where('warehouse_id', $transfer->warehouse_from_id)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock inventory_stock rows for destination warehouse
            $stocksTo = InventoryStock::where('warehouse_id', $transfer->warehouse_to_id)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock master_produk rows for update (to get avg_cost)
            $products = MasterProduk::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // First pass: Validate all items (check stock availability)
            $negativeStockAllowed = SettingService::isNegativeStockAllowed();
            $errors = [];

            foreach ($transfer->details as $detail) {
                $currentStockFrom = $stocksFrom[$detail->product_id]->qty ?? 0;
                $newStockFrom = $currentStockFrom - $detail->qty;

                if ($newStockFrom < 0 && !$negativeStockAllowed) {
                    $productName = $detail->product->nama_produk ?? 'Unknown';
                    $errors[] = "Stok {$productName} di gudang asal tidak mencukupi. Tersedia: {$currentStockFrom}, Dibutuhkan: {$detail->qty}";
                }

                // Produk serial wajib punya daftar unit (divalidasi penuh di pass kedua)
                if ($detail->product->is_serial && empty($detail->serial_unit_ids)) {
                    $errors[] = "Produk serial {$detail->product->nama_produk} tidak memiliki unit terpilih.";
                }
            }

            if (!empty($errors)) {
                throw ValidationException::withMessages([
                    'stock' => $errors,
                ]);
            }

            // Alokasi biaya kirim + biaya lain ke tiap baris (by-value = qty × avg_cost,
            // fallback by-qty). Disimpan sebagai info; hanya diterapkan ke HPP bila masuk_hpp.
            $totalBiaya = round((float) $transfer->biaya_kirim + (float) $transfer->biaya_lain, 2);
            $allocations = $this->allocateByValue($transfer->details, $products, $totalBiaya);

            // Skip InventoryStock observer to prevent duplicate stock_card entries
            StockCard::$skipObserver = true;

            // Track running stock per product (to handle multiple lines of same product)
            $runningStocksFrom = [];
            foreach ($stocksFrom as $productId => $stock) {
                $runningStocksFrom[$productId] = (float) $stock->qty;
            }
            $runningStocksTo = [];
            foreach ($stocksTo as $productId => $stock) {
                $runningStocksTo[$productId] = (float) $stock->qty;
            }

            try {
                // Second pass: Process all items
                foreach ($transfer->details as $detail) {
                    $product = $products[$detail->product_id];
                    $avgCost = (float) $product->avg_cost;
                    $movedUnits = []; // unit serial yang dipindah (untuk apply HPP)

                    // --- Process source warehouse (OUT) ---
                    $currentStockFrom = $runningStocksFrom[$detail->product_id] ?? 0;
                    $newStockFrom = $currentStockFrom - $detail->qty;

                    // Update or create inventory_stock for source warehouse
                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $detail->product_id,
                            'warehouse_id' => $transfer->warehouse_from_id,
                        ],
                        [
                            'qty' => $newStockFrom,
                            'avg_cost' => $avgCost,
                        ]
                    );

                    // Update running stock for next iteration of same product
                    $runningStocksFrom[$detail->product_id] = $newStockFrom;

                    // Check if stock card already exists for TRANSFER_OUT
                    $existingOutCard = StockCard::where('transaction_id', $transfer->id)
                        ->where('product_id', $detail->product_id)
                        ->where('transaction_type', 'TRANSFER_OUT')
                        ->exists();

                    if (!$existingOutCard) {
                        // Record stock card for OUT
                        StockCard::record([
                            'product_id' => $detail->product_id,
                            'warehouse_id' => $transfer->warehouse_from_id,
                            'transaction_type' => 'TRANSFER_OUT',
                            'transaction_id' => $transfer->id,
                            'transaction_no' => $transfer->nomor_dokumen,
                            'tanggal' => $transfer->tanggal,
                            'qty_in' => 0,
                            'qty_out' => $detail->qty,
                            'cost_per_unit' => $avgCost,
                            'avg_cost_before' => $avgCost, // HPP tidak berubah untuk transfer
                            'avg_cost_after' => $avgCost,
                            'notes' => $transfer->notes,
                        ]);
                    }

                    // --- Process destination warehouse (IN) ---
                    $currentStockTo = $runningStocksTo[$detail->product_id] ?? 0;
                    $newStockTo = $currentStockTo + $detail->qty;

                    // Update or create inventory_stock for destination warehouse
                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $detail->product_id,
                            'warehouse_id' => $transfer->warehouse_to_id,
                        ],
                        [
                            'qty' => $newStockTo,
                            'avg_cost' => $avgCost,
                        ]
                    );

                    // Update running stock for next iteration of same product
                    $runningStocksTo[$detail->product_id] = $newStockTo;

                    // Check if stock card already exists for TRANSFER_IN
                    $existingInCard = StockCard::where('transaction_id', $transfer->id)
                        ->where('product_id', $detail->product_id)
                        ->where('transaction_type', 'TRANSFER_IN')
                        ->exists();

                    if (!$existingInCard) {
                        // Record stock card for IN
                        StockCard::record([
                            'product_id' => $detail->product_id,
                            'warehouse_id' => $transfer->warehouse_to_id,
                            'transaction_type' => 'TRANSFER_IN',
                            'transaction_id' => $transfer->id,
                            'transaction_no' => $transfer->nomor_dokumen,
                            'tanggal' => $transfer->tanggal,
                            'qty_in' => $detail->qty,
                            'qty_out' => 0,
                            'cost_per_unit' => $avgCost,
                            'avg_cost_before' => $avgCost, // HPP tidak berubah untuk transfer
                            'avg_cost_after' => $avgCost,
                            'notes' => $transfer->notes,
                        ]);
                    }

                    // --- Produk serial: pindahkan unit fisik ke gudang tujuan + catat ledger ---
                    if ($product->is_serial && !empty($detail->serial_unit_ids)) {
                        $units = $this->resolveSelectedUnits(
                            $detail->serial_unit_ids,
                            $detail->product_id,
                            $transfer->warehouse_from_id,
                            (int) $detail->qty
                        );
                        $movedUnits = $units;

                        foreach ($units as $unit) {
                            $unit->update(['warehouse_id' => $transfer->warehouse_to_id]);

                            // Dua movement (paritas dengan stock_card OUT/IN); status tetap tersedia
                            $movementBase = [
                                'serial_unit_id' => $unit->id,
                                'doc_type' => 'TRANSFER',
                                'doc_id' => $transfer->id,
                                'doc_no' => $transfer->nomor_dokumen,
                                'from_status' => SerialUnit::STATUS_TERSEDIA,
                                'to_status' => SerialUnit::STATUS_TERSEDIA,
                                'tanggal' => $transfer->tanggal,
                            ];
                            SerialUnitMovement::record($movementBase + [
                                'movement_type' => 'TRANSFER_OUT',
                                'from_warehouse_id' => $transfer->warehouse_from_id,
                                'to_warehouse_id' => null,
                            ]);
                            SerialUnitMovement::record($movementBase + [
                                'movement_type' => 'TRANSFER_IN',
                                'from_warehouse_id' => null,
                                'to_warehouse_id' => $transfer->warehouse_to_id,
                            ]);
                        }
                    }

                    // --- Biaya kirim/lain: simpan porsi (info) + terapkan ke HPP bila opt-in ---
                    $allocated = (float) ($allocations[$detail->id] ?? 0);
                    $detail->update(['biaya_dialokasikan' => $allocated]);

                    if ($transfer->masuk_hpp && $allocated > 0) {
                        if ($product->is_serial) {
                            // Serial: porsi → cost_per_unit unit yang dipindah; avg via Metode A
                            $this->applyHppSerial($transfer, $product, $movedUnits, $allocated);
                        } else {
                            // Non-serial (Opsi B): naikkan avg_cost global = avg_lama + porsi ÷ qty_global
                            $this->applyHppNonSerial($transfer, $product, $allocated);
                        }
                    }
                }
            } finally {
                // Always reset the observer flag
                StockCard::$skipObserver = false;
            }

            // Update transfer status
            $transfer->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            // Reload with relations
            $transfer->load(['warehouseFrom', 'warehouseTo', 'details.product', 'createdBy', 'approvedBy']);

            return $transfer;
        });
    }

    /**
     * Alokasi total biaya ke tiap baris detail secara proporsional (by-value = qty × avg_cost).
     * Fallback ke by-qty bila total value 0. Sisa pembulatan dilempar ke baris terakhir agar
     * Σ alokasi == total biaya (kekekalan nilai).
     *
     * @return array<int,float>  map detail->id => biaya dialokasikan
     */
    private function allocateByValue($details, $products, float $totalBiaya): array
    {
        $result = [];
        if ($totalBiaya <= 0) {
            foreach ($details as $d) {
                $result[$d->id] = 0.0;
            }

            return $result;
        }

        // Bobot by-value (qty × avg_cost)
        $weights = [];
        $totalWeight = 0.0;
        foreach ($details as $d) {
            $avg = (float) ($products[$d->product_id]->avg_cost ?? 0);
            $w = (float) $d->qty * $avg;
            $weights[$d->id] = $w;
            $totalWeight += $w;
        }

        // Fallback by-qty bila semua nilai 0
        if ($totalWeight <= 0) {
            $totalWeight = 0.0;
            foreach ($details as $d) {
                $weights[$d->id] = (float) $d->qty;
                $totalWeight += (float) $d->qty;
            }
        }

        if ($totalWeight <= 0) {
            foreach ($details as $d) {
                $result[$d->id] = 0.0;
            }

            return $result;
        }

        $allocatedSum = 0.0;
        $lastId = null;
        foreach ($details as $d) {
            $alloc = round($totalBiaya * $weights[$d->id] / $totalWeight, 2);
            $result[$d->id] = $alloc;
            $allocatedSum += $alloc;
            $lastId = $d->id;
        }

        $remainder = round($totalBiaya - $allocatedSum, 2);
        if ($lastId !== null && abs($remainder) >= 0.01) {
            $result[$lastId] = round($result[$lastId] + $remainder, 2);
        }

        return $result;
    }

    /**
     * Non-serial (Opsi B): avg_cost global naik = avg_lama + (porsi biaya ÷ qty_global).
     * qty_global = total stok produk di semua gudang (transfer internal, qty tak berubah).
     * Catat stock_card HPP_CORRECTION agar muncul di Pergerakan HPP.
     */
    private function applyHppNonSerial(DocTransfer $transfer, MasterProduk $product, float $allocated): void
    {
        $qtyGlobal = (float) InventoryStock::where('product_id', $product->id)->sum('qty');
        if ($qtyGlobal <= 0) {
            return; // tak ada stok → tak bisa dikapitalisasi
        }

        $oldAvg = (float) $product->avg_cost;
        $newAvg = round($oldAvg + $allocated / $qtyGlobal, 4);

        $product->update(['avg_cost' => $newAvg]);
        $product->syncAvgCostToInventoryStocks();
        $this->recordHppCorrection($transfer, $product->id, $oldAvg, $newAvg, $allocated);
    }

    /**
     * Serial: porsi biaya dibagi rata ke cost_per_unit unit yang dipindah, lalu avg_cost produk
     * direkalkulasi = rata cost_per_unit unit tersedia (Metode A, sama dgn Koreksi HPP Serial).
     */
    private function applyHppSerial(DocTransfer $transfer, MasterProduk $product, $movedUnits, float $allocated): void
    {
        $count = is_countable($movedUnits) ? count($movedUnits) : 0;
        if ($count === 0) {
            return;
        }

        $perUnit = round($allocated / $count, 4);
        foreach ($movedUnits as $unit) {
            $unit->update(['cost_per_unit' => round((float) $unit->cost_per_unit + $perUnit, 4)]);
        }

        // Propagasi avg (Metode A): rata cost_per_unit unit tersedia
        $oldAvg = (float) $product->avg_cost;
        $tersedia = SerialUnit::byProduct($product->id)->tersedia()->get(['cost_per_unit']);
        $n = $tersedia->count();
        if ($n > 0) {
            $newAvg = round((float) $tersedia->sum('cost_per_unit') / $n, 4);
            $product->update(['avg_cost' => $newAvg]);
            $product->syncAvgCostToInventoryStocks();
            $this->recordHppCorrection($transfer, $product->id, $oldAvg, $newAvg, $allocated);
        }
    }

    /**
     * Catat perubahan HPP akibat biaya transfer ke stock_card (HPP_CORRECTION, warehouse null = global).
     */
    private function recordHppCorrection(DocTransfer $transfer, int $productId, float $oldAvg, float $newAvg, float $allocated): void
    {
        StockCard::record([
            'product_id' => $productId,
            'warehouse_id' => null, // HPP global
            'transaction_type' => 'HPP_CORRECTION',
            'transaction_id' => $transfer->id,
            'transaction_no' => $transfer->nomor_dokumen,
            'tanggal' => $transfer->tanggal,
            'qty_in' => 0,
            'qty_out' => 0,
            'cost_per_unit' => $newAvg,
            'avg_cost_before' => $oldAvg,
            'avg_cost_after' => $newAvg,
            'notes' => 'Biaya transfer ' . $transfer->nomor_dokumen . ' masuk HPP (alokasi ' . number_format($allocated, 2) . ')',
        ]);
    }
}
