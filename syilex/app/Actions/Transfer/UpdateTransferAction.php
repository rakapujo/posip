<?php

namespace App\Actions\Transfer;

use App\Actions\Serial\Concerns\ResolvesSelectedUnits;
use App\Models\DocTransfer;
use App\Models\DocTransferDetail;
use App\Models\MasterProduk;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class UpdateTransferAction
{
    use RequiresAuthenticatedUser;
    use ResolvesSelectedUnits;

    /**
     * Execute the action.
     */
    public function execute(DocTransfer $transfer, array $data): DocTransfer
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$transfer->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya transfer dengan status draft yang dapat diedit.'],
            ]);
        }

        return DB::transaction(function () use ($transfer, $data) {
            if (! SettingService::isElektronikEnabled()) {
                foreach ($data['details'] as $i => $detail) {
                    if (! empty($detail['serial_unit_ids'])) {
                        throw ValidationException::withMessages([
                            "details.{$i}.serial_unit_ids" => ['Modul Elektronik nonaktif. Fitur serial tidak tersedia.'],
                        ]);
                    }
                }
            }

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Update header
            $transfer->update([
                'warehouse_from_id' => $data['warehouse_from_id'],
                'warehouse_to_id' => $data['warehouse_to_id'],
                'tanggal' => $data['tanggal'],
                'notes' => $notes,
                'biaya_kirim' => $data['biaya_kirim'] ?? 0,
                'biaya_lain' => $data['biaya_lain'] ?? 0,
                'biaya_lain_nama' => isset($data['biaya_lain_nama']) ? SettingService::formatName($data['biaya_lain_nama']) : null,
                'masuk_hpp' => (bool) ($data['masuk_hpp'] ?? false),
            ]);

            // Delete existing details
            $transfer->details()->delete();

            // Produk serial: qty diturunkan dari jumlah unit dipilih (wajib ada)
            $serialProductIds = MasterProduk::whereIn('id', collect($data['details'])->pluck('product_id'))
                ->where('is_serial', true)
                ->pluck('id');

            // Re-create details
            foreach ($data['details'] as $i => $detail) {
                $serialUnitIds = null;
                $qty = $detail['qty'];

                if ($serialProductIds->contains($detail['product_id'])) {
                    $serialUnitIds = array_values(array_unique(array_filter(
                        $detail['serial_unit_ids'] ?? [],
                        fn ($u) => $u !== null && $u !== ''
                    )));
                    if ($serialUnitIds === []) {
                        throw ValidationException::withMessages([
                            'details' => ['Produk serial wajib memilih unit (nomor seri) yang ditransfer.'],
                        ]);
                    }
                    $this->resolveSelectedUnits(
                        $serialUnitIds,
                        (int) $detail['product_id'],
                        (int) $data['warehouse_from_id'],
                        null,
                        "details.{$i}.serial_unit_ids"
                    );
                    $qty = count($serialUnitIds);
                }

                DocTransferDetail::create([
                    'transfer_id' => $transfer->id,
                    'product_id' => $detail['product_id'],
                    'qty' => $qty,
                    'serial_unit_ids' => $serialUnitIds,
                ]);
            }

            // Reload with relations
            $transfer->load(['warehouseFrom', 'warehouseTo', 'details.product', 'createdBy']);

            return $transfer;
        });
    }
}
