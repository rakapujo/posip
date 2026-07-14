<?php

namespace App\Actions\HppCorrection;

use App\Models\DocHppCorrection;
use App\Models\DocHppCorrectionDetail;
use App\Models\MasterProduk;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class UpdateHppCorrectionAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(DocHppCorrection $correction, array $data): DocHppCorrection
    {
        $this->ensureAuthenticated();

        if (!$correction->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya koreksi HPP dengan status draft yang dapat diedit.'],
            ]);
        }

        return DB::transaction(function () use ($correction, $data) {
            // Check for locked products (products in OTHER drafts, not this one)
            $productIds = collect($data['details'])->pluck('product_id');
            $lockedProducts = $this->getLockedProductIds($correction->id);

            $conflictingProducts = $productIds->intersect($lockedProducts);
            if ($conflictingProducts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'details' => ['Beberapa produk sudah ada di draft koreksi HPP lain.'],
                ]);
            }

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Update header
            $correction->update([
                'tanggal_koreksi' => $data['tanggal_koreksi'],
                'notes' => $notes,
            ]);

            // Delete existing details
            $correction->details()->delete();

            // Create new details
            foreach ($data['details'] as $detail) {
                // Get current HPP from product (refresh at update time)
                $product = MasterProduk::find($detail['product_id']);
                $hppLama = $product ? (float) $product->avg_cost : 0;

                // Format notes
                $detailNotes = isset($detail['notes'])
                    ? SettingService::formatName($detail['notes'])
                    : null;

                DocHppCorrectionDetail::create([
                    'correction_id' => $correction->id,
                    'product_id' => $detail['product_id'],
                    'hpp_lama' => $hppLama,
                    'hpp_baru' => $detail['hpp_baru'],
                    'alasan' => $detail['alasan'],
                    'notes' => $detailNotes,
                ]);
            }

            // Load relations for response
            $correction->load(['details.product', 'createdBy', 'updatedBy']);

            return $correction;
        });
    }

    /**
     * Get IDs of products that are locked in other drafts.
     */
    private function getLockedProductIds(int $excludeCorrectionId): array
    {
        return DocHppCorrectionDetail::whereHas('correction', function ($query) use ($excludeCorrectionId) {
            $query->where('status', 'draft')
                  ->where('id', '!=', $excludeCorrectionId);
        })->pluck('product_id')->toArray();
    }
}
