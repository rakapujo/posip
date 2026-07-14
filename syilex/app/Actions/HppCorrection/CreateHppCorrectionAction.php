<?php

namespace App\Actions\HppCorrection;

use App\Models\DocHppCorrection;
use App\Models\DocHppCorrectionDetail;
use App\Models\MasterProduk;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class CreateHppCorrectionAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(array $data): DocHppCorrection
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            // Check for existing draft (only 1 draft allowed globally)
            $existingDraft = DocHppCorrection::where('status', 'draft')->first();

            if ($existingDraft) {
                throw ValidationException::withMessages([
                    'draft' => ["Sudah ada draft koreksi HPP aktif: {$existingDraft->nomor_dokumen}. Selesaikan atau hapus draft tersebut terlebih dahulu."],
                ]);
            }

            // Check for locked products (products already in any draft)
            $productIds = collect($data['details'])->pluck('product_id');

            // Guard: produk serial pakai menu Koreksi HPP Serial (per-unit), bukan koreksi HPP agregat
            if (MasterProduk::whereIn('id', $productIds)->where('is_serial', true)->exists()) {
                throw ValidationException::withMessages([
                    'details' => ['Produk serial tidak bisa dikoreksi di sini. Gunakan menu Koreksi HPP Serial (per-unit).'],
                ]);
            }

            $lockedProducts = $this->getLockedProductIds();

            $conflictingProducts = $productIds->intersect($lockedProducts);
            if ($conflictingProducts->isNotEmpty()) {
                throw ValidationException::withMessages([
                    'details' => ['Beberapa produk sudah ada di draft koreksi HPP yang aktif.'],
                ]);
            }

            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber(
                'hpp_correction',
                'doc_hpp_correction',
                'nomor_dokumen'
            );

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Create header
            $correction = DocHppCorrection::create([
                'nomor_dokumen' => $nomorDokumen,
                'tanggal_koreksi' => $data['tanggal_koreksi'],
                'status' => 'draft',
                'notes' => $notes,
            ]);

            // Create details
            foreach ($data['details'] as $detail) {
                // Get current HPP from product
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
            $correction->load(['details.product', 'createdBy']);

            return $correction;
        });
    }

    /**
     * Get IDs of products that are locked in any draft.
     */
    private function getLockedProductIds(): array
    {
        return DocHppCorrectionDetail::whereHas('correction', function ($query) {
            $query->where('status', 'draft');
        })->pluck('product_id')->toArray();
    }
}
