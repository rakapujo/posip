<?php

namespace App\Actions\PembayaranPiutang\Concerns;

use App\Models\CustomerDeposit;
use App\Models\CustomerPiutang;
use Illuminate\Validation\ValidationException;

trait ValidatesPiutangAllocations
{
    private function validateAllocations(array $data): array
    {
        $details = collect($data['details']);
        $usages = collect($data['deposit_usages'] ?? []);

        if ($details->map(fn ($d) => $d['piutang_id'].'|'.$d['sumber'])->duplicates()->isNotEmpty()
            || $usages->pluck('deposit_id')->duplicates()->isNotEmpty()) {
            throw ValidationException::withMessages(['details' => ['Alokasi piutang/deposit tidak boleh duplikat.']]);
        }

        $piutang = CustomerPiutang::whereIn('id', $details->pluck('piutang_id'))->get();
        $deposits = CustomerDeposit::whereIn('id', $usages->pluck('deposit_id'))->get();
        if ($piutang->count() !== $details->pluck('piutang_id')->unique()->count()
            || $piutang->contains(fn ($item) => (int) $item->customer_id !== (int) $data['customer_id'])
            || $deposits->count() !== $usages->pluck('deposit_id')->unique()->count()
            || $deposits->contains(fn ($item) => (int) $item->customer_id !== (int) $data['customer_id'])) {
            throw ValidationException::withMessages(['details' => ['Piutang atau deposit bukan milik customer yang dipilih.']]);
        }

        $cash = (float) $details->where('sumber', 'cash')->sum('nominal_dibayar');
        $deposit = (float) $details->where('sumber', 'deposit')->sum('nominal_dibayar');
        if (abs((float) $usages->sum('nominal_digunakan') - $deposit) > 0.01) {
            throw ValidationException::withMessages(['deposit_usages' => ['Total deposit tidak sesuai dengan alokasi pembayaran.']]);
        }

        $byPiutang = $details->groupBy('piutang_id')
            ->map(fn ($rows) => (float) collect($rows)->sum('nominal_dibayar'));
        foreach ($byPiutang as $piutangId => $amount) {
            $row = $piutang->firstWhere('id', $piutangId);
            if ($row && $amount > (float) $row->sisa_piutang + 0.01) {
                throw ValidationException::withMessages(['details' => ['Total alokasi melebihi sisa piutang.']]);
            }
        }

        $byDeposit = $usages->groupBy('deposit_id')
            ->map(fn ($rows) => (float) collect($rows)->sum('nominal_digunakan'));
        foreach ($byDeposit as $depositId => $amount) {
            $row = $deposits->firstWhere('id', $depositId);
            if ($row && $amount > (float) $row->sisa_deposit + 0.01) {
                throw ValidationException::withMessages(['deposit_usages' => ['Penggunaan deposit melebihi saldo tersedia.']]);
            }
        }

        return [$cash, $deposit];
    }
}
