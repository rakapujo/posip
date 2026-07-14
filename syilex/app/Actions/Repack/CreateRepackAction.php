<?php

namespace App\Actions\Repack;

use App\Models\DocRepack;
use App\Models\DocRepackInput;
use App\Models\DocRepackOutput;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class CreateRepackAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(array $data): DocRepack
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber(
                'repack',
                'doc_repack',
                'nomor_dokumen'
            );

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Create header
            $repack = DocRepack::create([
                'nomor_dokumen' => $nomorDokumen,
                'warehouse_id' => $data['warehouse_id'],
                'tipe' => $data['tipe'],
                'tanggal' => $data['tanggal'],
                'biaya_repack' => $data['biaya_repack'] ?? 0,
                'total_cost_input' => 0,
                'total_cost_output' => 0,
                'status' => 'draft',
                'notes' => $notes,
            ]);

            // Create input items (bahan)
            foreach ($data['inputs'] as $input) {
                DocRepackInput::create([
                    'repack_id' => $repack->id,
                    'product_id' => $input['product_id'],
                    'qty' => $input['qty'],
                    'cost_per_unit' => 0, // Will be calculated on approve
                    'total_cost' => 0,
                ]);
            }

            // Create output items (hasil)
            foreach ($data['outputs'] as $output) {
                DocRepackOutput::create([
                    'repack_id' => $repack->id,
                    'product_id' => $output['product_id'],
                    'qty' => $output['qty'],
                    'cost_per_unit' => 0, // Will be calculated on approve
                    'total_cost' => 0,
                ]);
            }

            // Load relations for response
            $repack->load(['warehouse', 'inputs.product', 'outputs.product', 'createdBy']);

            return $repack;
        });
    }
}
