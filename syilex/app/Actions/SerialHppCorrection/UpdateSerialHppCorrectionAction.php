<?php

namespace App\Actions\SerialHppCorrection;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\SerialHppCorrection\Concerns\HandlesHppCorrectionUnits;
use App\Models\DocSerialHppCorrection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Update Koreksi HPP Serial (draft saja) — replace detail.
 */
class UpdateSerialHppCorrectionAction
{
    use RequiresAuthenticatedUser, HandlesHppCorrectionUnits;

    public function execute(DocSerialHppCorrection $correction, array $data): DocSerialHppCorrection
    {
        $this->ensureAuthenticated();

        if (!$correction->isDraft()) {
            throw ValidationException::withMessages(['status' => ['Hanya draft yang dapat diedit.']]);
        }

        [$product, $serialUnits] = $this->validateHppPayload((int) $data['product_id'], $data['units']);
        $units = $data['units'];

        return DB::transaction(function () use ($correction, $product, $data, $units, $serialUnits) {
            $correction->update([
                'tanggal' => $data['tanggal'] ?? $correction->tanggal,
                'product_id' => $product->id,
                'total_unit' => count($units),
                'notes' => $data['notes'] ?? null,
            ]);

            $correction->details()->delete();
            $this->createCorrectionDetails($correction, $units, $serialUnits);

            return $correction->load(['product', 'details']);
        });
    }
}
