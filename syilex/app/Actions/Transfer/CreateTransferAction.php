<?php

namespace App\Actions\Transfer;

use App\Models\DocTransfer;
use App\Models\DocTransferDetail;
use App\Models\MasterProduk;
use App\Services\SettingService;
use App\Traits\HasInventoryStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class CreateTransferAction
{
    use RequiresAuthenticatedUser;

    use HasInventoryStock;

    /**
     * Execute the action.
     */
    public function execute(array $data): DocTransfer
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber(
                'transfer',
                'doc_transfer',
                'nomor_dokumen'
            );

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Create header
            $transfer = DocTransfer::create([
                'nomor_dokumen' => $nomorDokumen,
                'warehouse_from_id' => $data['warehouse_from_id'],
                'warehouse_to_id' => $data['warehouse_to_id'],
                'tanggal' => $data['tanggal'],
                'notes' => $notes,
                'biaya_kirim' => $data['biaya_kirim'] ?? 0,
                'biaya_lain' => $data['biaya_lain'] ?? 0,
                'biaya_lain_nama' => isset($data['biaya_lain_nama']) ? SettingService::formatName($data['biaya_lain_nama']) : null,
                'masuk_hpp' => (bool) ($data['masuk_hpp'] ?? false),
                'status' => 'draft',
            ]);

            // Produk serial: qty diturunkan dari jumlah unit dipilih (wajib ada)
            $serialProductIds = MasterProduk::whereIn('id', collect($data['details'])->pluck('product_id'))
                ->where('is_serial', true)
                ->pluck('id');

            // Create details
            foreach ($data['details'] as $detail) {
                $serialUnitIds = null;
                $qty = $detail['qty'];

                if ($serialProductIds->contains($detail['product_id'])) {
                    $serialUnitIds = $detail['serial_unit_ids'] ?? [];
                    if (empty($serialUnitIds)) {
                        throw ValidationException::withMessages([
                            'details' => ['Produk serial wajib memilih unit (nomor seri) yang ditransfer.'],
                        ]);
                    }
                    $qty = count($serialUnitIds);
                }

                DocTransferDetail::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $detail['product_id'],
                    'qty' => $qty,
                    'serial_unit_ids' => $serialUnitIds,
                ]);
            }

            // Load relations for response
            $transfer->load(['warehouseFrom', 'warehouseTo', 'details.product', 'createdBy']);

            return $transfer;
        });
    }
}
