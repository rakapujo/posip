<?php

namespace App\Exports;

use App\Models\MasterMetodePembayaran;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class MetodePembayaransExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected ?string $search;
    protected ?string $status;
    protected ?string $metode;
    protected int $rowNumber = 0;

    public function __construct(?string $search = null, ?string $status = null, ?string $metode = null)
    {
        $this->search = $search;
        $this->status = $status;
        $this->metode = $metode;
    }

    public function query()
    {
        $query = MasterMetodePembayaran::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('kode_pembayaran', 'like', "%{$this->search}%")
                  ->orWhere('nama_pembayaran', 'like', "%{$this->search}%")
                  ->orWhere('nama_akun', 'like', "%{$this->search}%");
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->metode) {
            $query->where('metode', $this->metode);
        }

        return $query->orderBy('kode_pembayaran', 'asc');
    }

    public function headings(): array
    {
        return [
            'No', 'Kode Pembayaran', 'Nama Pembayaran', 'Metode', 'Jenis',
            'Nama Akun', 'Nomor Akun', 'Biaya Tambahan Tipe', 'Biaya Tambahan Nilai', 'Status',
        ];
    }

    public function map($metode): array
    {
        $this->rowNumber++;

        $metodeLabel = $metode->metode === 'tunai' ? 'Tunai' : 'Non-Tunai';

        $jenisLabels = [
            'cash' => 'Cash',
            'bank_transfer' => 'Bank Transfer',
            'e_wallet' => 'E-Wallet',
            'qris' => 'QRIS',
            'debit_card' => 'Kartu Debit',
            'credit_card' => 'Kartu Kredit',
        ];
        $jenisLabel = $jenisLabels[$metode->jenis] ?? $metode->jenis ?? '-';

        $biayaTipe = $metode->biaya_tambahan_tipe;
        $biayaTipeLabel = '-';
        if ($biayaTipe === 'nominal') {
            $biayaTipeLabel = 'Nominal';
        } elseif ($biayaTipe === 'persen') {
            $biayaTipeLabel = 'Persen';
        }

        return [
            $this->rowNumber,
            $metode->kode_pembayaran,
            $metode->nama_pembayaran,
            $metodeLabel,
            $jenisLabel,
            $metode->nama_akun ?? '-',
            $metode->nomor_akun ?? '-',
            $biayaTipeLabel,
            $metode->biaya_tambahan_nilai ?? 0,
            $metode->status === 'active' ? 'Aktif' : 'Nonaktif',
        ];
    }

}
