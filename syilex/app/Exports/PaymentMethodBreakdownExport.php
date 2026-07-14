<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class PaymentMethodBreakdownExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
        protected ?int $terminalId = null,
    ) {
        $rows = DB::table('doc_sales_payments as p')
            ->join('doc_sales as s', 's.id', '=', 'p.sales_id')
            ->join('master_metode_pembayaran as m', 'm.id', '=', 'p.metode_pembayaran_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('s.terminal_id', $terminalId))
            ->select(
                'm.kode_pembayaran',
                'm.nama_pembayaran',
                'm.metode',
                'm.jenis',
                DB::raw('COUNT(DISTINCT p.sales_id) as trx_count'),
                DB::raw('COALESCE(SUM(p.nominal), 0) as nominal_total'),
                DB::raw('COALESCE(SUM(p.biaya_tambahan), 0) as biaya_total')
            )
            ->groupBy('m.id', 'm.kode_pembayaran', 'm.nama_pembayaran', 'm.metode', 'm.jenis')
            ->orderByDesc(DB::raw('SUM(p.nominal)'))
            ->get();

        $grandTotal = (float) $rows->sum('nominal_total');

        $this->rows = $rows->map(fn ($r) => (object) [
            'kode_pembayaran' => $r->kode_pembayaran,
            'nama_pembayaran' => $r->nama_pembayaran,
            'metode' => $r->metode,
            'jenis' => $r->jenis,
            'trx_count' => (int) $r->trx_count,
            'nominal_total' => (float) $r->nominal_total,
            'biaya_total' => (float) $r->biaya_total,
            'percent' => $grandTotal > 0 ? round(((float) $r->nominal_total / $grandTotal) * 100, 2) : 0,
        ]);
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['No', 'Kode', 'Metode Pembayaran', 'Tipe', 'Jenis', 'Trx', 'Nominal', 'Biaya', '%'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->kode_pembayaran,
            $row->nama_pembayaran,
            $row->metode,
            $row->jenis,
            $row->trx_count,
            $row->nominal_total,
            $row->biaya_total,
            $row->percent,
        ];
    }
}
