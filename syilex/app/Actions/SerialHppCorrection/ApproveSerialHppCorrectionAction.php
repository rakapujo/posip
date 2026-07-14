<?php

namespace App\Actions\SerialHppCorrection;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\DocSerialHppCorrection;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use App\Models\StockCard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Approve Koreksi HPP Serial (draft → approved) — terapkan harga_modal & cost_per_unit
 * baru ke unit TERSEDIA. Catat movement (HPP_SERIAL) per unit untuk jejak.
 *
 * PROPAGASI (Metode A): setelah unit dikoreksi, avg_cost produk direkalkulasi =
 * rata-rata cost_per_unit semua unit tersedia + dicatat stock_card HPP_CORRECTION
 * → tampil di Pergerakan HPP. Koreksi eksplisit (sah meski §2B membatasi recalc
 * otomatis ke PURCHASE/ADJUSTMENT_IN). Lihat docs/modules/serial.md §4.12.
 */
class ApproveSerialHppCorrectionAction
{
    use RequiresAuthenticatedUser;

    public function execute(DocSerialHppCorrection $correction): DocSerialHppCorrection
    {
        $this->ensureAuthenticated();

        if (!$correction->isDraft()) {
            throw ValidationException::withMessages(['status' => ['Hanya draft yang dapat disetujui.']]);
        }
        $correction->load('details');
        if ($correction->details->isEmpty()) {
            throw ValidationException::withMessages(['details' => ['Tidak ada unit untuk dikoreksi.']]);
        }

        return DB::transaction(function () use ($correction) {
            $details = $correction->details;
            $unitIds = $details->pluck('serial_unit_id')->all();

            $units = SerialUnit::whereIn('id', $unitIds)->lockForUpdate()->get()->keyBy('id');

            foreach ($details as $d) {
                $unit = $units->get($d->serial_unit_id);
                if (!$unit || $unit->status !== SerialUnit::STATUS_TERSEDIA) {
                    continue; // unit sudah terjual / keluar → lewati
                }

                // Re-snapshot nilai lama tepat sebelum apply (audit akurat)
                $d->update([
                    'before' => [
                        'harga_modal' => $unit->harga_modal,
                        'cost_per_unit' => $unit->cost_per_unit,
                    ],
                ]);

                $unit->update([
                    'harga_modal' => $d->harga_modal_baru,
                    'cost_per_unit' => $d->cost_per_unit_baru,
                ]);

                SerialUnitMovement::record([
                    'serial_unit_id' => $unit->id,
                    'doc_type' => 'HPP_SERIAL',
                    'doc_id' => $correction->id,
                    'doc_no' => $correction->nomor_dokumen,
                    'movement_type' => 'STATUS_CHANGE',
                    'from_warehouse_id' => $unit->warehouse_id,
                    'to_warehouse_id' => $unit->warehouse_id,
                    'from_status' => $unit->status,
                    'to_status' => $unit->status,
                    'tanggal' => $correction->tanggal,
                    'notes' => 'Koreksi HPP unit',
                ]);
            }

            // Propagasi ke HPP agregat (Metode A): avg_cost = rata-rata cost_per_unit
            // SEMUA unit tersedia produk → catat stock_card HPP_CORRECTION (tampil di Pergerakan HPP).
            $product = MasterProduk::lockForUpdate()->find($correction->product_id);
            if ($product) {
                $oldAvg = (float) $product->avg_cost;
                $tersedia = SerialUnit::byProduct($product->id)->tersedia()->get(['cost_per_unit']);
                $count = $tersedia->count();
                if ($count > 0) {
                    $newAvg = round((float) $tersedia->sum('cost_per_unit') / $count, 4);
                    $product->update(['avg_cost' => $newAvg]);
                    $product->syncAvgCostToInventoryStocks();

                    StockCard::record([
                        'product_id' => $product->id,
                        'warehouse_id' => null, // HPP global
                        'transaction_type' => 'HPP_CORRECTION',
                        'transaction_id' => $correction->id,
                        'transaction_no' => $correction->nomor_dokumen,
                        'tanggal' => $correction->tanggal,
                        'qty_in' => 0,
                        'qty_out' => 0,
                        'cost_per_unit' => $newAvg,
                        'avg_cost_before' => $oldAvg,
                        'avg_cost_after' => $newAvg,
                        'notes' => 'Koreksi HPP Serial ' . $correction->nomor_dokumen . ' (avg dari unit tersedia)',
                    ]);
                }
            }

            $correction->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            return $correction->load(['product', 'details', 'approvedBy']);
        });
    }
}
