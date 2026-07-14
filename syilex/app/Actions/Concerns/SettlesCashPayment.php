<?php

namespace App\Actions\Concerns;

use App\Actions\PembayaranHutang\CompletePembayaranHutangAction;
use App\Actions\PembayaranHutang\CreatePembayaranHutangAction;
use App\Models\SupplierHutang;

/**
 * Opsi "Cash / Lunas langsung" pada PO & Pembelian Serial.
 *
 * Dipanggil di dalam transaksi approve SETELAH hutang dibuat: bila dokumen ditandai
 * cash_payment, otomatis buat Pembayaran Hutang penuh (sumber cash) lalu di-complete →
 * hutang langsung LUNAS. Info pembayaran (metode/referensi/bank) diambil dari kolom cash_*.
 */
trait SettlesCashPayment
{
    /**
     * @param  \Illuminate\Database\Eloquent\Model  $doc     Dokumen PO/Intake (punya kolom cash_*).
     * @param  SupplierHutang                       $hutang  Hutang yang baru dibuat (belum terbayar).
     */
    protected function settleCashPayment($doc, SupplierHutang $hutang): void
    {
        if (!$doc->cash_payment) {
            return;
        }

        $sisa = (float) $hutang->sisa_hutang;
        if ($sisa <= 0) {
            return;
        }

        $payment = (new CreatePembayaranHutangAction())->execute([
            'tanggal' => $hutang->tanggal,
            'supplier_id' => $hutang->supplier_id,
            'metode_pembayaran' => $doc->cash_metode ?: 'cash',
            'no_referensi' => $doc->cash_no_referensi,
            'bank_nama' => $doc->cash_bank_nama,
            'bank_rekening' => $doc->cash_bank_rekening,
            'details' => [[
                'hutang_id' => $hutang->id,
                'nominal_dibayar' => $sisa,
                'sumber' => 'cash',
            ]],
        ]);

        (new CompletePembayaranHutangAction())->execute($payment);
    }
}
