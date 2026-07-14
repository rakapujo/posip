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

class UpdatePembayaranHutangAction
{
    use RequiresAuthenticatedUser;

    /**
     * Execute the action.
     */
    public function execute(DocPembayaranHutang $pembayaran, array $data): DocPembayaranHutang
    {
        $this->ensureAuthenticated();

        // Validate status
        if (!$pembayaran->isDraft()) {
            throw ValidationException::withMessages([
                'status' => ['Hanya pembayaran dengan status draft yang dapat diubah.'],
            ]);
        }

        return DB::transaction(function () use ($pembayaran, $data) {
            // Calculate totals
            $totalBayarCash = 0;
            $totalBayarDeposit = 0;

            // Validate and calculate cash payments from details
            if (!empty($data['details'])) {
                foreach ($data['details'] as $detail) {
                    $hutang = SupplierHutang::find($detail['hutang_id']);
                    if (!$hutang || $hutang->supplier_id != $pembayaran->supplier_id) {
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

            // Validate deposit usage
            if (!empty($data['deposit_usages'])) {
                $calculatedDepositTotal = 0;
                foreach ($data['deposit_usages'] as $usage) {
                    $deposit = SupplierDeposit::find($usage['deposit_id']);
                    if (!$deposit || $deposit->supplier_id != $pembayaran->supplier_id) {
                        throw ValidationException::withMessages([
                            'deposit_usages' => ['Deposit tidak valid atau bukan milik supplier yang dipilih.'],
                        ]);
                    }
                    $calculatedDepositTotal += (float) $usage['nominal_digunakan'];
                }

                // Validate deposit usage matches detail deposit amounts
                if (abs($calculatedDepositTotal - $totalBayarDeposit) > 0.01) {
                    throw ValidationException::withMessages([
                        'deposit_usages' => ['Total deposit yang digunakan tidak sesuai dengan alokasi pembayaran.'],
                    ]);
                }
            }

            $totalPembayaran = $totalBayarCash + $totalBayarDeposit;

            // Format notes
            $notes = isset($data['notes'])
                ? SettingService::formatName($data['notes'])
                : null;

            // Format no_referensi
            $noReferensi = isset($data['no_referensi'])
                ? SettingService::formatName($data['no_referensi'])
                : null;

            // Update header
            $pembayaran->update([
                'tanggal' => $data['tanggal'],
                'total_bayar_cash' => $totalBayarCash,
                'total_bayar_deposit' => $totalBayarDeposit,
                'total_pembayaran' => $totalPembayaran,
                'metode_pembayaran' => $data['metode_pembayaran'] ?? 'cash',
                'no_referensi' => $noReferensi,
                'bank_nama' => $data['bank_nama'] ?? null,
                'bank_rekening' => $data['bank_rekening'] ?? null,
                'notes' => $notes,
            ]);

            // Delete existing details and deposit usages
            $pembayaran->details()->delete();
            $pembayaran->depositUsages()->delete();

            // Create new details
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

            // Create new deposit usages
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
                'updatedBy',
            ]);

            return $pembayaran;
        });
    }
}
