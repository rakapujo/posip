<?php

namespace App\Actions\Adjustment;

use App\Models\DocAdjustment;
use App\Models\DocAdjustmentDetail;
use App\Models\MasterProduk;
use App\Services\SettingService;
use App\Traits\HasInventoryStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class UpdateAdjustmentAction
{
    use RequiresAuthenticatedUser;

    use HasInventoryStock;

    /**
     * Execute the action.
     */
    public function execute(DocAdjustment $adjustment, array $data): DocAdjustment
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$adjustment->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya adjustment dengan status draft yang dapat diedit.'],
            ]);
        }

        return DB::transaction(function () use ($adjustment, $data) {
            // Format keterangan
            $keterangan = isset($data['keterangan'])
                ? SettingService::formatName($data['keterangan'])
                : null;

            // Update header
            $adjustment->update([
                'warehouse_id' => $data['warehouse_id'],
                'tanggal' => $data['tanggal'],
                'keterangan' => $keterangan,
            ]);

            // Delete existing details
            $adjustment->details()->delete();

            // Produk serial: debit dilarang; kredit wajib pilih unit (qty = jumlah unit)
            $serialProductIds = MasterProduk::whereIn('id', collect($data['details'])->pluck('product_id'))
                ->where('is_serial', true)
                ->pluck('id');

            // Re-create details with fresh stock data
            foreach ($data['details'] as $detail) {
                $serialUnitIds = null;
                $serialUnitStatuses = null;
                $qty = $detail['qty'];

                if ($serialProductIds->contains($detail['product_id'])) {
                    if ($detail['jenis'] === 'debit') {
                        throw ValidationException::withMessages([
                            'details' => ['Produk serial tidak bisa ditambah via Adjustment. Gunakan Pembelian Serial.'],
                        ]);
                    }
                    $serialUnitIds = $detail['serial_unit_ids'] ?? [];
                    if (empty($serialUnitIds)) {
                        throw ValidationException::withMessages([
                            'details' => ['Produk serial wajib memilih unit (nomor seri) yang dikeluarkan.'],
                        ]);
                    }
                    $qty = count($serialUnitIds);
                    $serialUnitStatuses = $this->buildSerialUnitStatuses($serialUnitIds, $detail['serial_unit_statuses'] ?? []);
                }

                // Get current stock for this warehouse (refresh)
                $stokSistem = $this->getCurrentStock($detail['product_id'], $data['warehouse_id']);

                // Calculate stok_akhir
                $stokAkhir = $detail['jenis'] === 'debit'
                    ? $stokSistem + $qty
                    : $stokSistem - $qty;

                // Format notes
                $notes = isset($detail['notes'])
                    ? SettingService::formatName($detail['notes'])
                    : null;

                DocAdjustmentDetail::create([
                    'adjustment_id' => $adjustment->id,
                    'product_id' => $detail['product_id'],
                    'jenis' => $detail['jenis'],
                    'stok_sistem' => $stokSistem,
                    'qty' => $qty,
                    'stok_akhir' => $stokAkhir,
                    'notes' => $notes,
                    'serial_unit_ids' => $serialUnitIds,
                    'serial_unit_statuses' => $serialUnitStatuses,
                ]);
            }

            // Reload with relations
            $adjustment->load(['warehouse', 'details.product', 'createdBy']);

            return $adjustment;
        });
    }

    /**
     * Bentuk map status fate per unit serial keluar: {ulid: rusak|hilang}, default 'rusak'.
     */
    private function buildSerialUnitStatuses(array $serialUnitIds, array $raw): array
    {
        $map = [];
        foreach ($serialUnitIds as $ulid) {
            $s = $raw[$ulid] ?? 'rusak';
            $map[$ulid] = in_array($s, ['rusak', 'hilang'], true) ? $s : 'rusak';
        }

        return $map;
    }
}
