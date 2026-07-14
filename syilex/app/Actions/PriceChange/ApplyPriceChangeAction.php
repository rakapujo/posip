<?php

namespace App\Actions\PriceChange;

use App\Models\DocPriceChange;
use App\Models\MasterProduk;
use App\Models\PriceChangeTriggerLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class ApplyPriceChangeAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action (scheduled → applied).
     *
     * @param DocPriceChange $priceChange
     * @param int|null $triggeredBy User ID or null for auto-trigger
     * @param string $triggerType 'manual' (UI) or 'cron' (scheduler batch)
     * @return DocPriceChange
     */
    public function execute(DocPriceChange $priceChange, ?int $triggeredBy = null, string $triggerType = 'manual'): DocPriceChange
    {
        $this->ensureAuthenticated();

        if (!$priceChange->isScheduled()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya dokumen dengan status scheduled yang dapat diapply.'],
            ]);
        }

        return DB::transaction(function () use ($priceChange, $triggeredBy, $triggerType) {
            // Load details with products
            $priceChange->load('details.product');

            foreach ($priceChange->details as $detail) {
                // Lock product row for update
                $product = MasterProduk::lockForUpdate()->find($detail->product_id);

                if (!$product) {
                    throw ValidationException::withMessages([
                        'product' => ["Produk dengan ID {$detail->product_id} tidak ditemukan."],
                    ]);
                }

                // Capture current prices at apply time (for audit trail)
                $currentHarga1 = (float) $product->harga_1;
                $currentHarga2 = (float) $product->harga_2;
                $currentHarga3 = (float) $product->harga_3;
                $currentHarga4 = (float) $product->harga_4;

                // Update product prices
                $product->update([
                    'harga_1' => $detail->harga_1_baru,
                    'harga_2' => $detail->harga_2_baru,
                    'harga_3' => $detail->harga_3_baru,
                    'harga_4' => $detail->harga_4_baru,
                ]);

                // Update the detail with actual harga_lama at apply time (for audit trail)
                $detail->update([
                    'harga_1_lama' => $currentHarga1,
                    'harga_2_lama' => $currentHarga2,
                    'harga_3_lama' => $currentHarga3,
                    'harga_4_lama' => $currentHarga4,
                ]);
            }

            // Update header status
            $priceChange->update([
                'status' => 'applied',
                'applied_at' => now(),
                'applied_by' => $triggeredBy,
            ]);

            // Log manual apply only — cron batch log ditulis oleh ApplyScheduledPriceChangesCommand.
            if ($triggerType === 'manual') {
                PriceChangeTriggerLog::create([
                    'triggered_at' => now(),
                    'documents_processed' => 1,
                    'trigger_type' => 'manual',
                    'triggered_by' => $triggeredBy,
                    'notes' => "Applied document: {$priceChange->nomor_dokumen}",
                ]);
            }

            // Reload for response
            $priceChange->load(['details.product', 'createdBy', 'updatedBy', 'approvedBy', 'appliedBy']);

            return $priceChange;
        });
    }
}
