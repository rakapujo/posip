<?php

namespace App\Actions\Sales;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\CustomerPiutang;
use App\Models\DocPembayaranPiutangDetail;
use App\Models\DocSales;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class VoidManualSalesAction
{
    use RequiresAuthenticatedUser;

    public function execute(DocSales $sales, string $reason): DocSales
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($sales, $reason) {
            $sales = DocSales::manual()->whereKey($sales->id)->lockForUpdate()->firstOrFail();
            $piutang = CustomerPiutang::where('sales_id', $sales->id)->lockForUpdate()->first();
            $hasCompletedAllocation = $piutang && DocPembayaranPiutangDetail::where('piutang_id', $piutang->id)
                ->whereHas('pembayaran', fn ($query) => $query->completed())
                ->exists();
            $hasCommittedReturn = $sales->returns()->whereIn('status', ['lock', 'approved'])->exists();

            if (! $sales->isCompleted()
                || $sales->cash_payment
                || ! $piutang
                || $piutang->status !== 'unpaid'
                || (float) $piutang->nominal_terbayar !== 0.0
                || $hasCompletedAllocation
                || $hasCommittedReturn) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya penjualan tempo yang sepenuhnya belum dibayar dan belum diretur yang dapat di-void.'],
                ]);
            }

            $sales = (new VoidSalesAction)->execute($sales, $reason);
            $piutang->update([
                'sisa_piutang' => 0,
                'status' => 'cancelled',
            ]);

            return $sales->load(['customer', 'warehouse', 'details.product', 'piutang']);
        });
    }
}
