<?php

namespace App\Actions\SerialIntake;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\Concerns\SettlesCashPayment;
use App\Models\DocSerialIntake;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\StockCard;
use App\Models\SupplierHutang;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve Pembelian Serial (draft → approved) — komit stok + HPP.
 *
 * Perlakuan stok/HPP = SAMA seperti pembelian (lihat ApprovePurchaseOrderAction):
 *   - inventory_stock.qty += N (lockForUpdate, updateOrCreate)
 *   - avg_cost produk di-recalc weighted-average (modal rata-rata batch)
 *   - stock_card PURCHASE dicatat dgn pola StockCard::$skipObserver (CLAUDE.md §7)
 *   - unit: pending → tersedia (baru bisa dijual di POS)
 */
class ApproveSerialIntakeAction
{
    use RequiresAuthenticatedUser;
    use SettlesCashPayment;

    public function execute(DocSerialIntake $intake): DocSerialIntake
    {
        $this->ensureAuthenticated();

        if (!$intake->isDraft()) {
            throw ValidationException::withMessages(['status' => ['Hanya intake draft yang dapat disetujui.']]);
        }
        if ($intake->units()->count() === 0) {
            throw ValidationException::withMessages(['units' => ['Intake tidak memiliki unit.']]);
        }

        return DB::transaction(function () use ($intake) {
            // Lock header & re-assert draft DI DALAM transaksi (cegah double-approve race —
            // dua request paralel bisa lolos cek isDraft() di luar transaksi → dobel stok/hutang).
            $intake = DocSerialIntake::where('id', $intake->id)->lockForUpdate()->firstOrFail();
            if (!$intake->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Intake sudah diproses, tidak bisa disetujui ulang.']]);
            }

            // Lock produk + stok gudang
            $product = MasterProduk::where('id', $intake->product_id)->lockForUpdate()->first();
            $stock = InventoryStock::where('product_id', $product->id)
                ->where('warehouse_id', $intake->warehouse_id)
                ->lockForUpdate()
                ->first();

            $units = $intake->units()->lockForUpdate()->get();
            $qtyIn = $units->count();
            // HPP pakai LANDED cost per-unit (modal + alokasi diskon/biaya/pajak header)
            $landedTotal = (float) $units->sum('cost_per_unit');
            $avgBatchCost = $qtyIn > 0 ? $landedTotal / $qtyIn : 0;

            $oldHpp = (float) $product->avg_cost;
            $currentWhStock = $stock ? (int) $stock->qty : 0;
            $newWhStock = $currentWhStock + $qtyIn;

            $newHpp = $product->recalculateAvgCost($qtyIn, $avgBatchCost);
            $product->syncAvgCostToInventoryStocks();

            StockCard::$skipObserver = true;
            try {
                InventoryStock::updateOrCreate(
                    ['product_id' => $product->id, 'warehouse_id' => $intake->warehouse_id],
                    ['qty' => $newWhStock, 'avg_cost' => $newHpp]
                );

                StockCard::record([
                    'product_id' => $product->id,
                    'warehouse_id' => $intake->warehouse_id,
                    'transaction_type' => 'PURCHASE',
                    'transaction_id' => $intake->id,
                    'transaction_no' => $intake->nomor_dokumen,
                    'tanggal' => $intake->tanggal,
                    'qty_in' => $qtyIn,
                    'qty_out' => 0,
                    'cost_per_unit' => $avgBatchCost,
                    'avg_cost_before' => $oldHpp,
                    'avg_cost_after' => $newHpp,
                    'notes' => "Pembelian Serial {$intake->nomor_dokumen}",
                ]);
            } finally {
                StockCard::$skipObserver = false;
            }

            // Unit pending → tersedia
            $intake->units()->where('status', 'pending')->update(['status' => 'tersedia']);

            // Header → approved
            $intake->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            // Hutang supplier (jika ada supplier & grand total > 0) — seperti PO
            if ($intake->supplier_id && (float) $intake->grand_total > 0) {
                $hutang = SupplierHutang::create([
                    'supplier_id' => $intake->supplier_id,
                    'po_id' => null,
                    'serial_intake_id' => $intake->id,
                    'tanggal' => $intake->tanggal,
                    'tanggal_jatuh_tempo' => $intake->tanggal_jatuh_tempo,
                    'nominal_awal' => $intake->grand_total,
                    'nominal_terbayar' => 0,
                    'sisa_hutang' => $intake->grand_total,
                    'status' => 'unpaid',
                ]);

                // Cash / lunas langsung → otomatis buat + complete pembayaran hutang
                $this->settleCashPayment($intake, $hutang);
            }

            return $intake->load(['product', 'warehouse', 'supplier', 'units', 'approvedBy']);
        });
    }
}
