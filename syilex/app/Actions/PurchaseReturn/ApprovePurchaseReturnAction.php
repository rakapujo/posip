<?php

namespace App\Actions\PurchaseReturn;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\DocPurchaseReturn;
use App\Models\SupplierDeposit;
use App\Models\SupplierHutang;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApprovePurchaseReturnAction
{
    use RequiresAuthenticatedUser;

    /**
     * Approve purchase return: net hutang first (PO / PBS linked or FIFO free), excess → supplier deposit.
     */
    public function execute(DocPurchaseReturn $retur, array $data): DocPurchaseReturn
    {
        $this->ensureAuthenticated();

        $nilaiDiakui = (float) ($data['nilai_diakui'] ?? 0);
        if ($nilaiDiakui < 0) {
            throw ValidationException::withMessages([
                'nilai_diakui' => ['Nilai diakui tidak boleh negatif.'],
            ]);
        }

        return DB::transaction(function () use ($retur, $data, $nilaiDiakui) {
            $retur = DocPurchaseReturn::lockForUpdate()->findOrFail($retur->id);
            if (! $retur->canApprove()) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya retur dengan status lock yang dapat disetujui.'],
                ]);
            }

            $remaining = $nilaiDiakui;
            $credited = 0.0;

            if ($retur->po_id) {
                $hutang = SupplierHutang::where('po_id', $retur->po_id)->lockForUpdate()->first();
                if ($hutang) {
                    $credited += $hutang->recordReturnCredit($remaining);
                    $remaining -= $credited;
                }
            } elseif ($retur->serial_intake_id) {
                $hutang = SupplierHutang::where('serial_intake_id', $retur->serial_intake_id)->lockForUpdate()->first();
                if ($hutang) {
                    $credited += $hutang->recordReturnCredit($remaining);
                    $remaining -= $credited;
                }
            } else {
                $hutangs = SupplierHutang::where('supplier_id', $retur->supplier_id)
                    ->where('sisa_hutang', '>', 0)
                    ->orderBy('tanggal')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($hutangs as $hutang) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $c = $hutang->recordReturnCredit($remaining);
                    $credited += $c;
                    $remaining -= $c;
                }
            }

            $depositAmount = max(0, $remaining);

            $retur->update([
                'nilai_diakui' => $nilaiDiakui,
                'selisih' => $nilaiDiakui - (float) $retur->nilai_kalkulasi,
                'catatan_approval' => $data['catatan_approval'] ?? null,
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            if ($depositAmount > 0) {
                SupplierDeposit::create([
                    'supplier_id' => $retur->supplier_id,
                    'retur_id' => $retur->id,
                    'no_referensi' => $retur->nomor_dokumen,
                    'tanggal' => $retur->tanggal,
                    'nominal_awal' => $depositAmount,
                    'nominal_terpakai' => 0,
                    'sisa_deposit' => $depositAmount,
                    'status' => 'available',
                    'created_by' => Auth::id(),
                    'created_at' => now(),
                ]);
            }

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
