<?php

namespace App\Actions\PembayaranPiutang;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\PembayaranPiutang\Concerns\ValidatesPiutangAllocations;
use App\Models\DocPembayaranPiutang;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;

class CreatePembayaranPiutangAction
{
    use RequiresAuthenticatedUser, ValidatesPiutangAllocations;

    public function execute(array $data): DocPembayaranPiutang
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($data) {
            [$cash, $deposit] = $this->validateAllocations($data);

            $payment = DocPembayaranPiutang::create([
                'nomor_dokumen' => SettingService::generateDocumentNumber(
                    'payment_piutang',
                    'doc_pembayaran_piutang',
                    date: $data['tanggal'],
                ),
                'tanggal' => $data['tanggal'],
                'customer_id' => $data['customer_id'],
                'total_bayar_cash' => $cash,
                'total_bayar_deposit' => $deposit,
                'total_pembayaran' => $cash + $deposit,
                'metode_pembayaran' => $data['metode_pembayaran'],
                'no_referensi' => isset($data['no_referensi']) ? SettingService::formatName($data['no_referensi']) : null,
                'bank_nama' => $data['bank_nama'] ?? null,
                'bank_rekening' => $data['bank_rekening'] ?? null,
                'notes' => isset($data['notes']) ? SettingService::formatName($data['notes']) : null,
                'status' => 'draft',
            ]);

            $payment->details()->createMany($data['details']);
            $payment->depositUsages()->createMany($data['deposit_usages'] ?? []);

            return $payment->load([
                'customer',
                'details.piutang.sales',
                'depositUsages.deposit',
                'createdBy',
            ]);
        });
    }
}
