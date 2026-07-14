<?php

namespace App\Exports;

use App\Models\SupplierDeposit;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;

class SupplierDepositExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected ?int $supplierId;
    protected ?string $status;
    protected bool $hasBalanceOnly;
    protected ?string $dateFrom;
    protected ?string $dateTo;
    protected ?string $search;
    protected int $rowNumber = 0;

    public function __construct(
        ?int $supplierId = null,
        ?string $status = null,
        bool $hasBalanceOnly = false,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $search = null
    ) {
        $this->supplierId = $supplierId;
        $this->status = $status;
        $this->hasBalanceOnly = $hasBalanceOnly;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->search = $search;
    }

    public function query()
    {
        $query = SupplierDeposit::with([
            'supplier:id,kode_supplier,nama_supplier',
            'purchaseReturn:id,nomor_dokumen',
        ]);

        if ($this->search) {
            $query->search($this->search);
        }

        if ($this->supplierId) {
            $query->bySupplier($this->supplierId);
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        if ($this->hasBalanceOnly) {
            $query->hasBalance();
        }

        $query->byDateRange($this->dateFrom, $this->dateTo);

        return $query->orderBy('tanggal', 'desc');
    }

    public function headings(): array
    {
        return [
            'No', 'Supplier', 'Kode Supplier', 'Sumber', 'No. Referensi',
            'Tanggal', 'Nominal Awal', 'Terpakai', 'Sisa Deposit', 'Status',
        ];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $sumber = $row->purchaseReturn ? 'Retur - ' . $row->purchaseReturn->nomor_dokumen : 'Manual';

        $statusLabel = match ($row->status) {
            'available' => 'Available',
            'used_partial' => 'Sebagian',
            'used_all' => 'Habis',
            default => $row->status,
        };

        return [
            $this->rowNumber,
            $row->supplier?->nama_supplier ?? '-',
            $row->supplier?->kode_supplier ?? '-',
            $sumber,
            $row->no_referensi ?? '-',
            $row->tanggal,
            $row->nominal_awal,
            $row->nominal_terpakai,
            $row->sisa_deposit,
            $statusLabel,
        ];
    }
}
