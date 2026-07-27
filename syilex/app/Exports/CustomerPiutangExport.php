<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Models\CustomerPiutang;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class CustomerPiutangExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use UsesExportSheetStyles;

    private int $row = 0;

    public function __construct(private bool $canViewNominal, private array $filters = []) {}

    public function query()
    {
        $query = CustomerPiutang::with([
            'customer:id,kode_customer,nama',
            'sales:id,nomor_dokumen,tanggal',
        ]);
        if ($search = ($this->filters['search'] ?? null)) {
            $query->search($search);
        }
        if ($customer = ($this->filters['customer_id'] ?? null)) {
            $query->byCustomer((int) $customer);
        }
        if ($status = ($this->filters['status'] ?? null)) {
            $status === 'outstanding' ? $query->outstanding() : $query->where('status', $status);
        }
        if ($due = ($this->filters['due_within_days'] ?? null)) {
            $due === 'all' ? $query->notOverdue() : $query->dueWithinDays((int) $due);
        }
        if ($overdue = ($this->filters['overdue_within_days'] ?? null)) {
            $overdue === 'all' ? $query->overdue() : $query->overdueWithinDays((int) $overdue);
        }
        if ($bucket = ($this->filters['aging_bucket'] ?? null)) {
            $allowed = ['belum_tempo', 'b1_30', 'b31_60', 'b61_90', 'above_90'];
            if (in_array($bucket, $allowed, true)) {
                $query->agingBucket($bucket);
            }
        }
        if (($this->filters['date_from'] ?? null) || ($this->filters['date_to'] ?? null)) {
            $query->byDateRange($this->filters['date_from'] ?? null, $this->filters['date_to'] ?? null);
        }

        return $query->orderByDesc('tanggal');
    }

    public function headings(): array
    {
        $headings = ['No', 'No. Dokumen', 'Tanggal', 'Customer', 'Kode Customer'];
        if ($this->canViewNominal) {
            array_push($headings, 'Nominal Awal', 'Terbayar', 'Sisa Piutang');
        }
        array_push($headings, 'Jatuh Tempo', 'Status');

        return $headings;
    }

    public function map($item): array
    {
        $row = [++$this->row, $item->sales?->nomor_dokumen ?? '-', $item->tanggal,
            $item->customer?->nama ?? '-', $item->customer?->kode_customer ?? '-'];
        if ($this->canViewNominal) {
            array_push($row, $item->nominal_awal, $item->nominal_terbayar, $item->sisa_piutang);
        }
        array_push($row, $item->tanggal_jatuh_tempo ?? '-', $item->status);

        return $row;
    }
}
