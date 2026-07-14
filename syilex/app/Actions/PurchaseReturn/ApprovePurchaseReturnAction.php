<?php

namespace App\Actions\PurchaseReturn;

use App\Models\DocPurchaseReturn;
use App\Models\SupplierDeposit;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class ApprovePurchaseReturnAction
{
    use RequiresAuthenticatedUser;

    /**
     * Approve purchase return and create supplier deposit.
     *
     * APPROVE = input nilai_diakui, create deposit
     */
    public function execute(DocPurchaseReturn $retur, array $data): DocPurchaseReturn
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$retur->canApprove()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya retur dengan status lock yang dapat disetujui.'],
            ]);
        }

        // Validate nilai_diakui
        $nilaiDiakui = (float) ($data['nilai_diakui'] ?? 0);
        if ($nilaiDiakui < 0) {
            throw ValidationException::withMessages([
                'nilai_diakui' => ['Nilai diakui tidak boleh negatif.'],
            ]);
        }

        return DB::transaction(function () use ($retur, $data, $nilaiDiakui) {
            // Calculate selisih
            $nilaiKalkulasi = (float) $retur->nilai_kalkulasi;
            $selisih = $nilaiDiakui - $nilaiKalkulasi;

            // Update retur with approval data
            $retur->update([
                'nilai_diakui' => $nilaiDiakui,
                'selisih' => $selisih,
                'catatan_approval' => $data['catatan_approval'] ?? null,
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            // Create supplier deposit only if nilai_diakui > 0
            if ($nilaiDiakui > 0) {
                SupplierDeposit::create([
                    'supplier_id' => $retur->supplier_id,
                    'retur_id' => $retur->id,
                    'no_referensi' => $retur->nomor_dokumen,
                    'tanggal' => $retur->tanggal,
                    'nominal_awal' => $nilaiDiakui,
                    'nominal_terpakai' => 0,
                    'sisa_deposit' => $nilaiDiakui,
                    'status' => 'available',
                    'created_at' => now(),
                ]);
            }

            // Reload with relations
            $retur->load([
                'warehouse',
                'supplier',
                'details.product',
                'deposit',
                'createdBy',
                'lockedBy',
                'approvedBy',
            ]);

            return $retur;
        });
    }
}
