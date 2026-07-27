<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Services\ReportHelperService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class KasirPerformanceExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
        protected ?int $terminalId = null,
        protected string $mode = 'bruto',
    ) {
        $from = $this->dateFrom;
        $to = $this->dateTo;

        $salesAgg = DB::table('doc_sales as s')
            ->join('users as u', 'u.id', '=', 's.created_by')
            ->where('s.source', 'pos')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($this->terminalId, fn ($q) => $q->where('s.terminal_id', $this->terminalId))
            ->select(
                'u.id as user_id',
                'u.name as user_name',
                DB::raw("SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as trx_completed"),
                DB::raw("SUM(CASE WHEN s.status = 'voided' THEN 1 ELSE 0 END) as trx_voided"),
                DB::raw("COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.grand_total ELSE 0 END), 0) as omzet"),
                DB::raw("COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.total_diskon ELSE 0 END), 0) as diskon_nota_total")
            )
            ->groupBy('u.id', 'u.name')
            ->get()
            ->keyBy('user_id');

        $lineDisc = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->where('s.source', 'pos')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->where('s.status', 'completed')
            ->when($this->terminalId, fn ($q) => $q->where('s.terminal_id', $this->terminalId))
            ->select(
                's.created_by as user_id',
                DB::raw('COALESCE(SUM(d.diskon_total), 0) as diskon_line_total')
            )
            ->groupBy('s.created_by')
            ->get()
            ->keyBy('user_id');

        $returAgg = DB::table('doc_sales_returns as r')
            ->where('r.source', 'pos')
            ->where('r.status', 'approved')
            ->whereBetween('r.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($this->terminalId, fn ($q) => $q->where('r.terminal_id', $this->terminalId))
            ->select(
                'r.created_by as user_id',
                DB::raw('COUNT(*) as retur_count'),
                DB::raw('COALESCE(SUM(r.grand_total), 0) as retur_nominal')
            )
            ->groupBy('r.created_by')
            ->get()
            ->keyBy('user_id');

        // B1.3: retur (lock|approved) untuk omzet net.
        $returNetAgg = ReportHelperService::salesReturnMoneyByKasirSubquery($from.' 00:00:00', $to.' 23:59:59', $this->terminalId)
            ->get()
            ->keyBy('user_id');

        $shiftAgg = DB::table('pos_terminal_shifts as sh')
            ->whereBetween('sh.started_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($this->terminalId, fn ($q) => $q->where('sh.terminal_id', $this->terminalId))
            ->select(
                'sh.user_id',
                DB::raw('COUNT(*) as shift_total')
            )
            ->groupBy('sh.user_id')
            ->get()
            ->keyBy('user_id');

        $allUserIds = $salesAgg->keys()
            ->merge($returAgg->keys())
            ->merge($returNetAgg->keys())
            ->merge($shiftAgg->keys())
            ->unique();

        $mode = $this->mode;

        $this->rows = $allUserIds->map(function ($userId) use ($salesAgg, $lineDisc, $returAgg, $returNetAgg, $shiftAgg, $mode) {
            $sale = $salesAgg->get($userId);
            $line = $lineDisc->get($userId);
            $retur = $returAgg->get($userId);
            $shift = $shiftAgg->get($userId);

            $omzetBruto = (float) ($sale->omzet ?? 0);
            $omzet = $mode === 'net'
                ? max(0, $omzetBruto - (float) ($returNetAgg->get($userId)->ret_money ?? 0))
                : $omzetBruto;

            return (object) [
                'user_name' => $sale->user_name ?? (DB::table('users')->where('id', $userId)->value('name') ?: '-'),
                'trx_completed' => (int) ($sale->trx_completed ?? 0),
                'trx_voided' => (int) ($sale->trx_voided ?? 0),
                'omzet' => $omzet,
                'diskon_total' => (float) ($sale->diskon_nota_total ?? 0) + (float) ($line->diskon_line_total ?? 0),
                'retur_count' => (int) ($retur->retur_count ?? 0),
                'retur_nominal' => (float) ($retur->retur_nominal ?? 0),
                'shift_total' => (int) ($shift->shift_total ?? 0),
            ];
        })->sortByDesc('omzet')->values();
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['No', 'Kasir', 'Trx Selesai', 'Trx Void', 'Omzet', 'Diskon Total', 'Retur', 'Nominal Retur', 'Shift'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->user_name,
            $row->trx_completed,
            $row->trx_voided,
            $row->omzet,
            $row->diskon_total,
            $row->retur_count,
            $row->retur_nominal,
            $row->shift_total,
        ];
    }
}
