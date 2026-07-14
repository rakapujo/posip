<?php

namespace App\Actions\SerialChange;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\SerialChange\Concerns\HandlesSerialChangeUnits;
use App\Models\DocSerialChange;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Buat Perubahan Data Serial sebagai DRAFT.
 * Snapshot nilai lama + nilai baru per unit; BELUM mengubah unit (apply saat approve).
 */
class CreateSerialChangeAction
{
    use RequiresAuthenticatedUser, HandlesSerialChangeUnits;

    public function execute(array $data): DocSerialChange
    {
        $this->ensureAuthenticated();

        [$product, $serialUnits, $newSerials] = $this->validateChangePayload((int) $data['product_id'], $data['units']);
        $units = $data['units'];

        return DB::transaction(function () use ($product, $data, $units, $serialUnits, $newSerials) {
            $nomor = SettingService::generateDocumentNumber('serial_change', 'doc_serial_change');

            $change = DocSerialChange::create([
                'nomor_dokumen' => $nomor,
                'tanggal' => $data['tanggal'] ?? now(),
                'product_id' => $product->id,
                'total_unit' => count($units),
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
            ]);

            $this->createChangeDetails($change, $units, $serialUnits, $newSerials);

            return $change->load(['product', 'details']);
        });
    }
}
