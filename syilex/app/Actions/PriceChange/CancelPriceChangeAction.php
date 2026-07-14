<?php

namespace App\Actions\PriceChange;

use App\Models\DocPriceChange;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class CancelPriceChangeAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action (scheduled → draft).
     */
    public function execute(DocPriceChange $priceChange): DocPriceChange
    {
        $this->ensureAuthenticated();

        if (!$priceChange->isScheduled()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya dokumen dengan status scheduled yang dapat dibatalkan.'],
            ]);
        }

        return DB::transaction(function () use ($priceChange) {
            // Update header status back to draft
            $priceChange->update([
                'status' => 'draft',
                'approved_at' => null,
                'approved_by' => null,
            ]);

            // Reload for response
            $priceChange->load(['details.product', 'createdBy', 'updatedBy']);

            return $priceChange;
        });
    }
}
