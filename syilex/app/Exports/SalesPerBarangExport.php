<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Services\ReportHelperService;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class SalesPerBarangExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use UsesExportSheetStyles;

    protected string $dateFrom;

    protected string $dateTo;

    protected bool $canViewHpp;

    protected ?int $terminalId;

    protected ?int $brandId;

    protected ?int $kategoriId;

    protected ?string $search;

    protected ?int $warehouseId;

    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        bool $canViewHpp,
        ?int $terminalId = null,
        ?int $brandId = null,
        ?int $kategoriId = null,
        ?string $search = null,
        ?int $warehouseId = null
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo.' 23:59:59';
        $this->canViewHpp = $canViewHpp;
        $this->terminalId = $terminalId;
        $this->brandId = $brandId;
        $this->kategoriId = $kategoriId;
        $this->search = $search;
        $this->warehouseId = $warehouseId;
    }

    public function query()
    {
        $nett = ReportHelperService::salesLineNettExpr('dsd', 'ds');

        // Retur subquery (linked + free)
        $returAgg = DB::table('doc_sales_return_detail as dsrd')
            ->join('doc_sales_returns as dsr', 'dsr.id', '=', 'dsrd.return_id')
            ->leftJoin('doc_sales as ds2', 'ds2.id', '=', 'dsr.sales_id')
            ->whereIn('dsr.status', ['lock', 'approved'])
            ->where(function ($q) {
                $q->where(function ($linked) {
                    $linked->whereNotNull('dsr.sales_id')
                        ->where('ds2.status', 'completed')
                        ->where('ds2.tanggal', '>=', $this->dateFrom)
                        ->where('ds2.tanggal', '<=', $this->dateTo);
                })->orWhere(function ($free) {
                    $free->whereNull('dsr.sales_id')
                        ->where('dsr.tanggal', '>=', $this->dateFrom)
                        ->where('dsr.tanggal', '<=', $this->dateTo);
                });
            });

        if ($this->terminalId) {
            $returAgg->where('ds2.terminal_id', $this->terminalId);
        }
        if ($this->warehouseId) {
            $returAgg->where('dsr.warehouse_id', $this->warehouseId);
        }

        $returAgg->select('dsrd.product_id', DB::raw('SUM(dsrd.qty_base) as qty_retur'))
            ->groupBy('dsrd.product_id');

        $selectColumns = [
            'mp.kode_produk',
            'mp.nama_produk',
            'mb.nama_brand as brand',
            'mk.nama_kategori as kategori',
            DB::raw('SUM(dsd.qty_base) as qty_terjual'),
            DB::raw("SUM({$nett}) as pendapatan"),
            DB::raw('COALESCE(MAX(retur_agg.qty_retur), 0) as qty_retur'),
        ];

        if ($this->canViewHpp) {
            $selectColumns[] = DB::raw('SUM(dsd.qty_base * dsd.hpp_at_time) as hpp_total');
        }

        $query = DB::table('doc_sales_detail as dsd')
            ->join('doc_sales as ds', 'ds.id', '=', 'dsd.sales_id')
            ->join('master_produk as mp', 'mp.id', '=', 'dsd.product_id')
            ->leftJoin('master_brand as mb', 'mb.id', '=', 'mp.brand_id')
            ->leftJoin('master_kategori as mk', 'mk.id', '=', 'mp.kategori_id')
            ->leftJoinSub($returAgg, 'retur_agg', function ($join) {
                $join->on('retur_agg.product_id', '=', 'mp.id');
            })
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $this->dateFrom)
            ->where('ds.tanggal', '<=', $this->dateTo)
            ->select($selectColumns)
            ->groupBy('mp.id', 'mp.kode_produk', 'mp.nama_produk', 'mb.nama_brand', 'mk.nama_kategori');

        if ($this->terminalId) {
            $query->where('ds.terminal_id', $this->terminalId);
        }
        if ($this->warehouseId) {
            $query->where('ds.warehouse_id', $this->warehouseId);
        }
        if ($this->brandId) {
            $query->where('mp.brand_id', $this->brandId);
        }
        if ($this->kategoriId) {
            $query->where('mp.kategori_id', $this->kategoriId);
        }
        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('mp.kode_produk', 'like', "%{$search}%")
                    ->orWhere('mp.nama_produk', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('pendapatan', 'desc');
    }

    public function headings(): array
    {
        $headings = ['No', 'Kode Produk', 'Nama Produk', 'Brand', 'Kategori', 'Qty Terjual', 'Qty Retur', 'Pendapatan'];

        if ($this->canViewHpp) {
            $headings = array_merge($headings, ['HPP Total', 'Laba Kotor', 'Margin (%)']);
        }

        return $headings;
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $mapped = [
            $this->rowNumber,
            $row->kode_produk,
            $row->nama_produk,
            $row->brand ?? '-',
            $row->kategori ?? '-',
            $row->qty_terjual,
            $row->qty_retur,
            round($row->pendapatan, 2),
        ];

        if ($this->canViewHpp) {
            $hppTotal = $row->hpp_total ?? 0;
            $labaKotor = round($row->pendapatan - $hppTotal, 2);
            $margin = $row->pendapatan > 0 ? round(($labaKotor / $row->pendapatan) * 100, 2) : 0;

            $mapped = array_merge($mapped, [
                round($hppTotal, 2),
                $labaKotor,
                $margin,
            ]);
        }

        return $mapped;
    }
}
