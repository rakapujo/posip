<?php

namespace App\Exports;

use App\Models\SupplierHutang;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class SupplierHutangExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected bool $canViewNominal;
    protected ?int $supplierId;
    protected ?string $status;
    protected mixed $dueWithinDays;
    protected mixed $overdueWithinDays;
    protected ?string $dateFrom;
    protected ?string $dateTo;
    protected ?string $search;
    protected int $rowNumber = 0;

    public function __construct(
        bool $canViewNominal,
        ?int $supplierId = null,
        ?string $status = null,
        mixed $dueWithinDays = null,
        mixed $overdueWithinDays = null,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $search = null
    ) {
        $this->canViewNominal = $canViewNominal;
        $this->supplierId = $supplierId;
        $this->status = $status;
        $this->dueWithinDays = $dueWithinDays;
        $this->overdueWithinDays = $overdueWithinDays;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->search = $search;
    }

    public function query()
    {
        $query = SupplierHutang::with([
            'supplier:id,kode_supplier,nama_supplier',
            'purchaseOrder:id,nomor_dokumen,tanggal_po',
            'serialIntake:id,nomor_dokumen,tanggal',
        ]);

        if ($this->search) {
            $query->search($this->search);
        }

        if ($this->supplierId) {
            $query->bySupplier($this->supplierId);
        }

        if ($this->status) {
            if ($this->status === 'outstanding') {
                $query->outstanding();
            } else {
                $query->where('status', $this->status);
            }
        }

        if ($this->dueWithinDays !== null) {
            if ($this->dueWithinDays === 'all') {
                $query->notOverdue();
            } else {
                $query->dueWithinDays((int) $this->dueWithinDays);
            }
        }

        if ($this->overdueWithinDays !== null) {
            if ($this->overdueWithinDays === 'all') {
                $query->overdue();
            } else {
                $query->overdueWithinDays((int) $this->overdueWithinDays);
            }
        }

        if ($this->dateFrom || $this->dateTo) {
            $query->byDateRange($this->dateFrom, $this->dateTo);
        }

        return $query->orderBy('tanggal', 'desc');
    }

    public function headings(): array
    {
        $headings = ['No', 'No. Dokumen', 'Sumber', 'Tanggal', 'Supplier', 'Kode Supplier'];

        if ($this->canViewNominal) {
            $headings = array_merge($headings, ['Nominal Awal', 'Terbayar', 'Sisa Hutang']);
        }

        $headings = array_merge($headings, ['Jatuh Tempo', 'Status']);

        return $headings;
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $statusLabel = match ($row->status) {
            'unpaid' => 'Belum Bayar',
            'partial' => 'Sebagian',
            'paid' => 'Lunas',
            default => $row->status,
        };

        $mapped = [
            $this->rowNumber,
            $row->purchaseOrder?->nomor_dokumen ?? $row->serialIntake?->nomor_dokumen ?? '-',
            $row->purchaseOrder ? 'PO' : ($row->serialIntake ? 'Serial' : '-'),
            $row->tanggal,
            $row->supplier?->nama_supplier ?? '-',
            $row->supplier?->kode_supplier ?? '-',
        ];

        if ($this->canViewNominal) {
            $mapped = array_merge($mapped, [
                $row->nominal_awal,
                $row->nominal_terbayar,
                $row->sisa_hutang,
            ]);
        }

        $mapped = array_merge($mapped, [
            $row->tanggal_jatuh_tempo ?? '-',
            $statusLabel,
        ]);

        return $mapped;
    }
}
