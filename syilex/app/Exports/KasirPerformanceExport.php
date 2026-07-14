<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use App\Exports\Concerns\UsesExportSheetStyles;

class KasirPerformanceExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
        protected ?int $terminalId = null,
    ) {
    }

    public function query()
    {
        $from = $this->dateFrom;
        $to = $this->dateTo.' 23:59:59';

        return DB::table('doc_sales as s')
            ->join('users as u', 'u.id', '=', 's.created_by')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to])
            ->when($this->terminalId, fn ($q) => $q->where('s.terminal_id', $this->terminalId))
            ->select(
                'u.name as user_name',
                DB::raw("SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as trx_completed"),
                DB::raw("SUM(CASE WHEN s.status = 'voided' THEN 1 ELSE 0 END) as trx_voided"),
                DB::raw("COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.grand_total ELSE 0 END), 0) as omzet")
            )
            ->groupBy('u.id', 'u.name')
            ->orderByDesc('omzet');
    }

    public function headings(): array
    {
        return ['No', 'Kasir', 'Trx Selesai', 'Trx Void', 'Omzet'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->user_name,
            (int) $row->trx_completed,
            (int) $row->trx_voided,
            (float) $row->omzet,
        ];
    }

}
