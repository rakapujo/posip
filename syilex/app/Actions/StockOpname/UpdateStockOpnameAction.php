<?php

namespace App\Actions\StockOpname;

use App\Models\DocStockOpname;
use App\Models\DocStockOpnameDetail;
use App\Models\MasterProduk;
use App\Services\SettingService;
use App\Traits\HasInventoryStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\StockOpname\Concerns\ResolvesOpnamePresentUnits;

class UpdateStockOpnameAction
{
    use RequiresAuthenticatedUser;
    use HasInventoryStock;
    use ResolvesOpnamePresentUnits;

    /**
     * Execute the action.
     */
    public function execute(DocStockOpname $opname, array $data): DocStockOpname
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$opname->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya stock opname dengan status draft yang dapat diubah.'],
            ]);
        }

        return DB::transaction(function () use ($opname, $data) {
            if (! SettingService::isElektronikEnabled()) {
                foreach ($data['details'] as $i => $detail) {
                    if (! empty($detail['serial_unit_ids_present'])) {
                        throw ValidationException::withMessages([
                            "details.{$i}.serial_unit_ids_present" => ['Modul Elektronik nonaktif. Fitur serial tidak tersedia.'],
                        ]);
                    }
                }
            }

            $warehouseId = (int) ($data['warehouse_id'] ?? $opname->warehouse_id);
            if ($warehouseId !== (int) $opname->warehouse_id) {
                $existingDraft = DocStockOpname::where('warehouse_id', $warehouseId)
                    ->where('status', 'draft')
                    ->where('id', '!=', $opname->id)
                    ->lockForUpdate()
                    ->first();
                if ($existingDraft) {
                    throw ValidationException::withMessages([
                        'warehouse_id' => ["Sudah ada draft stock opname untuk warehouse ini: {$existingDraft->nomor_dokumen}. Selesaikan atau hapus draft tersebut terlebih dahulu."],
                    ]);
                }
            }

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            $opname->update([
                'warehouse_id' => $warehouseId,
                'tanggal_opname' => $data['tanggal_opname'],
                'mode' => $data['mode'] ?? $opname->mode,
                'notes' => $notes,
            ]);

            // Delete existing details
            $opname->details()->delete();

            // Produk serial: fisik dihitung dari checklist SN yang HADIR
            $serialProductIds = MasterProduk::whereIn('id', collect($data['details'])->pluck('product_id'))
                ->where('is_serial', true)
                ->pluck('id');

            // Create new details
            foreach ($data['details'] as $i => $detail) {
                // Get current stock for this warehouse (qty_system)
                $qtySystem = $this->getCurrentStock($detail['product_id'], $warehouseId);

                $presentIds = null;
                if ($serialProductIds->contains($detail['product_id'])) {
                    $presentIds = $this->resolvePresentUlids($detail, $i, (int) $detail['product_id'], $warehouseId);
                    $qtyPhysical = count($presentIds);
                } else {
                    $qtyPhysical = (int) $detail['qty_physical'];
                }

                // Calculate difference: physical - system
                $qtyDifference = $qtyPhysical - $qtySystem;

                // Format notes
                $detailNotes = isset($detail['notes'])
                    ? SettingService::formatName($detail['notes'])
                    : null;

                DocStockOpnameDetail::create([
                    'opname_id' => $opname->id,
                    'product_id' => $detail['product_id'],
                    'qty_system' => $qtySystem,
                    'qty_physical' => $qtyPhysical,
                    'qty_difference' => $qtyDifference,
                    'notes' => $detailNotes,
                    'serial_unit_ids_present' => $presentIds,
                ]);
            }

            // Load relations for response
            $opname->load(['warehouse', 'details.product', 'createdBy', 'updatedBy']);

            return $opname;
        });
    }
}
