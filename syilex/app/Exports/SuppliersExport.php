<?php

namespace App\Exports;

use App\Models\MasterSupplier;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class SuppliersExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected ?string $search;
    protected ?string $status;
    protected int $rowNumber = 0;

    public function __construct(?string $search = null, ?string $status = null)
    {
        $this->search = $search;
        $this->status = $status;
    }

    public function query()
    {
        $query = MasterSupplier::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('kode_supplier', 'like', "%{$this->search}%")
                  ->orWhere('nama_supplier', 'like', "%{$this->search}%")
                  ->orWhere('nama_pic', 'like', "%{$this->search}%")
                  ->orWhere('telepon', 'like', "%{$this->search}%");
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        return $query->orderBy('kode_supplier', 'asc');
    }

    public function headings(): array
    {
        return [
            'No', 'Kode Supplier', 'Nama Supplier', 'PIC', 'Telepon',
            'Email', 'Alamat', 'NPWP', 'Bank', 'No. Rekening',
            'Atas Nama', 'Tempo (Hari)', 'Status',
        ];
    }

    public function map($supplier): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $supplier->kode_supplier,
            $supplier->nama_supplier,
            $supplier->nama_pic ?? '-',
            $supplier->telepon ?? '-',
            $supplier->email ?? '-',
            $supplier->alamat ?? '-',
            $supplier->npwp ?? '-',
            $supplier->bank_nama ?? '-',
            $supplier->bank_rekening ?? '-',
            $supplier->bank_atas_nama ?? '-',
            $supplier->tempo_default ?? 0,
            $supplier->status === 'active' ? 'Aktif' : 'Nonaktif',
        ];
    }

}
