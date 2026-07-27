<?php

namespace App\Actions\PembayaranHutang;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Models\DocPembayaranHutang;
use App\Models\SupplierDeposit;
use App\Models\SupplierHutang;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CompletePembayaranHutangAction
{
    use RequiresAuthenticatedUser;

    public function execute(DocPembayaranHutang $pembayaran): DocPembayaranHutang
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($pembayaran) {
            $pembayaran = DocPembayaranHutang::whereKey($pembayaran->id)->lockForUpdate()->firstOrFail();
            if (! $pembayaran->isDraft()) {
                throw ValidationException::withMessages([
                    'status' => ['Hanya pembayaran dengan status draft yang dapat di-complete.'],
                ]);
            }

            $pembayaran->load(['details.hutang', 'depositUsages.deposit']);
            if ($pembayaran->details->isEmpty()) {
                throw ValidationException::withMessages([
                    'details' => ['Pembayaran harus memiliki minimal 1 detail hutang.'],
                ]);
            }

            $calculatedCash = (float) $pembayaran->details->where('sumber', 'cash')->sum('nominal_dibayar');
            $calculatedDeposit = (float) $pembayaran->details->where('sumber', 'deposit')->sum('nominal_dibayar');

            if (abs($calculatedCash - (float) $pembayaran->total_bayar_cash) > 0.01
                || abs($calculatedDeposit - (float) $pembayaran->total_bayar_deposit) > 0.01
                || abs($calculatedCash + $calculatedDeposit - (float) $pembayaran->total_pembayaran) > 0.01) {
                throw ValidationException::withMessages([
                    'details' => ['Total pembayaran tidak sesuai dengan detail.'],
                ]);
            }

            // Always match usages ↔ deposit total (incl. empty usages when deposit > 0).
            if (abs((float) $pembayaran->depositUsages->sum('nominal_digunakan') - $calculatedDeposit) > 0.01) {
                throw ValidationException::withMessages([
                    'deposit_usages' => ['Total deposit yang digunakan tidak sesuai dengan alokasi pembayaran.'],
                ]);
            }

            $paymentsByHutang = $pembayaran->details
                ->groupBy('hutang_id')
                ->map(fn ($details) => (float) $details->sum('nominal_dibayar'));

            $hutangs = SupplierHutang::whereIn('id', $paymentsByHutang->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($paymentsByHutang as $hutangId => $totalPayment) {
                $hutang = $hutangs->get($hutangId);
                if (! $hutang || (int) $hutang->supplier_id !== (int) $pembayaran->supplier_id) {
                    throw ValidationException::withMessages([
                        'details' => ['Hutang tidak valid untuk supplier pembayaran.'],
                    ]);
                }
                if ($totalPayment > (float) $hutang->sisa_hutang + 0.01) {
                    $ref = $hutang->purchaseOrder?->nomor_dokumen
                        ?? $hutang->serialIntake?->nomor_dokumen
                        ?? ('hutang #'.$hutang->id);
                    throw ValidationException::withMessages([
                        'details' => ["Pembayaran untuk {$ref} melebihi sisa hutang."],
                    ]);
                }
            }

            $usageByDeposit = $pembayaran->depositUsages
                ->groupBy('deposit_id')
                ->map(fn ($usages) => (float) $usages->sum('nominal_digunakan'));

            $deposits = SupplierDeposit::whereIn('id', $usageByDeposit->keys())
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            foreach ($usageByDeposit as $depositId => $amount) {
                $deposit = $deposits->get($depositId);
                if (! $deposit || (int) $deposit->supplier_id !== (int) $pembayaran->supplier_id) {
                    throw ValidationException::withMessages([
                        'deposit_usages' => ['Deposit tidak valid untuk supplier pembayaran.'],
                    ]);
                }
                if ($amount > (float) $deposit->sisa_deposit + 0.01) {
                    throw ValidationException::withMessages([
                        'deposit_usages' => ['Penggunaan deposit melebihi sisa deposit yang tersedia.'],
                    ]);
                }
            }

            foreach ($usageByDeposit as $depositId => $amount) {
                $deposits[$depositId]->use($amount);
            }
            foreach ($paymentsByHutang as $hutangId => $totalPayment) {
                $hutangs[$hutangId]->recordPayment($totalPayment);
            }

            $pembayaran->update([
                'status' => 'completed',
                'completed_at' => SettingService::now(),
                'completed_by' => Auth::id(),
            ]);

            return $pembayaran->load([
                'supplier',
                'details.hutang.purchaseOrder',
                'details.hutang.serialIntake',
                'depositUsages.deposit',
                'createdBy',
                'updatedBy',
                'completedBy',
            ]);
        });
    }
}
