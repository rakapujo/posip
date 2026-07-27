<?php

namespace App\Actions\Concerns;

use App\Models\CustomerPiutang;
use App\Models\DocPembayaranPiutang;
use App\Services\SettingService;
use Illuminate\Support\Facades\Auth;

trait SettlesCustomerPiutang
{
    protected function settleCustomerPiutang(
        CustomerPiutang $piutang,
        mixed $tanggal,
        array $payment = [],
    ): DocPembayaranPiutang {
        $amount = (float) $piutang->sisa_piutang;

        $document = DocPembayaranPiutang::create([
            'nomor_dokumen' => SettingService::generateDocumentNumber(
                'payment_piutang',
                'doc_pembayaran_piutang',
                date: $tanggal,
            ),
            'tanggal' => $tanggal,
            'customer_id' => $piutang->customer_id,
            'total_bayar_cash' => $amount,
            'total_bayar_deposit' => 0,
            'total_pembayaran' => $amount,
            'metode_pembayaran' => $payment['metode_pembayaran'] ?? 'cash',
            'no_referensi' => $payment['no_referensi'] ?? null,
            'bank_nama' => $payment['bank_nama'] ?? null,
            'bank_rekening' => $payment['bank_rekening'] ?? null,
            'status' => 'completed',
            'completed_at' => SettingService::now(),
            'completed_by' => Auth::id(),
        ]);
        $document->details()->create([
            'piutang_id' => $piutang->id,
            'nominal_dibayar' => $amount,
            'sumber' => 'cash',
        ]);
        $piutang->recordPayment($amount);

        return $document;
    }
}
