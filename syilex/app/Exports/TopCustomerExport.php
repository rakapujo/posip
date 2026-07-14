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

class TopCustomerExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
        protected int $limit = 50,
        protected string $sort = 'omzet_desc',
    ) {
        $from = $dateFrom;
        $to = $dateTo;

        $query = DB::table('doc_sales as s')
            ->join('master_customer as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('master_tipe_customer as t', 't.id', '=', 'c.tipe_customer_id')
            ->leftJoin('master_kategori_customer as k', 'k.id', '=', 'c.kategori_customer_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->select(
                'c.id as customer_id',
                'c.kode_customer',
                'c.nama as customer_nama',
                't.nama_tipe',
                'k.nama_kategori',
                DB::raw('COUNT(DISTINCT s.id) as trx_count'),
                DB::raw('COALESCE(SUM(s.grand_total), 0) as omzet'),
                DB::raw('MAX(s.tanggal) as last_trx_at')
            )
            ->groupBy('c.id', 'c.kode_customer', 'c.nama', 't.nama_tipe', 'k.nama_kategori');

        match ($sort) {
            'trx_desc' => $query->orderByDesc(DB::raw('COUNT(DISTINCT s.id)')),
            'avg_desc' => $query->orderByDesc(DB::raw('COALESCE(SUM(s.grand_total), 0) * 1.0 / COUNT(DISTINCT s.id)')),
            'last_desc' => $query->orderByDesc(DB::raw('MAX(s.tanggal)')),
            default => $query->orderByDesc(DB::raw('COALESCE(SUM(s.grand_total), 0)')),
        };

        $results = $query->limit($limit)->get();
        $customerIds = $results->pluck('customer_id')->all();
        $qtyMap = collect();

        if ($customerIds !== []) {
            $qtyMap = DB::table('doc_sales_detail as d')
                ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
                ->whereIn('s.customer_id', $customerIds)
                ->select('s.customer_id', DB::raw('COALESCE(SUM(d.qty_base), 0) as qty_total'))
                ->groupBy('s.customer_id')
                ->get()
                ->keyBy('customer_id');
        }

        $this->rows = $results->map(function ($r, $idx) use ($qtyMap) {
            $trx = (int) $r->trx_count;
            $omzet = (float) $r->omzet;

            return (object) [
                'rank' => $idx + 1,
                'kode_customer' => $r->kode_customer,
                'customer_nama' => $r->customer_nama,
                'tipe' => $r->nama_tipe ?? '-',
                'kategori' => $r->nama_kategori ?? '-',
                'trx_count' => $trx,
                'qty_total' => (float) ($qtyMap->get($r->customer_id)->qty_total ?? 0),
                'omzet' => $omzet,
                'avg_per_trx' => $trx > 0 ? round($omzet / $trx, 2) : 0,
                'last_trx_at' => $r->last_trx_at,
            ];
        })->values();
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Rank', 'Kode', 'Customer', 'Tipe', 'Kategori', 'Trx', 'Qty', 'Omzet', 'Avg/Trx', 'Terakhir Trx'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $row->rank,
            $row->kode_customer,
            $row->customer_nama,
            $row->tipe,
            $row->kategori,
            $row->trx_count,
            $row->qty_total,
            $row->omzet,
            $row->avg_per_trx,
            $row->last_trx_at,
        ];
    }
}
