<?php

namespace App\Actions\PriceChange;

use App\Models\DocPriceChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class ApprovePriceChangeAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action (draft → scheduled).
     */
    public function execute(DocPriceChange $priceChange): DocPriceChange
    {
        $this->ensureAuthenticated();

        if (!$priceChange->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya dokumen dengan status draft yang dapat diapprove.'],
            ]);
        }

        // Check if has details
        if ($priceChange->details()->count() === 0) {
            throw ValidationException::withMessages([
                'details' => ['Dokumen harus memiliki minimal 1 produk untuk diapprove.'],
            ]);
        }

        return DB::transaction(function () use ($priceChange) {
            // Update header status to scheduled
            $priceChange->update([
                'status' => 'scheduled',
                'approved_at' => now(),
                'approved_by' => auth()->id(),
            ]);

            // Reload for response
            $priceChange->load(['details.product', 'createdBy', 'updatedBy', 'approvedBy']);

            return $priceChange;
        });
    }
}
