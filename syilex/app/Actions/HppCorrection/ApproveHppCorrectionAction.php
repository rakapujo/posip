<?php

namespace App\Actions\HppCorrection;

use App\Models\DocHppCorrection;
use App\Models\MasterProduk;
use App\Models\StockCard;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class ApproveHppCorrectionAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(DocHppCorrection $correction): DocHppCorrection
    {
        $this->ensureAuthenticated();

        if (!$correction->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya koreksi HPP dengan status draft yang dapat diapprove.'],
            ]);
        }

        return DB::transaction(function () use ($correction) {
            // Load details with products
            $correction->load('details.product');

            foreach ($correction->details as $detail) {
                $product = MasterProduk::lockForUpdate()->find($detail->product_id);

                if (!$product) {
                    throw ValidationException::withMessages([
                        'product' => ["Produk dengan ID {$detail->product_id} tidak ditemukan."],
                    ]);
                }

                // Get current HPP at approval time (might differ from when draft was created)
                $currentHpp = (float) $product->avg_cost;

                // Update product avg_cost
                $product->update(['avg_cost' => $detail->hpp_baru]);

                // Sync to all inventory_stock records
                $product->syncAvgCostToInventoryStocks();

                // Build notes for stock_card
                $alasanLabel = DocHppCorrection::getAlasanLabel($detail->alasan);
                $stockCardNotes = $alasanLabel;
                if ($detail->notes) {
                    $stockCardNotes .= ': ' . $detail->notes;
                }

                // Record in stock_card (for Pergerakan HPP)
                // warehouse_id is null because HPP is global
                StockCard::record([
                    'product_id' => $detail->product_id,
                    'warehouse_id' => null,
                    'transaction_type' => 'HPP_CORRECTION',
                    'transaction_id' => $correction->id,
                    'transaction_no' => $correction->nomor_dokumen,
                    'tanggal' => $correction->tanggal_koreksi,
                    'qty_in' => 0,
                    'qty_out' => 0,
                    'cost_per_unit' => $detail->hpp_baru,
                    'avg_cost_before' => $currentHpp,
                    'avg_cost_after' => $detail->hpp_baru,
                    'notes' => $stockCardNotes,
                ]);

                // Update the detail with actual hpp_lama at approval time
                $detail->update(['hpp_lama' => $currentHpp]);
            }

            // Update header status
            $correction->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            // Reload for response
            $correction->load(['details.product', 'createdBy', 'updatedBy', 'approvedBy']);

            return $correction;
        });
    }
}
