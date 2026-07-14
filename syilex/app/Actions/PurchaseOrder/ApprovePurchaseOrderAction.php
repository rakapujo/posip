<?php

namespace App\Actions\PurchaseOrder;

use App\Models\DocPurchaseOrder;
use App\Models\HistoryHargaBeli;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\StockCard;
use App\Models\SupplierHutang;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\Concerns\SettlesCashPayment;

class ApprovePurchaseOrderAction
{
    use RequiresAuthenticatedUser;
    use SettlesCashPayment;

    /**
     * Execute the action.
     */
    public function execute(DocPurchaseOrder $po): DocPurchaseOrder
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$po->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya PO dengan status draft yang dapat disetujui.'],
            ]);
        }

        // Validate has details
        if ($po->details()->count() === 0) {
            throw ValidationException::withMessages([
                'details' => ['PO harus memiliki minimal 1 detail produk.'],
            ]);
        }

        return DB::transaction(function () use ($po) {
            // Lock header & re-assert draft DI DALAM transaksi (cegah double-approve race —
            // dua request paralel bisa lolos cek isDraft() di luar transaksi → dobel stok/hutang).
            $po = DocPurchaseOrder::where('id', $po->id)->lockForUpdate()->firstOrFail();
            if (!$po->isDraft()) {
                throw ValidationException::withMessages(['status' => ['PO sudah diproses, tidak bisa disetujui ulang.']]);
            }

            // Load details with products
            $po->load('details.product');

            // Get all product IDs
            $productIds = $po->details->pluck('product_id')->toArray();

            // Lock inventory_stock rows for update (prevent race condition)
            $stocks = InventoryStock::where('warehouse_id', $po->warehouse_id)
                ->whereIn('product_id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            // Lock master_produk rows for update
            $products = MasterProduk::whereIn('id', $productIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Skip InventoryStock observer to prevent duplicate stock_card entries
            StockCard::$skipObserver = true;

            // Track running stock per product (to handle multiple lines of same product)
            $runningStocks = [];
            foreach ($stocks as $productId => $stock) {
                $runningStocks[$productId] = (float) $stock->qty;
            }

            try {
                // Process each detail
                foreach ($po->details as $detail) {
                    $product = $products[$detail->product_id];
                    $currentWarehouseStock = $runningStocks[$detail->product_id] ?? 0;
                    $oldHpp = (float) $product->avg_cost;

                    // Calculate new stock
                    $qtyIn = (int) $detail->qty_in_base;
                    $newWarehouseStock = $currentWarehouseStock + $qtyIn;

                    // Calculate new HPP using weighted average
                    // cost_per_unit already includes allocated costs (biaya kirim, biaya lain, and tax if included)
                    $newHpp = $product->recalculateAvgCost($qtyIn, (float) $detail->cost_per_unit);
                    $product->syncAvgCostToInventoryStocks();

                    // Update or create inventory_stock
                    InventoryStock::updateOrCreate(
                        [
                            'product_id' => $detail->product_id,
                            'warehouse_id' => $po->warehouse_id,
                        ],
                        [
                            'qty' => $newWarehouseStock,
                            'avg_cost' => $newHpp,
                        ]
                    );

                    // Update running stock for next iteration of same product
                    $runningStocks[$detail->product_id] = $newWarehouseStock;

                    // Record stock card
                    StockCard::record([
                        'product_id' => $detail->product_id,
                        'warehouse_id' => $po->warehouse_id,
                        'transaction_type' => 'PURCHASE',
                        'transaction_id' => $po->id,
                        'transaction_no' => $po->nomor_dokumen,
                        'tanggal' => $po->tanggal_po,
                        'qty_in' => $qtyIn,
                        'qty_out' => 0,
                        'cost_per_unit' => $detail->cost_per_unit,
                        'avg_cost_before' => $oldHpp,
                        'avg_cost_after' => $newHpp,
                        'notes' => "PO dari {$po->supplier->nama_supplier}",
                    ]);

                    // Record price history
                    HistoryHargaBeli::create([
                        'product_id' => $detail->product_id,
                        'supplier_id' => $po->supplier_id,
                        'po_id' => $po->id,
                        'po_detail_id' => $detail->id,
                        'tanggal' => $po->tanggal_po,
                        'unit_used' => $detail->unit_used,
                        'qty_in_unit' => $detail->qty_in_unit,
                        'qty_in_base' => $detail->qty_in_base,
                        'harga_per_unit' => $detail->harga_per_unit,
                        'harga_per_base' => $detail->harga_per_base,
                    ]);
                }
            } finally {
                // Always reset the observer flag
                StockCard::$skipObserver = false;
            }

            // Create supplier hutang record
            $hutang = SupplierHutang::create([
                'supplier_id' => $po->supplier_id,
                'po_id' => $po->id,
                'tanggal' => $po->tanggal_po,
                'tanggal_jatuh_tempo' => $po->tanggal_jatuh_tempo,
                'nominal_awal' => $po->grand_total,
                'nominal_terbayar' => 0,
                'sisa_hutang' => $po->grand_total,
                'status' => 'unpaid',
            ]);

            // Cash / lunas langsung → otomatis buat + complete pembayaran hutang
            $this->settleCashPayment($po, $hutang);

            // Update PO status
            $po->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            // Reload with relations
            $po->load([
                'supplier',
                'warehouse',
                'details.product',
                'createdBy',
                'updatedBy',
                'approvedBy',
                'hutang',
            ]);

            return $po;
        });
    }
}
