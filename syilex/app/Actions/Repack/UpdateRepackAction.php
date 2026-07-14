<?php

namespace App\Actions\Repack;

use App\Models\DocRepack;
use App\Models\DocRepackInput;
use App\Models\DocRepackOutput;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class UpdateRepackAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(DocRepack $repack, array $data): DocRepack
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$repack->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya repack dengan status draft yang dapat diedit.'],
            ]);
        }

        return DB::transaction(function () use ($repack, $data) {
            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Update header
            $repack->update([
                'warehouse_id' => $data['warehouse_id'],
                'tipe' => $data['tipe'],
                'tanggal' => $data['tanggal'],
                'biaya_repack' => $data['biaya_repack'] ?? 0,
                'notes' => $notes,
            ]);

            // Delete existing inputs and outputs
            $repack->inputs()->delete();
            $repack->outputs()->delete();

            // Re-create input items (bahan)
            foreach ($data['inputs'] as $input) {
                DocRepackInput::create([
                    'repack_id' => $repack->id,
                    'product_id' => $input['product_id'],
                    'qty' => $input['qty'],
                    'cost_per_unit' => 0,
                    'total_cost' => 0,
                ]);
            }

            // Re-create output items (hasil)
            foreach ($data['outputs'] as $output) {
                DocRepackOutput::create([
                    'repack_id' => $repack->id,
                    'product_id' => $output['product_id'],
                    'qty' => $output['qty'],
                    'cost_per_unit' => 0,
                    'total_cost' => 0,
                ]);
            }

            // Reload with relations
            $repack->load(['warehouse', 'inputs.product', 'outputs.product', 'createdBy']);

            return $repack;
        });
    }
}
