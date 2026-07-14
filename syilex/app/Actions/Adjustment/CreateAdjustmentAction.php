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

class CreateAdjustmentAction
{
    use RequiresAuthenticatedUser;

    use HasInventoryStock;

    /**
     * Execute the action.
     */
    public function execute(array $data): DocAdjustment
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            // Produk serial (modul serial A+): debit (masuk) DILARANG permanen
            // (unit lahir hanya via Pembelian Serial); kredit (keluar) WAJIB pilih unit.
            $serialProductIds = MasterProduk::whereIn('id', collect($data['details'])->pluck('product_id'))
                ->where('is_serial', true)
                ->pluck('id');

            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber(
                'adjustment',
                'doc_adjustment',
                'nomor_dokumen'
            );

            // Format keterangan
            $keterangan = isset($data['keterangan'])
                ? SettingService::formatName($data['keterangan'])
                : null;

            // Create header
            $adjustment = DocAdjustment::create([
                'nomor_dokumen' => $nomorDokumen,
                'warehouse_id' => $data['warehouse_id'],
                'tanggal' => $data['tanggal'],
                'keterangan' => $keterangan,
                'status' => 'draft',
            ]);

            // Create details
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
                    // kredit: wajib pilih unit; qty = jumlah unit
                    $serialUnitIds = $detail['serial_unit_ids'] ?? [];
                    if (empty($serialUnitIds)) {
                        throw ValidationException::withMessages([
                            'details' => ['Produk serial wajib memilih unit (nomor seri) yang dikeluarkan.'],
                        ]);
                    }
                    $qty = count($serialUnitIds);
                    $serialUnitStatuses = $this->buildSerialUnitStatuses($serialUnitIds, $detail['serial_unit_statuses'] ?? []);
                }

                // Get current stock for this warehouse
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

            // Load relations for response
            $adjustment->load(['warehouse', 'details.product', 'createdBy']);

            return $adjustment;
        });
    }

    /**
     * Bentuk map status fate per unit serial keluar: {ulid: rusak|hilang}, default 'rusak'.
     * Hanya untuk ulid yang benar-benar dipilih; nilai non-valid dipaksa 'rusak'.
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
