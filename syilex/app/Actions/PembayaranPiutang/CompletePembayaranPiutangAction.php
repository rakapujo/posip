<?php

namespace App\Actions\PembayaranPiutang;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\CustomerDeposit;
use App\Models\CustomerPiutang;
use App\Models\DocPembayaranPiutang;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompletePembayaranPiutangAction
{
    use RequiresAuthenticatedUser;

    public function execute(DocPembayaranPiutang $payment): DocPembayaranPiutang
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($payment) {
            $payment = DocPembayaranPiutang::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $payment->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Hanya pembayaran draft yang dapat diselesaikan.']]);
            }

            $payment->load(['details', 'depositUsages']);
            if ($payment->details->isEmpty()) {
                throw ValidationException::withMessages(['details' => ['Pembayaran harus memiliki minimal satu detail.']]);
            }

            $cash = (float) $payment->details->where('sumber', 'cash')->sum('nominal_dibayar');
            $depositTotal = (float) $payment->details->where('sumber', 'deposit')->sum('nominal_dibayar');
            if (abs($cash - (float) $payment->total_bayar_cash) > 0.01
                || abs($depositTotal - (float) $payment->total_bayar_deposit) > 0.01
                || abs($cash + $depositTotal - (float) $payment->total_pembayaran) > 0.01) {
                throw ValidationException::withMessages(['details' => ['Total pembayaran tidak sesuai dengan detail.']]);
            }
            if (abs((float) $payment->depositUsages->sum('nominal_digunakan') - $depositTotal) > 0.01) {
                throw ValidationException::withMessages(['deposit_usages' => ['Total deposit tidak sesuai dengan alokasi pembayaran.']]);
            }

            $paymentsByPiutang = $payment->details->groupBy('piutang_id')
                ->map(fn ($details) => (float) $details->sum('nominal_dibayar'));
            $piutang = CustomerPiutang::whereIn('id', $paymentsByPiutang->keys())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($paymentsByPiutang as $id => $amount) {
                $item = $piutang->get($id);
                if (! $item || (int) $item->customer_id !== (int) $payment->customer_id) {
                    throw ValidationException::withMessages(['details' => ['Piutang tidak valid untuk customer pembayaran.']]);
                }
                if ($amount > (float) $item->sisa_piutang + 0.01) {
                    throw ValidationException::withMessages([
                        'details' => ['Pembayaran untuk '.($item->sales?->nomor_dokumen ?? "piutang #{$id}").' melebihi sisa piutang.'],
                    ]);
                }
            }

            $usageByDeposit = $payment->depositUsages->groupBy('deposit_id')
                ->map(fn ($usages) => (float) $usages->sum('nominal_digunakan'));
            $deposits = CustomerDeposit::whereIn('id', $usageByDeposit->keys())
                ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            foreach ($usageByDeposit as $id => $amount) {
                $item = $deposits->get($id);
                if (! $item || (int) $item->customer_id !== (int) $payment->customer_id) {
                    throw ValidationException::withMessages(['deposit_usages' => ['Deposit tidak valid untuk customer pembayaran.']]);
                }
                if ($amount > (float) $item->sisa_deposit + 0.01) {
                    throw ValidationException::withMessages(['deposit_usages' => ['Penggunaan deposit melebihi saldo tersedia.']]);
                }
            }

            foreach ($usageByDeposit as $id => $amount) {
                $deposits[$id]->use($amount);
            }
            foreach ($paymentsByPiutang as $id => $amount) {
                $piutang[$id]->recordPayment($amount);
            }

            $payment->update([
                'status' => 'completed',
                'completed_at' => SettingService::now(),
                'completed_by' => Auth::id(),
            ]);

            return $payment->load([
                'customer',
                'details.piutang.sales',
                'depositUsages.deposit',
                'createdBy',
                'updatedBy',
                'completedBy',
            ]);
        });
    }
}
