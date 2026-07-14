<?php

namespace App\Exports;

use App\Models\MasterCustomer;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class CustomersExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected ?string $search;
    protected ?string $status;
    protected ?string $jenis;
    protected ?int $tipeCustomerId;
    protected ?int $kategoriCustomerId;
    protected int $rowNumber = 0;

    public function __construct(
        ?string $search = null,
        ?string $status = null,
        ?string $jenis = null,
        ?int $tipeCustomerId = null,
        ?int $kategoriCustomerId = null
    ) {
        $this->search = $search;
        $this->status = $status;
        $this->jenis = $jenis;
        $this->tipeCustomerId = $tipeCustomerId;
        $this->kategoriCustomerId = $kategoriCustomerId;
    }

    public function query()
    {
        $query = MasterCustomer::with([
            'tipeCustomer:id,kode_tipe,nama_tipe',
            'kategoriCustomer:id,kode_kategori,nama_kategori',
        ]);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('kode_customer', 'like', "%{$this->search}%")
                  ->orWhere('nama', 'like', "%{$this->search}%")
                  ->orWhere('telepon', 'like', "%{$this->search}%")
                  ->orWhere('email', 'like', "%{$this->search}%");
            });
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->jenis) {
            $query->where('jenis', $this->jenis);
        }

        if ($this->tipeCustomerId) {
            $query->where('tipe_customer_id', $this->tipeCustomerId);
        }

        if ($this->kategoriCustomerId) {
            $query->where('kategori_customer_id', $this->kategoriCustomerId);
        }

        return $query->orderBy('kode_customer', 'asc');
    }

    public function headings(): array
    {
        return [
            'No', 'Kode Customer', 'Nama', 'Telepon', 'Email', 'Alamat',
            'NIK', 'NPWP', 'Jenis', 'Kode Tipe', 'Tipe Customer',
            'Kode Kategori', 'Kategori Customer', 'Status',
        ];
    }

    public function map($customer): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $customer->kode_customer,
            $customer->nama,
            $customer->telepon ?? '-',
            $customer->email ?? '-',
            $customer->alamat ?? '-',
            $customer->nik ?? '-',
            $customer->npwp ?? '-',
            $customer->jenis === 'perorangan' ? 'Perorangan' : 'Perusahaan',
            $customer->tipeCustomer?->kode_tipe ?? '-',
            $customer->tipeCustomer?->nama_tipe ?? '-',
            $customer->kategoriCustomer?->kode_kategori ?? '-',
            $customer->kategoriCustomer?->nama_kategori ?? '-',
            $customer->status === 'active' ? 'Aktif' : 'Nonaktif',
        ];
    }

}
