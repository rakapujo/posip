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

class UpdateStockOpnameAction
{
    use RequiresAuthenticatedUser;

    use HasInventoryStock;

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
            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Update header
            $opname->update([
                'warehouse_id' => $data['warehouse_id'],
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
            foreach ($data['details'] as $detail) {
                // Get current stock for this warehouse (qty_system)
                $qtySystem = $this->getCurrentStock($detail['product_id'], $data['warehouse_id']);

                $presentIds = null;
                if ($serialProductIds->contains($detail['product_id'])) {
                    $presentIds = $detail['serial_unit_ids_present'] ?? [];
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
