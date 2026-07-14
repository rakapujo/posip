<?php

namespace App\Actions\SerialHppCorrection;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\SerialHppCorrection\Concerns\HandlesHppCorrectionUnits;
use App\Models\DocSerialHppCorrection;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;

/**
 * Buat Koreksi HPP Serial sebagai DRAFT (belum mengubah unit — apply saat approve).
 */
class CreateSerialHppCorrectionAction
{
    use RequiresAuthenticatedUser, HandlesHppCorrectionUnits;

    public function execute(array $data): DocSerialHppCorrection
    {
        $this->ensureAuthenticated();

        [$product, $serialUnits] = $this->validateHppPayload((int) $data['product_id'], $data['units']);
        $units = $data['units'];

        return DB::transaction(function () use ($product, $data, $units, $serialUnits) {
            $nomor = SettingService::generateDocumentNumber('serial_hpp_correction', 'doc_serial_hpp_correction');

            $correction = DocSerialHppCorrection::create([
                'nomor_dokumen' => $nomor,
                'tanggal' => $data['tanggal'] ?? now(),
                'product_id' => $product->id,
                'total_unit' => count($units),
                'notes' => $data['notes'] ?? null,
                'status' => 'draft',
            ]);

            $this->createCorrectionDetails($correction, $units, $serialUnits);

            return $correction->load(['product', 'details']);
        });
    }
}
