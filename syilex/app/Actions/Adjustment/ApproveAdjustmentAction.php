<?php

namespace App\Actions\Adjustment;

use App\Actions\Serial\Concerns\ResolvesSelectedUnits;
use App\Models\DocAdjustment;
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

class ApproveAdjustmentAction
{
    use RequiresAuthenticatedUser;
    use ResolvesSelectedUnits;

    /**
     * Execute the action.
     */
    public function execute(DocAdjustment $adjustment): DocAdjustment
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$adjustment->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya adjustment dengan status draft yang dapat disetujui.'],
            ]);
        }

        return DB::transaction(function () use ($adjustment) {
            // Load details with products
            $adjustment->load('details.product');

            // Get all product IDs
            $productIds = $adjustment->details->pluck('product_id')->toArray();

            // Defense-in-depth: tolak baris produk ganda (controller juga memblok). Cegah
            // double-mutate inventory_stock tanpa stock_card padanan → invariant §2C pecah.
            if (count($productIds) !== count(array_unique($productIds))) {
                throw ValidationException::withMessages([
                    'details' => ['Tidak boleh ada produk yang sama lebih dari satu baris.'],
                ]);
            }

            // Lock inventory_stock rows for update (prevent race condition)
            $stocks = InventoryStock::where('warehouse_id', $adjustment->warehouse_id)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock master_produk rows for update
            $products = MasterProduk::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // First pass: Validate all items (check negative stock)
            $negativeStockAllowed = SettingService::isNegativeStockAllowed();
            $errors = [];

            foreach ($adjustment->details as $detail) {
                $currentStock = $stocks[$detail->product_id]->qty ?? 0;

                if ($detail->jenis === 'kredit') {
                    $newStock = $currentStock - $detail->qty;

                    if ($newStock < 0 && !$negativeStockAllowed) {
                        $productName = $detail->product->nama_produk ?? 'Unknown';
                        $errors[] = "Stok {$productName} tidak mencukupi. Tersedia: {$currentStock}, Dibutuhkan: {$detail->qty}";
                    }
                }

                // Guard serial defensif: debit dilarang; kredit wajib punya unit
                if ($detail->product->is_serial) {
                    if ($detail->jenis === 'debit') {
                        $errors[] = "Produk serial {$detail->product->nama_produk} tidak bisa ditambah via Adjustment.";
                    } elseif (empty($detail->serial_unit_ids)) {
                        $errors[] = "Produk serial {$detail->product->nama_produk} tidak memiliki unit terpilih.";
                    }
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
                // Second pass: Process all items
                foreach ($adjustment->details as $detail) {
                    $product = $products[$detail->product_id];
                    $currentWarehouseStock = $runningStocks[$detail->product_id] ?? 0;
                    $oldHpp = (float) $product->avg_cost;

                    // Serial (kredit): resolve unit terpilih + valuasi pakai cost_per_unit unit
                    // (biaya riil unit, bukan avg_cost produk) untuk stock_card.
                    $serialUnits = null;
                    $costPerUnit = $oldHpp;
                    if ($product->is_serial && $detail->jenis === 'kredit') {
                        $serialUnits = $this->resolveSelectedUnits(
                            $detail->serial_unit_ids ?? [],
                            $detail->product_id,
                            $adjustment->warehouse_id,
                            (int) $detail->qty
                        );
                        $count = $serialUnits->count();
                        $costPerUnit = $count > 0 ? (float) $serialUnits->sum('cost_per_unit') / $count : $oldHpp;
                    }

                    // Calculate new warehouse stock
                    $qtyChange = $detail->jenis === 'debit' ? $detail->qty : -$detail->qty;
                    $newWarehouseStock = $currentWarehouseStock + $qtyChange;

                    // HPP calculation depends on transaction type
                    // - ADJUSTMENT_IN (debit): Recalculate HPP using weighted average
                    // - ADJUSTMENT_OUT (kredit): HPP does NOT change (per CLAUDE.md)
                    $newHpp = $oldHpp;

                    if ($detail->jenis === 'debit') {
                        // For IN: Recalculate using weighted average
                        $newHpp = $product->recalculateAvgCost($detail->qty, $oldHpp);
                        $product->syncAvgCostToInventoryStocks();
                    }

                    // Update or create inventory_stock
                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $detail->product_id,
                            'warehouse_id' => $adjustment->warehouse_id,
                        ],
                        [
                            'qty' => $newWarehouseStock,
                            'avg_cost' => $newHpp,
                        ]
                    );

                    // Update running stock for next iteration of same product
                    $runningStocks[$detail->product_id] = $newWarehouseStock;

                    // Update detail with latest stock info (for audit)
                    $detail->update([
                        'stok_sistem' => $currentWarehouseStock,
                        'stok_akhir' => $newWarehouseStock,
                    ]);

                    // Build notes: [header keterangan] | [detail notes]
                    $noteParts = [];
                    if ($adjustment->keterangan) {
                        $noteParts[] = $adjustment->keterangan;
                    }
                    if ($detail->notes) {
                        $noteParts[] = $detail->notes;
                    }
                    $combinedNotes = implode(' | ', $noteParts) ?: null;

                    // Check if stock card already exists for this transaction (prevent duplicates)
                    $existingStockCard = StockCard::where('transaction_id', $adjustment->id)
                        ->where('product_id', $detail->product_id)
                        ->where('transaction_type', $detail->jenis === 'debit' ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT')
                        ->exists();

                    if ($existingStockCard) {
                        continue; // Skip if already recorded
                    }

                    // Record stock card
                    StockCard::record([
                        'product_id' => $detail->product_id,
                        'warehouse_id' => $adjustment->warehouse_id,
                        'transaction_type' => $detail->jenis === 'debit' ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT',
                        'transaction_id' => $adjustment->id,
                        'transaction_no' => $adjustment->nomor_dokumen,
                        'tanggal' => $adjustment->tanggal,
                        'qty_in' => $detail->jenis === 'debit' ? $detail->qty : 0,
                        'qty_out' => $detail->jenis === 'kredit' ? $detail->qty : 0,
                        'cost_per_unit' => $costPerUnit,
                        'avg_cost_before' => $oldHpp,
                        'avg_cost_after' => $newHpp,
                        'notes' => $combinedNotes,
                    ]);

                    // Serial (kredit): tandai unit keluar + catat movement.
                    // Status: opname → semua 'hilang' (raib saat hitung fisik); manual → pilihan
                    // user per unit (serial_unit_statuses), default 'rusak'.
                    if ($serialUnits !== null) {
                        $isOpname = $adjustment->source === 'opname';
                        $statusMap = $detail->serial_unit_statuses ?? [];

                        foreach ($serialUnits as $unit) {
                            $targetStatus = $isOpname
                                ? SerialUnit::STATUS_HILANG
                                : (($statusMap[$unit->ulid] ?? 'rusak') === 'hilang'
                                    ? SerialUnit::STATUS_HILANG
                                    : SerialUnit::STATUS_RUSAK);

                            $unit->update(['status' => $targetStatus]);
                            SerialUnitMovement::record([
                                'serial_unit_id' => $unit->id,
                                'doc_type' => 'ADJUSTMENT',
                                'doc_id' => $adjustment->id,
                                'doc_no' => $adjustment->nomor_dokumen,
                                'movement_type' => 'OUT',
                                'from_warehouse_id' => $adjustment->warehouse_id,
                                'to_warehouse_id' => null,
                                'from_status' => SerialUnit::STATUS_TERSEDIA,
                                'to_status' => $targetStatus,
                                'tanggal' => $adjustment->tanggal,
                            ]);
                        }
                    }

                    // For ADJUSTMENT_OUT: Check and reset HPP if global stock becomes 0
                    if ($detail->jenis === 'kredit') {
                        $product->checkAndResetHppIfStockEmpty(
                            $adjustment->warehouse_id,
                            $adjustment->id,
                            $adjustment->nomor_dokumen,
                            $adjustment->tanggal
                        );
                    }
                }
            } finally {
                // Always reset the observer flag
                StockCard::$skipObserver = false;
            }

            // Update adjustment status
            $adjustment->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            // Reload with relations
            $adjustment->load(['warehouse', 'details.product', 'createdBy', 'approvedBy']);

            return $adjustment;
        });
    }
}
