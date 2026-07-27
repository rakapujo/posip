<?php

namespace App\Actions\SalesReturn;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\CustomerDeposit;
use App\Models\CustomerPiutang;
use App\Models\DocSales;
use App\Models\DocSalesReturn;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ApproveSalesReturnAction
{
    use RequiresAuthenticatedUser;

    public function execute(DocSalesReturn $return, array $data): DocSalesReturn
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($return, $data) {
            $return = DocSalesReturn::manual()->lockForUpdate()->findOrFail($return->id);
            if (! $return->canApprove()) {
                throw ValidationException::withMessages(['status' => ['Hanya retur lock yang dapat disetujui.']]);
            }

            $recognized = (float) $data['nilai_diakui'];
            if ($recognized > (float) $return->grand_total) {
                throw ValidationException::withMessages([
                    'nilai_diakui' => ['Nilai diakui tidak boleh melebihi grand total retur.'],
                ]);
            }
            $credited = 0.0;

            if ($return->sales_id) {
                $sales = DocSales::manual()->lockForUpdate()->findOrFail($return->sales_id);
                $piutang = CustomerPiutang::where('sales_id', $sales->id)->lockForUpdate()->first();
                if (! $piutang) {
                    throw ValidationException::withMessages(['sales_id' => ['Piutang penjualan asal tidak ditemukan.']]);
                }
                $credited = $piutang->recordReturnCredit($recognized);
            } else {
                $remaining = $recognized;
                $piutangs = CustomerPiutang::where('customer_id', $return->customer_id)
                    ->where('sisa_piutang', '>', 0)
                    ->orderBy('tanggal')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                foreach ($piutangs as $piutang) {
                    if ($remaining <= 0) {
                        break;
                    }
                    $c = $piutang->recordReturnCredit($remaining);
                    $credited += $c;
                    $remaining -= $c;
                }
            }

            $depositAmount = $recognized - $credited;

            if ($depositAmount > 0) {
                CustomerDeposit::create([
                    'customer_id' => $return->customer_id,
                    'retur_id' => $return->id,
                    'no_referensi' => $return->nomor_dokumen,
                    'keterangan' => $return->sales_id
                        ? 'Kelebihan nilai retur penjualan backoffice'
                        : 'Retur bebas — sisa setelah net piutang',
                    'tanggal' => $return->tanggal,
                    'nominal_awal' => $depositAmount,
                    'nominal_terpakai' => 0,
                    'sisa_deposit' => $depositAmount,
                    'status' => 'available',
                    'created_by' => Auth::id(),
                ]);
            }

            $return->update([
                'nilai_diakui' => $recognized,
                'selisih' => $recognized - (float) $return->grand_total,
                'catatan_approval' => $data['catatan_approval'] ?? null,
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => Auth::id(),
            ]);

            return $return->fresh([
                'sales',
                'warehouse',
                'customer',
                'details.product',
                'deposit',
                'lockedBy',
                'approvedBy',
            ]);
        });
    }
}
