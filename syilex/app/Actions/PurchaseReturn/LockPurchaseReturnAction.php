<?php

namespace App\Actions\PurchaseReturn;

use App\Actions\Serial\Concerns\ResolvesSelectedUnits;
use App\Models\DocPurchaseOrderDetail;
use App\Models\DocPurchaseReturn;
use App\Models\DocPurchaseReturnDetail;
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

class LockPurchaseReturnAction
{
    use RequiresAuthenticatedUser;
    use ResolvesSelectedUnits;

    /**
     * Lock purchase return and process stock out.
     *
     * LOCK = barang dikirim ke vendor, stok keluar
     */
    public function execute(DocPurchaseReturn $retur): DocPurchaseReturn
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($retur) {
            $retur = DocPurchaseReturn::lockForUpdate()->findOrFail($retur->id);
            if (! $retur->canLock()) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya retur dengan status draft dan memiliki detail yang dapat dikunci.'],
                ]);
            }

            // Load details with products and PO details
            $retur->load('details.product', 'details.purchaseOrderDetail');

            // Get all product IDs
            $productIds = $retur->details->pluck('product_id')->unique()->toArray();

            // Defense-in-depth: tolak baris produk+satuan ganda (controller juga memblok
            // lewat hasDuplicateProducts). Produk sama dengan satuan BERBEDA (mis. PCS +
            // BOX) tetap diizinkan — running stock per product_id di bawah (mirip
            // ApprovePurchaseOrderAction) menangani multi-baris produk yang sama dengan benar.
            $comboKeys = $retur->details->map(fn ($d) => $d->product_id.'-'.$d->unit_used);
            if ($comboKeys->count() !== $comboKeys->unique()->count()) {
                throw ValidationException::withMessages([
                    'details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu retur.'],
                ]);
            }

            // Get all po_detail_ids that are not null
            $poDetailIds = $retur->details->pluck('po_detail_id')->filter()->toArray();

            // ============ VALIDATION 1: PO QTY LIMIT ============
            // Lock PO detail rows to prevent race condition
            if (!empty($poDetailIds)) {
                DocPurchaseOrderDetail::whereIn('id', $poDetailIds)
                    ->lockForUpdate()
                    ->get();

                // Get qty already returned for each PO detail (excluding current retur's draft details)
                $returnedQtys = DocPurchaseReturnDetail::whereIn('po_detail_id', $poDetailIds)
                    ->where('retur_id', '!=', $retur->id) // Exclude current return
                    ->whereHas('purchaseReturn', function ($q) {
                        $q->whereIn('status', ['lock', 'approved']);
                    })
                    ->selectRaw('po_detail_id, SUM(qty_in_base) as total_returned')
                    ->groupBy('po_detail_id')
                    ->pluck('total_returned', 'po_detail_id');

                $poErrors = [];
                foreach ($retur->details as $detail) {
                    if ($detail->po_detail_id && $detail->purchaseOrderDetail) {
                        $poDetail = $detail->purchaseOrderDetail;
                        $qtyOrdered = (float) $poDetail->qty_in_base;
                        $qtyReturned = (float) ($returnedQtys[$detail->po_detail_id] ?? 0);
                        $qtyAvailable = $qtyOrdered - $qtyReturned;
                        $qtyRequested = (float) $detail->qty_in_base;

                        if ($qtyRequested > $qtyAvailable) {
                            $productName = $detail->product->nama_produk ?? 'Unknown';
                            $poErrors[] = "{$productName}: Qty retur ({$qtyRequested}) melebihi sisa PO ({$qtyAvailable})";
                        }
                    }
                }

                if (!empty($poErrors)) {
                    throw ValidationException::withMessages([
                        'po_limit' => $poErrors,
                    ]);
                }
            }

            // ============ VALIDATION 2: STOCK AVAILABILITY ============
            // Lock inventory_stock rows for update (prevent race condition)
            $stocks = InventoryStock::where('warehouse_id', $retur->warehouse_id)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock master_produk rows for update
            $products = MasterProduk::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Check negative stock using running qty (multi-line same SKU).
            $negativeStockAllowed = SettingService::isNegativeStockAllowed();
            $errors = [];
            $runningCheck = [];
            foreach ($stocks as $productId => $stock) {
                $runningCheck[$productId] = (float) $stock->qty;
            }

            foreach ($retur->details as $detail) {
                $qtyOut = (int) $detail->qty_in_base;
                $available = $runningCheck[$detail->product_id] ?? 0;
                $newStock = $available - $qtyOut;

                if ($newStock < 0 && ! $negativeStockAllowed) {
                    $productName = $detail->product->nama_produk ?? 'Unknown';
                    $errors[] = "Stok {$productName} tidak mencukupi. Tersedia: {$available}, Dibutuhkan: {$qtyOut}";
                } else {
                    $runningCheck[$detail->product_id] = $newStock;
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
                // Second pass: Process stock out for all items
                foreach ($retur->details as $detail) {
                    $product = $products[$detail->product_id];
                    $currentWarehouseStock = $runningStocks[$detail->product_id] ?? 0;
                    $currentHpp = (float) $product->avg_cost;
                    $qtyOut = (int) $detail->qty_in_base;

                    // Serial: resolve unit terpilih + valuasi pakai cost_per_unit unit (landed)
                    $serialUnits = null;
                    $costPerUnit = $currentHpp;
                    if ($product->is_serial && !empty($detail->serial_unit_ids)) {
                        $serialUnits = $this->resolveSelectedUnits(
                            $detail->serial_unit_ids,
                            $detail->product_id,
                            $retur->warehouse_id,
                            $qtyOut,
                            'serial_unit_ids',
                            null,
                            $retur->serial_intake_id ? (int) $retur->serial_intake_id : null,
                        );
                        $count = $serialUnits->count();
                        $costPerUnit = $count > 0 ? (float) $serialUnits->sum('cost_per_unit') / $count : $currentHpp;
                    }

                    // Calculate new warehouse stock (decrease)
                    $newWarehouseStock = $currentWarehouseStock - $qtyOut;

                    // HPP does NOT change on PURCHASE_RETURN (per AI-AGENT.md)
                    $newHpp = $currentHpp;

                    // Update or create inventory_stock
                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $detail->product_id,
                            'warehouse_id' => $retur->warehouse_id,
                        ],
                        [
                            'qty' => $newWarehouseStock,
                            'avg_cost' => $newHpp,
                        ]
                    );

                    // Update running stock for next iteration of same product
                    $runningStocks[$detail->product_id] = $newWarehouseStock;

                    // Build notes for stock card
                    $combinedNotes = $retur->notes ?: null;

                    // NOTE: sengaja TIDAK dedup by (transaction_id, product_id, transaction_type)
                    // di sini — produk sama boleh muncul di >1 baris dengan unit berbeda
                    // (PCS + BOX), masing-masing baris WAJIB punya stock_card sendiri agar
                    // SUM(qty_out) match dengan total decrement inventory_stock (lihat §2C).
                    // Mirip pola ApprovePurchaseOrderAction yang juga record per detail line.

                    // Record stock card (valuasi serial pakai cost_per_unit unit)
                    StockCard::record([
                        'product_id' => $detail->product_id,
                        'warehouse_id' => $retur->warehouse_id,
                        'transaction_type' => 'PURCHASE_RETURN',
                        'transaction_id' => $retur->id,
                        'transaction_no' => $retur->nomor_dokumen,
                        'tanggal' => $retur->tanggal,
                        'qty_in' => 0,
                        'qty_out' => $qtyOut,
                        'cost_per_unit' => $costPerUnit,
                        'avg_cost_before' => $currentHpp,
                        'avg_cost_after' => $newHpp,
                        'notes' => $combinedNotes,
                    ]);

                    // Serial: tandai unit dikembalikan ke supplier + catat movement
                    if ($serialUnits !== null) {
                        foreach ($serialUnits as $unit) {
                            $unit->update(['status' => SerialUnit::STATUS_RETUR]);
                            SerialUnitMovement::record([
                                'serial_unit_id' => $unit->id,
                                'doc_type' => 'PURCHASE_RETURN',
                                'doc_id' => $retur->id,
                                'doc_no' => $retur->nomor_dokumen,
                                'movement_type' => 'OUT',
                                'from_warehouse_id' => $retur->warehouse_id,
                                'to_warehouse_id' => null,
                                'from_status' => SerialUnit::STATUS_TERSEDIA,
                                'to_status' => SerialUnit::STATUS_RETUR,
                                'tanggal' => $retur->tanggal,
                            ]);
                        }
                    }

                    // Check and reset HPP if global stock becomes 0
                    $product->checkAndResetHppIfStockEmpty(
                        $retur->warehouse_id,
                        $retur->id,
                        $retur->nomor_dokumen,
                        $retur->tanggal
                    );
                }
            } finally {
                // Always reset the observer flag
                StockCard::$skipObserver = false;
            }

            // Update retur status to lock
            $retur->update([
                'status' => 'lock',
                'locked_at' => now(),
                'locked_by' => Auth::id(),
            ]);

            // Reload with relations
            $retur->load(['warehouse', 'supplier', 'details.product', 'createdBy', 'lockedBy']);

            return $retur;
        });
    }
}
