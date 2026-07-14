<?php

namespace App\Actions\StockOpname;

use App\Actions\Adjustment\ApproveAdjustmentAction;
use App\Models\DocAdjustment;
use App\Models\DocAdjustmentDetail;
use App\Models\DocStockOpname;
use App\Models\InventoryStock;
use App\Models\SerialUnit;
use App\Models\StockCard;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class ApproveStockOpnameAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(DocStockOpname $opname): DocStockOpname
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$opname->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya stock opname dengan status draft yang dapat disetujui.'],
            ]);
        }

        return DB::transaction(function () use ($opname) {
            // Load details with products
            $opname->load('details.product');

            // Skip observer to prevent duplicate entries
            StockCard::$skipObserver = true;

            try {
                // Collect items with difference for adjustment
                $adjustmentDetails = [];

                foreach ($opname->details as $detail) {
                    // Get current stock (refresh from database)
                    $currentStock = InventoryStock::where('product_id', $detail->product_id)
                        ->where('warehouse_id', $opname->warehouse_id)
                        ->lockForUpdate()
                        ->first();

                    $currentQty = $currentStock ? (int) $currentStock->qty : 0;

                    // Update detail with latest qty_system
                    $detail->update([
                        'qty_system' => $currentQty,
                        'qty_difference' => $detail->qty_physical - $currentQty,
                    ]);

                    // Record stock_card for STOCK_OPNAME (recording only, no qty change)
                    $avgCost = $detail->product->avg_cost ?? 0;

                    StockCard::record([
                        'product_id' => $detail->product_id,
                        'warehouse_id' => $opname->warehouse_id,
                        'transaction_type' => 'STOCK_OPNAME',
                        'transaction_id' => $opname->id,
                        'transaction_no' => $opname->nomor_dokumen,
                        'tanggal' => $opname->tanggal_opname,
                        'qty_in' => 0,
                        'qty_out' => 0,
                        'cost_per_unit' => $avgCost,
                        'avg_cost_before' => $avgCost,
                        'avg_cost_after' => $avgCost,
                        'notes' => "Opname: sistem={$currentQty}, fisik={$detail->qty_physical}",
                    ]);

                    // Collect items with difference for adjustment
                    if ($detail->qty_difference !== 0) {
                        $serialUnitIds = null;

                        if ($detail->product->is_serial) {
                            // Selisih LEBIH untuk serial dilarang (unit baru hanya via Pembelian Serial)
                            if ($detail->qty_difference > 0) {
                                throw ValidationException::withMessages([
                                    'details' => ["Produk serial {$detail->product->nama_produk} tidak boleh selisih lebih saat opname. Daftarkan unit lewat Pembelian Serial."],
                                ]);
                            }
                            // Selisih KURANG: unit hilang = tersedia di gudang \ yang dicentang hadir
                            $tersedia = SerialUnit::byProduct($detail->product_id)
                                ->byWarehouse($opname->warehouse_id)
                                ->tersedia()
                                ->pluck('ulid')
                                ->all();
                            $present = $detail->serial_unit_ids_present ?? [];
                            $serialUnitIds = array_values(array_diff($tersedia, $present));
                        }

                        $adjustmentDetails[] = [
                            'product_id' => $detail->product_id,
                            'jenis' => $detail->qty_difference > 0 ? 'debit' : 'kredit',
                            'qty' => $serialUnitIds !== null ? count($serialUnitIds) : abs($detail->qty_difference),
                            'notes' => $detail->notes,
                            'serial_unit_ids' => $serialUnitIds,
                        ];
                    }
                }

                // Generate adjustment if there are differences
                if (!empty($adjustmentDetails)) {
                    $this->createAndApproveAdjustment($opname, $adjustmentDetails);
                }

                // Update opname status
                $opname->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => Auth::id(),
                ]);
            } finally {
                StockCard::$skipObserver = false;
            }

            // Reload with relations
            $opname->load(['warehouse', 'details.product', 'createdBy', 'approvedBy', 'adjustment']);

            return $opname;
        });
    }

    /**
     * Create and auto-approve adjustment from opname differences.
     */
    protected function createAndApproveAdjustment(DocStockOpname $opname, array $details): DocAdjustment
    {
        // Generate document number for adjustment
        $nomorDokumen = SettingService::generateDocumentNumber(
            'adjustment',
            'doc_adjustment',
            'nomor_dokumen'
        );

        // Create adjustment header
        $adjustment = DocAdjustment::create([
            'nomor_dokumen' => $nomorDokumen,
            'warehouse_id' => $opname->warehouse_id,
            'tanggal' => $opname->tanggal_opname,
            'keterangan' => "Auto-generated dari Stock Opname: {$opname->nomor_dokumen}",
            'status' => 'draft',
            'source' => 'opname',
            'opname_id' => $opname->id,
        ]);

        // Create adjustment details
        foreach ($details as $detail) {
            // Get current stock
            $stock = InventoryStock::where('product_id', $detail['product_id'])
                ->where('warehouse_id', $opname->warehouse_id)
                ->first();

            $stokSistem = $stock ? (int) $stock->qty : 0;
            $stokAkhir = $detail['jenis'] === 'debit'
                ? $stokSistem + $detail['qty']
                : $stokSistem - $detail['qty'];

            DocAdjustmentDetail::create([
                'adjustment_id' => $adjustment->id,
                'product_id' => $detail['product_id'],
                'jenis' => $detail['jenis'],
                'stok_sistem' => $stokSistem,
                'qty' => $detail['qty'],
                'stok_akhir' => $stokAkhir,
                'notes' => $detail['notes'] ?? 'Koreksi dari Stock Opname',
                'serial_unit_ids' => $detail['serial_unit_ids'] ?? null,
            ]);
        }

        // Auto-approve the adjustment (Flow A)
        $approveAction = new ApproveAdjustmentAction();
        $adjustment = $approveAction->execute($adjustment);

        return $adjustment;
    }
}
