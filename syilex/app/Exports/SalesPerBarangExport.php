<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class SalesPerBarangExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    private const NETT_EXPR = 'dsd.jumlah * CASE WHEN ds.subtotal > 0 THEN ds.total_setelah_diskon / ds.subtotal ELSE 1 END';

    protected string $dateFrom;
    protected string $dateTo;
    protected bool $canViewHpp;
    protected ?int $terminalId;
    protected ?int $brandId;
    protected ?int $kategoriId;
    protected ?string $search;
    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        bool $canViewHpp,
        ?int $terminalId = null,
        ?int $brandId = null,
        ?int $kategoriId = null,
        ?string $search = null
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo . ' 23:59:59';
        $this->canViewHpp = $canViewHpp;
        $this->terminalId = $terminalId;
        $this->brandId = $brandId;
        $this->kategoriId = $kategoriId;
        $this->search = $search;
    }

    public function query()
    {
        $nett = self::NETT_EXPR;

        // Retur subquery
        $returAgg = DB::table('doc_sales_return_detail as dsrd')
            ->join('doc_sales_returns as dsr', 'dsr.id', '=', 'dsrd.return_id')
            ->join('doc_sales as ds2', 'ds2.id', '=', 'dsr.sales_id')
            ->where('ds2.status', 'completed')
            ->where('ds2.tanggal', '>=', $this->dateFrom)
            ->where('ds2.tanggal', '<=', $this->dateTo);

        if ($this->terminalId) {
            $returAgg->where('ds2.terminal_id', $this->terminalId);
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
