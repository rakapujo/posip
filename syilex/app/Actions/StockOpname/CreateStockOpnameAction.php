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

class CreateStockOpnameAction
{
    use RequiresAuthenticatedUser;
    use HasInventoryStock;
    use ResolvesOpnamePresentUnits;

    /**
     * Execute the action.
     */
    public function execute(array $data): DocStockOpname
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            if (! SettingService::isElektronikEnabled()) {
                foreach ($data['details'] as $i => $detail) {
                    if (! empty($detail['serial_unit_ids_present'])) {
                        throw ValidationException::withMessages([
                            "details.{$i}.serial_unit_ids_present" => ['Modul Elektronik nonaktif. Fitur serial tidak tersedia.'],
                        ]);
                    }
                }
            }

            // Lock draft-per-WH di dalam TX (cegah race double-draft).
            $existingDraft = DocStockOpname::where('warehouse_id', $data['warehouse_id'])
                ->where('status', 'draft')
                ->lockForUpdate()
                ->first();

            if ($existingDraft) {
                throw ValidationException::withMessages([
                    'warehouse_id' => ["Sudah ada draft stock opname untuk warehouse ini: {$existingDraft->nomor_dokumen}. Selesaikan atau hapus draft tersebut terlebih dahulu."],
                ]);
            }
            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber(
                'stock_opname',
                'doc_stock_opname',
                'nomor_dokumen'
            );

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Create header
            $opname = DocStockOpname::create([
                'nomor_dokumen' => $nomorDokumen,
                'warehouse_id' => $data['warehouse_id'],
                'tanggal_opname' => $data['tanggal_opname'],
                'mode' => $data['mode'] ?? 'partial',
                'status' => 'draft',
                'notes' => $notes,
            ]);

            // Produk serial: fisik dihitung dari checklist SN yang HADIR (bukan input angka)
            $serialProductIds = MasterProduk::whereIn('id', collect($data['details'])->pluck('product_id'))
                ->where('is_serial', true)
                ->pluck('id');

            // Create details
            foreach ($data['details'] as $i => $detail) {
                // Get current stock for this warehouse (qty_system)
                $qtySystem = $this->getCurrentStock($detail['product_id'], $data['warehouse_id']);

                $presentIds = null;
                if ($serialProductIds->contains($detail['product_id'])) {
                    $presentIds = $this->resolvePresentUlids($detail, $i, (int) $detail['product_id'], (int) $data['warehouse_id']);
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
            $opname->load(['warehouse', 'details.product', 'createdBy']);

            return $opname;
        });
    }
}
