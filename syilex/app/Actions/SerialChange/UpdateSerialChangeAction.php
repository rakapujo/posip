<?php

namespace App\Actions\SerialChange;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\SerialChange\Concerns\HandlesSerialChangeUnits;
use App\Models\DocSerialChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Ubah Perubahan Data Serial — hanya saat draft. Detail diganti penuh.
 */
class UpdateSerialChangeAction
{
    use RequiresAuthenticatedUser, HandlesSerialChangeUnits;

    public function execute(DocSerialChange $change, array $data): DocSerialChange
    {
        $this->ensureAuthenticated();

        if (!$change->isDraft()) {
            throw ValidationException::withMessages(['status' => ['Hanya draft yang dapat diubah.']]);
        }

        [$product, $serialUnits, $newSerials] = $this->validateChangePayload((int) $data['product_id'], $data['units']);
        $units = $data['units'];

        return DB::transaction(function () use ($change, $product, $data, $units, $serialUnits, $newSerials) {
            $change->update([
                'tanggal' => $data['tanggal'] ?? $change->tanggal,
                'product_id' => $product->id,
                'total_unit' => count($units),
                'notes' => $data['notes'] ?? null,
            ]);

            $change->details()->delete();
            $this->createChangeDetails($change, $units, $serialUnits, $newSerials);

            return $change->load(['product', 'details']);
        });
    }
}
