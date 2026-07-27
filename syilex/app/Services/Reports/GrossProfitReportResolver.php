<?php

namespace App\Services\Reports;

use App\Services\ReportHelperService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single query path for Gross Profit — used by both the report controller (index/summary/
 * daily/byKategori/topProducts) and the Excel exports.
 *
 * @phpstan-type Filters array{terminal_id?: ?int, kategori_id?: ?int, warehouse_id?: ?int}
 */
class GrossProfitReportResolver
{
    /**
     * @param  Filters  $filters
     */
    public static function summaryRow(string $from, string $to, array $filters = []): object
    {
        $nett = ReportHelperService::salesLineNettExpr('d', 's');
        $hpp = ReportHelperService::salesLineHppExpr('d');
        $retHpp = ReportHelperService::returnLineHppExpr('rd');

        $salesAgg = self::baseSalesQuery($from, $to, $filters)
            ->select(
                DB::raw("COALESCE(SUM({$nett}), 0) as revenue"),
                DB::raw("COALESCE(SUM({$hpp}), 0) as hpp"),
                DB::raw('COUNT(DISTINCT s.id) as trx_count')
            )
            ->first();

        $returnAgg = self::baseReturnQuery($from, $to, $filters)
            ->select(
                DB::raw('COALESCE(SUM(rd.harga_satuan * rd.qty), 0) as revenue'),
                DB::raw("COALESCE(SUM({$retHpp}), 0) as hpp")
            )
            ->first();

        $revenue = (float) $salesAgg->revenue - (float) $returnAgg->revenue;
        $hppVal = (float) $salesAgg->hpp - (float) $returnAgg->hpp;
        $profit = $revenue - $hppVal;

        return (object) [
            'revenue_gross' => (float) $salesAgg->revenue,
            'revenue_return' => (float) $returnAgg->revenue,
            'revenue_net' => $revenue,
            'hpp_gross' => (float) $salesAgg->hpp,
            'hpp_return' => (float) $returnAgg->hpp,
            'hpp_net' => $hppVal,
            'gross_profit' => $profit,
            'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            'trx_count' => (int) $salesAgg->trx_count,
        ];
    }

    /**
     * @param  Filters  $filters
     * @return Collection<int, object>
     */
    public static function dailyRows(string $from, string $to, array $filters = []): Collection
    {
        $nett = ReportHelperService::salesLineNettExpr('d', 's');
        $hpp = ReportHelperService::salesLineHppExpr('d');
        $retHpp = ReportHelperService::returnLineHppExpr('rd');

        $sales = self::baseSalesQuery($from, $to, $filters)
            ->select(
                DB::raw('DATE(s.tanggal) as tanggal'),
                DB::raw("SUM({$nett}) as revenue"),
                DB::raw("SUM({$hpp}) as hpp"),
                DB::raw('COUNT(DISTINCT s.id) as trx_count')
            )
            ->groupBy(DB::raw('DATE(s.tanggal)'))
            ->orderBy('tanggal')
            ->get();

        $returns = self::baseReturnQuery($from, $to, $filters)
            ->select(
                DB::raw('DATE(r.tanggal) as tanggal'),
                DB::raw('SUM(rd.harga_satuan * rd.qty) as revenue'),
                DB::raw("SUM({$retHpp}) as hpp")
            )
            ->groupBy(DB::raw('DATE(r.tanggal)'))
            ->get()
            ->keyBy('tanggal');

        return $sales->map(function ($row) use ($returns) {
            $ret = $returns->get($row->tanggal);
            $revenue = (float) $row->revenue - (float) ($ret->revenue ?? 0);
            $hppVal = (float) $row->hpp - (float) ($ret->hpp ?? 0);
            $profit = $revenue - $hppVal;

            return (object) [
                'tanggal' => $row->tanggal,
                'revenue' => $revenue,
                'hpp' => $hppVal,
                'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
                'trx_count' => (int) $row->trx_count,
            ];
        });
    }

    /**
     * @param  Filters  $filters
     * @return Collection<int, object>
     */
    public static function byKategoriRows(string $from, string $to, array $filters = []): Collection
    {
        $nett = ReportHelperService::salesLineNettExpr('d', 's');
        $hpp = ReportHelperService::salesLineHppExpr('d');
        $retHpp = ReportHelperService::returnLineHppExpr('rd');

        $sales = self::baseSalesQuery($from, $to, $filters)
            ->leftJoin('master_produk as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->select(
                'k.id as kategori_id',
                'k.nama_kategori',
                DB::raw("SUM({$nett}) as revenue"),
                DB::raw("SUM({$hpp}) as hpp")
            )
            ->groupBy('k.id', 'k.nama_kategori')
            ->orderByDesc(DB::raw("SUM({$nett}) - SUM({$hpp})"))
            ->get();

        $returns = self::baseReturnQuery($from, $to, $filters)
            ->leftJoin('master_produk as p', 'p.id', '=', 'rd.product_id')
            ->select(
                'p.kategori_id',
                DB::raw('SUM(rd.harga_satuan * rd.qty) as revenue'),
                DB::raw("SUM({$retHpp}) as hpp")
            )
            ->groupBy('p.kategori_id')
            ->get()
            ->keyBy('kategori_id');

        return $sales->map(function ($row) use ($returns) {
            $ret = $returns->get($row->kategori_id);
            $revenue = (float) $row->revenue - (float) ($ret->revenue ?? 0);
            $hppVal = (float) $row->hpp - (float) ($ret->hpp ?? 0);
            $profit = $revenue - $hppVal;

            return (object) [
                'kategori_id' => $row->kategori_id,
                'nama_kategori' => $row->nama_kategori ?? '(Tanpa Kategori)',
                'revenue' => $revenue,
                'hpp' => $hppVal,
                'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            ];
        });
    }

    /**
     * @param  Filters  $filters
     * @return Collection<int, object>
     */
    public static function topProductRows(string $from, string $to, int $limit = 10, array $filters = []): Collection
    {
        $nett = ReportHelperService::salesLineNettExpr('d', 's');
        $hpp = ReportHelperService::salesLineHppExpr('d');
        $retHpp = ReportHelperService::returnLineHppExpr('rd');

        $sales = self::baseSalesQuery($from, $to, $filters)
            ->join('master_produk as p', 'p.id', '=', 'd.product_id')
            ->select(
                'p.id as product_id',
                'p.ulid as product_ulid',
                'p.kode_produk',
                'p.nama_produk',
                DB::raw('SUM(d.qty_base) as qty'),
                DB::raw("SUM({$nett}) as revenue"),
                DB::raw("SUM({$hpp}) as hpp")
            )
            ->groupBy('p.id', 'p.ulid', 'p.kode_produk', 'p.nama_produk')
            ->get()
            ->keyBy('product_id');

        $returns = self::baseReturnQuery($from, $to, $filters)
            ->join('master_produk as p', 'p.id', '=', 'rd.product_id')
            ->select(
                'rd.product_id',
                'p.ulid as product_ulid',
                'p.kode_produk',
                'p.nama_produk',
                DB::raw('SUM(rd.qty_base) as qty'),
                DB::raw('SUM(rd.harga_satuan * rd.qty) as revenue'),
                DB::raw("SUM({$retHpp}) as hpp")
            )
            ->groupBy('rd.product_id', 'p.ulid', 'p.kode_produk', 'p.nama_produk')
            ->get()
            ->keyBy('product_id');

        $rows = $sales->keys()
            ->merge($returns->keys())
            ->unique()
            ->map(function ($productId) use ($sales, $returns) {
                $sale = $sales->get($productId);
                $ret = $returns->get($productId);
                $revenue = (float) ($sale->revenue ?? 0) - (float) ($ret->revenue ?? 0);
                $hppVal = (float) ($sale->hpp ?? 0) - (float) ($ret->hpp ?? 0);
                $qty = (float) ($sale->qty ?? 0) - (float) ($ret->qty ?? 0);
                $profit = $revenue - $hppVal;
                $src = $sale ?? $ret;

                return (object) [
                    'product_id' => $productId,
                    'product_ulid' => $src->product_ulid,
                    'kode_produk' => $src->kode_produk,
                    'nama_produk' => $src->nama_produk,
                    'qty' => $qty,
                    'revenue' => $revenue,
                    'hpp' => $hppVal,
                    'profit' => $profit,
                    'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
                ];
            })
            ->sortByDesc('profit')
            ->values()
            ->take($limit)
            ->values();

        return $rows;
    }

    /**
     * @param  Filters  $filters
     */
    private static function baseSalesQuery(string $from, string $to, array $filters)
    {
        $q = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59']);

        if (! empty($filters['terminal_id'])) {
            $q->where('s.terminal_id', $filters['terminal_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $q->where('s.warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['kategori_id'])) {
            $q->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('master_produk as mp')
                    ->whereColumn('mp.id', 'd.product_id')
                    ->where('mp.kategori_id', $filters['kategori_id']);
            });
        }

        return $q;
    }

    /**
     * @param  Filters  $filters
     */
    private static function baseReturnQuery(string $from, string $to, array $filters)
    {
        $q = DB::table('doc_sales_return_detail as rd')
            ->join('doc_sales_returns as r', 'r.id', '=', 'rd.return_id')
            ->whereIn('r.status', ['lock', 'approved'])
            ->whereBetween('r.tanggal', [$from.' 00:00:00', $to.' 23:59:59']);

        if (! empty($filters['terminal_id'])) {
            $q->where('r.terminal_id', $filters['terminal_id']);
        }
        if (! empty($filters['warehouse_id'])) {
            $q->where('r.warehouse_id', $filters['warehouse_id']);
        }
        if (! empty($filters['kategori_id'])) {
            $q->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('master_produk as mp')
                    ->whereColumn('mp.id', 'rd.product_id')
                    ->where('mp.kategori_id', $filters['kategori_id']);
            });
        }

        return $q;
    }
}
