<?php

namespace App\Actions\PembayaranHutang;

use App\Models\DocPembayaranHutang;
use App\Models\DocPembayaranHutangDetail;
use App\Models\DocPembayaranHutangDeposit;
use App\Models\SupplierDeposit;
use App\Models\SupplierHutang;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Actions\Concerns\RequiresAuthenticatedUser;

class CreatePembayaranHutangAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(array $data): DocPembayaranHutang
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            $details = collect($data['details'] ?? []);
            $usages = collect($data['deposit_usages'] ?? []);
            if ($details->pluck('hutang_id')->duplicates()->isNotEmpty()
                || $usages->pluck('deposit_id')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['details' => ['Alokasi hutang/deposit tidak boleh duplikat.']]);
            }

            // Generate document number
            $nomorDokumen = SettingService::generateDocumentNumber(
                'payment_hutang',
                'doc_pembayaran_hutang',
                'nomor_dokumen'
            );

            // Calculate totals
            $totalBayarCash = 0;
            $totalBayarDeposit = 0;

            // Validate and calculate cash payments from details
            if (!empty($data['details'])) {
                foreach ($data['details'] as $detail) {
                    $hutang = SupplierHutang::find($detail['hutang_id']);
                    if (!$hutang || $hutang->supplier_id != $data['supplier_id']) {
                        throw ValidationException::withMessages([
                            'details' => ['Hutang tidak valid atau bukan milik supplier yang dipilih.'],
                        ]);
                    }

                    if ($detail['sumber'] === 'cash') {
                        $totalBayarCash += (float) $detail['nominal_dibayar'];
                    } else {
                        $totalBayarDeposit += (float) $detail['nominal_dibayar'];
                    }
                }
            }

            // Validate deposit usage — always match when deposit allocated (incl. empty usages).
            $calculatedDepositTotal = 0.0;
            if (! empty($data['deposit_usages'])) {
                foreach ($data['deposit_usages'] as $usage) {
                    $deposit = SupplierDeposit::find($usage['deposit_id']);
                    if (! $deposit || $deposit->supplier_id != $data['supplier_id']) {
                        throw ValidationException::withMessages([
                            'deposit_usages' => ['Deposit tidak valid atau bukan milik supplier yang dipilih.'],
                        ]);
                    }
                    $calculatedDepositTotal += (float) $usage['nominal_digunakan'];
                }
            }
            if (abs($calculatedDepositTotal - $totalBayarDeposit) > 0.01) {
                throw ValidationException::withMessages([
                    'deposit_usages' => ['Total deposit yang digunakan tidak sesuai dengan alokasi pembayaran.'],
                ]);
            }

            $totalPembayaran = $totalBayarCash + $totalBayarDeposit;

            // Q5: reject draft over-allocation vs remaining hutang (sum per hutang_id).
            if (! empty($data['details'])) {
                $byHutang = collect($data['details'])->groupBy('hutang_id')
                    ->map(fn ($rows) => (float) collect($rows)->sum('nominal_dibayar'));
                foreach ($byHutang as $hutangId => $amount) {
                    $hutang = SupplierHutang::find($hutangId);
                    if ($hutang && $amount > (float) $hutang->sisa_hutang + 0.01) {
                        throw ValidationException::withMessages([
                            'details' => ['Total alokasi melebihi sisa hutang.'],
                        ]);
                    }
                }
            }

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Format no_referensi
            $noReferensi = isset($data['no_referensi'])
                ? SettingService::formatName($data['no_referensi'])
                : null;

            // Create header
            $pembayaran = DocPembayaranHutang::create([
                'nomor_dokumen' => $nomorDokumen,
                'tanggal' => $data['tanggal'],
                'supplier_id' => $data['supplier_id'],
                'total_bayar_cash' => $totalBayarCash,
                'total_bayar_deposit' => $totalBayarDeposit,
                'total_pembayaran' => $totalPembayaran,
                'metode_pembayaran' => $data['metode_pembayaran'] ?? 'cash',
                'no_referensi' => $noReferensi,
                'bank_nama' => $data['bank_nama'] ?? null,
                'bank_rekening' => $data['bank_rekening'] ?? null,
                'notes' => $notes,
                'status' => 'draft',
            ]);

            // Create details
            if (!empty($data['details'])) {
                foreach ($data['details'] as $detail) {
                    DocPembayaranHutangDetail::create([
                        'pembayaran_id' => $pembayaran->id,
                        'hutang_id' => $detail['hutang_id'],
                        'nominal_dibayar' => $detail['nominal_dibayar'],
                        'sumber' => $detail['sumber'],
                    ]);
                }
            }

            // Create deposit usages
            if (!empty($data['deposit_usages'])) {
                foreach ($data['deposit_usages'] as $usage) {
                    DocPembayaranHutangDeposit::create([
                        'pembayaran_id' => $pembayaran->id,
                        'deposit_id' => $usage['deposit_id'],
                        'nominal_digunakan' => $usage['nominal_digunakan'],
                    ]);
                }
            }

            // Load relations for response
            $pembayaran->load([
                'supplier',
                'details.hutang.purchaseOrder',
                'depositUsages.deposit',
                'createdBy',
            ]);

            return $pembayaran;
        });
    }
}
