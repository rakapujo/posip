<?php

namespace App\Actions\PembayaranHutang;

use App\Models\DocPembayaranHutang;
use App\Models\SupplierDeposit;
use App\Models\SupplierHutang;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class CompletePembayaranHutangAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(DocPembayaranHutang $pembayaran): DocPembayaranHutang
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$pembayaran->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya pembayaran dengan status draft yang dapat di-complete.'],
            ]);
        }

        // Validate has details
        if ($pembayaran->details()->count() === 0) {
            throw ValidationException::withMessages([
                'details' => ['Pembayaran harus memiliki minimal 1 detail hutang.'],
            ]);
        }

        return DB::transaction(function () use ($pembayaran) {
            // Load details with relationships
            $pembayaran->load([
                'details.hutang',
                'depositUsages.deposit',
            ]);

            // Validate totals match
            $calculatedCash = $pembayaran->details->where('sumber', 'cash')->sum('nominal_dibayar');
            $calculatedDeposit = $pembayaran->details->where('sumber', 'deposit')->sum('nominal_dibayar');

            if (abs($calculatedCash - $pembayaran->total_bayar_cash) > 0.01) {
                throw ValidationException::withMessages([
                    'total_bayar_cash' => ['Total pembayaran cash tidak sesuai dengan detail.'],
                ]);
            }

            if (abs($calculatedDeposit - $pembayaran->total_bayar_deposit) > 0.01) {
                throw ValidationException::withMessages([
                    'total_bayar_deposit' => ['Total pembayaran deposit tidak sesuai dengan detail.'],
                ]);
            }

            // Validate deposit usage totals
            if ($pembayaran->depositUsages->count() > 0) {
                $totalDepositUsed = $pembayaran->depositUsages->sum('nominal_digunakan');
                if (abs($totalDepositUsed - $pembayaran->total_bayar_deposit) > 0.01) {
                    throw ValidationException::withMessages([
                        'deposit_usages' => ['Total deposit yang digunakan tidak sesuai dengan alokasi pembayaran.'],
                    ]);
                }
            }

            // Get all hutang IDs
            $hutangIds = $pembayaran->details->pluck('hutang_id')->unique()->toArray();

            // Lock hutang rows for update
            $hutangs = SupplierHutang::whereIn('id', $hutangIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            // Validate all hutangs exist and have sufficient remaining balance
            foreach ($pembayaran->details as $detail) {
                $hutang = $hutangs[$detail->hutang_id] ?? null;
                if (!$hutang) {
                    throw ValidationException::withMessages([
                        'details' => ['Hutang tidak ditemukan.'],
                    ]);
                }
            }

            // Group details by hutang_id and sum nominal_dibayar
            $paymentsByHutang = $pembayaran->details
                ->groupBy('hutang_id')
                ->map(fn ($details) => $details->sum('nominal_dibayar'));

            // Validate payment doesn't exceed remaining hutang
            foreach ($paymentsByHutang as $hutangId => $totalPayment) {
                $hutang = $hutangs[$hutangId];
                if ($totalPayment > (float) $hutang->sisa_hutang + 0.01) {
                    // Hutang bisa berasal dari PO biasa ATAU pembelian serial (po_id null) → null-safe.
                    $ref = $hutang->purchaseOrder?->nomor_dokumen
                        ?? $hutang->serialIntake?->nomor_dokumen
                        ?? ('hutang #' . $hutang->id);
                    throw ValidationException::withMessages([
                        'details' => ["Pembayaran untuk {$ref} melebihi sisa hutang."],
                    ]);
                }
            }

            // Lock and validate deposits
            if ($pembayaran->depositUsages->count() > 0) {
                $depositIds = $pembayaran->depositUsages->pluck('deposit_id')->toArray();
                $deposits = SupplierDeposit::whereIn('id', $depositIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('id');

                foreach ($pembayaran->depositUsages as $usage) {
                    $deposit = $deposits[$usage->deposit_id] ?? null;
                    if (!$deposit) {
                        throw ValidationException::withMessages([
                            'deposit_usages' => ['Deposit tidak ditemukan.'],
                        ]);
                    }

                    if ((float) $usage->nominal_digunakan > (float) $deposit->sisa_deposit + 0.01) {
                        throw ValidationException::withMessages([
                            'deposit_usages' => ["Penggunaan deposit melebihi sisa deposit yang tersedia."],
                        ]);
                    }
                }

                // Update deposits
                foreach ($pembayaran->depositUsages as $usage) {
                    $deposit = $deposits[$usage->deposit_id];
                    $deposit->use((float) $usage->nominal_digunakan);
                }
            }

            // Update hutangs
            foreach ($paymentsByHutang as $hutangId => $totalPayment) {
                $hutang = $hutangs[$hutangId];
                $hutang->recordPayment((float) $totalPayment);
            }

            // Update pembayaran status
            $pembayaran->update([
                'status' => 'completed',
                'completed_at' => now(),
                'completed_by' => Auth::id(),
            ]);

            // Reload with relations
            $pembayaran->load([
                'supplier',
                'details.hutang.purchaseOrder',
                'depositUsages.deposit',
                'createdBy',
                'updatedBy',
                'completedBy',
            ]);

            return $pembayaran;
        });
    }
}
