<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Models\CustomerDeposit;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class CustomerDepositExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use UsesExportSheetStyles;

    protected ?int $customerId;

    protected ?string $status;

    protected bool $hasBalanceOnly;

    protected ?string $dateFrom;

    protected ?string $dateTo;

    protected ?string $search;

    protected int $rowNumber = 0;

    public function __construct(
        ?int $customerId = null,
        ?string $status = null,
        bool $hasBalanceOnly = false,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $search = null
    ) {
        $this->customerId = $customerId;
        $this->status = $status;
        $this->hasBalanceOnly = $hasBalanceOnly;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->search = $search;
    }

    public function query()
    {
        $query = CustomerDeposit::with([
            'customer:id,kode_customer,nama',
            'salesReturn:id,nomor_dokumen',
        ]);

        if ($this->search) {
            $query->search($this->search);
        }

        if ($this->customerId) {
            $query->byCustomer($this->customerId);
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
            'No', 'Customer', 'Kode Customer', 'Sumber', 'No. Referensi',
            'Tanggal', 'Nominal Awal', 'Terpakai', 'Sisa Deposit', 'Status',
        ];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $sumber = $row->salesReturn ? 'Retur - '.$row->salesReturn->nomor_dokumen : 'Manual';

        $statusLabel = match ($row->status) {
            'available' => 'Available',
            'used_partial' => 'Sebagian',
            'used_all' => 'Habis',
            default => $row->status,
        };

        return [
            $this->rowNumber,
            $row->customer?->nama ?? '-',
            $row->customer?->kode_customer ?? '-',
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
