<?php

namespace App\Exports;

use App\Models\DocPromo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use App\Exports\Concerns\UsesExportSheetStyles;

class PromoUsageExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
        protected ?int $terminalId = null,
        protected bool $includeUnused = false,
        protected string $sort = 'diskon_desc',
    ) {
        $from = $dateFrom;
        $to = $dateTo.' 23:59:59';

        $base = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->where('s.status', 'completed')
            ->whereNotNull('d.promo_id')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to]);

        if ($terminalId) {
            $base->where('s.terminal_id', $terminalId);
        }

        $used = (clone $base)
            ->select(
                'd.promo_id',
                DB::raw('COUNT(DISTINCT d.sales_id) as trx_count'),
                DB::raw('COALESCE(SUM(d.qty_base), 0) as qty_total'),
                DB::raw('COALESCE(SUM(d.diskon_total), 0) as diskon_total'),
                DB::raw('COALESCE(SUM(d.jumlah), 0) as revenue_net')
            )
            ->groupBy('d.promo_id')
            ->get();

        $promoIds = $used->pluck('promo_id')->filter()->all();
        $promos = DocPromo::whereIn('id', $promoIds)
            ->select('id', 'ulid', 'kode_promo', 'nama_promo', 'tanggal_mulai', 'tanggal_selesai', 'status')
            ->get()
            ->keyBy('id');

        $rows = $used->map(function ($u) use ($promos) {
            $promo = $promos->get($u->promo_id);

            return (object) [
                'kode_promo' => $promo?->kode_promo ?? '-',
                'nama_promo' => $promo?->nama_promo ?? '-',
                'tanggal_mulai' => $promo?->tanggal_mulai,
                'tanggal_selesai' => $promo?->tanggal_selesai,
                'status' => $promo?->status,
                'trx_count' => (int) $u->trx_count,
                'qty_total' => (float) $u->qty_total,
                'diskon_total' => (float) $u->diskon_total,
                'revenue_net' => (float) $u->revenue_net,
            ];
        });

        $rows = match ($sort) {
            'diskon_asc' => $rows->sortBy('diskon_total'),
            'trx_desc' => $rows->sortByDesc('trx_count'),
            'revenue_desc' => $rows->sortByDesc('revenue_net'),
            default => $rows->sortByDesc('diskon_total'),
        };

        if ($includeUnused) {
            $unusedPromos = DocPromo::where('status', 'approved')
                ->whereNotIn('id', $promoIds)
                ->select('id', 'kode_promo', 'nama_promo', 'tanggal_mulai', 'tanggal_selesai', 'status')
                ->get()
                ->map(fn ($p) => (object) [
                    'kode_promo' => $p->kode_promo,
                    'nama_promo' => $p->nama_promo,
                    'tanggal_mulai' => $p->tanggal_mulai,
                    'tanggal_selesai' => $p->tanggal_selesai,
                    'status' => $p->status,
                    'trx_count' => 0,
                    'qty_total' => 0.0,
                    'diskon_total' => 0.0,
                    'revenue_net' => 0.0,
                ]);

            $rows = $rows->concat($unusedPromos);
        }

        $this->rows = $rows->values();
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['No', 'Kode Promo', 'Nama Promo', 'Mulai', 'Selesai', 'Status', 'Trx', 'Qty', 'Diskon', 'Revenue'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->kode_promo,
            $row->nama_promo,
            $row->tanggal_mulai,
            $row->tanggal_selesai,
            $row->status,
            $row->trx_count,
            $row->qty_total,
            $row->diskon_total,
            $row->revenue_net,
        ];
    }

}
