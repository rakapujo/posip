<?php

namespace App\Actions\PembayaranPiutang;

use App\Actions\Concerns\RequiresAuthenticatedUser;
use App\Actions\PembayaranPiutang\Concerns\ValidatesPiutangAllocations;
use App\Models\DocPembayaranPiutang;
use App\Services\SettingService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdatePembayaranPiutangAction
{
    use RequiresAuthenticatedUser, ValidatesPiutangAllocations;

    public function execute(DocPembayaranPiutang $payment, array $data): DocPembayaranPiutang
    {
        $this->ensureAuthenticated();

        return DB::transaction(function () use ($payment, $data) {
            $payment = DocPembayaranPiutang::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if (! $payment->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Hanya pembayaran draft yang dapat diubah.']]);
            }

            [$cash, $deposit] = $this->validateAllocations(array_merge($data, [
                'customer_id' => $payment->customer_id,
            ]));

            $payment->update([
                'tanggal' => $data['tanggal'],
                'total_bayar_cash' => $cash,
                'total_bayar_deposit' => $deposit,
                'total_pembayaran' => $cash + $deposit,
                'metode_pembayaran' => $data['metode_pembayaran'],
                'no_referensi' => isset($data['no_referensi']) ? SettingService::formatName($data['no_referensi']) : null,
                'bank_nama' => $data['bank_nama'] ?? null,
                'bank_rekening' => $data['bank_rekening'] ?? null,
                'notes' => isset($data['notes']) ? SettingService::formatName($data['notes']) : null,
            ]);
            $payment->details()->delete();
            $payment->depositUsages()->delete();
            $payment->details()->createMany($data['details']);
            $payment->depositUsages()->createMany($data['deposit_usages'] ?? []);

            return $payment->load(['customer', 'details.piutang.sales', 'depositUsages.deposit', 'createdBy', 'updatedBy']);
        });
    }
}
