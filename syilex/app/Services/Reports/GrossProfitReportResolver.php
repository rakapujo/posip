<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GrossProfitReportResolver
{
    /**
     * @return Collection<int, object>
     */
    public static function dailyRows(string $from, string $to, ?int $terminalId = null, ?int $kategoriId = null): Collection
    {
        $filters = ['terminal_id' => $terminalId, 'kategori_id' => $kategoriId];

        $sales = self::baseSalesQuery($from, $to, $filters)
            ->select(
                DB::raw('DATE(s.tanggal) as tanggal'),
                DB::raw('SUM(d.jumlah) as revenue'),
                DB::raw('SUM(d.qty * d.hpp_at_time) as hpp'),
                DB::raw('COUNT(DISTINCT s.id) as trx_count')
            )
            ->groupBy(DB::raw('DATE(s.tanggal)'))
            ->orderBy('tanggal')
            ->get();

        $returns = self::baseReturnQuery($from, $to, $filters)
            ->select(
                DB::raw('DATE(r.tanggal) as tanggal'),
                DB::raw('SUM(rd.harga_satuan * rd.qty) as revenue'),
                DB::raw('SUM(rd.qty * rd.hpp_at_time) as hpp')
            )
            ->groupBy(DB::raw('DATE(r.tanggal)'))
            ->get()
            ->keyBy('tanggal');

        return $sales->map(function ($row) use ($returns) {
            $ret = $returns->get($row->tanggal);
            $revenue = (float) $row->revenue - (float) ($ret->revenue ?? 0);
            $hpp = (float) $row->hpp - (float) ($ret->hpp ?? 0);
            $profit = $revenue - $hpp;

            return (object) [
                'tanggal' => $row->tanggal,
                'revenue' => $revenue,
                'hpp' => $hpp,
                'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
                'trx_count' => (int) $row->trx_count,
            ];
        });
    }

    /**
     * @return Collection<int, object>
     */
    public static function byKategoriRows(string $from, string $to, ?int $terminalId = null): Collection
    {
        $filters = ['terminal_id' => $terminalId, 'kategori_id' => null];

        $sales = self::baseSalesQuery($from, $to, $filters)
            ->leftJoin('master_produk as p', 'p.id', '=', 'd.product_id')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->select(
                'k.id as kategori_id',
                'k.nama_kategori',
                DB::raw('SUM(d.jumlah) as revenue'),
                DB::raw('SUM(d.qty * d.hpp_at_time) as hpp')
            )
            ->groupBy('k.id', 'k.nama_kategori')
            ->orderByDesc(DB::raw('SUM(d.jumlah) - SUM(d.qty * d.hpp_at_time)'))
            ->get();

        $returns = self::baseReturnQuery($from, $to, $filters)
            ->leftJoin('master_produk as p', 'p.id', '=', 'rd.product_id')
            ->select(
                'p.kategori_id',
                DB::raw('SUM(rd.harga_satuan * rd.qty) as revenue'),
                DB::raw('SUM(rd.qty * rd.hpp_at_time) as hpp')
            )
            ->groupBy('p.kategori_id')
            ->get()
            ->keyBy('kategori_id');

        return $sales->map(function ($row) use ($returns) {
            $ret = $returns->get($row->kategori_id);
            $revenue = (float) $row->revenue - (float) ($ret->revenue ?? 0);
            $hpp = (float) $row->hpp - (float) ($ret->hpp ?? 0);
            $profit = $revenue - $hpp;

            return (object) [
                'nama_kategori' => $row->nama_kategori ?? '(Tanpa Kategori)',
                'revenue' => $revenue,
                'hpp' => $hpp,
                'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            ];
        });
    }

    /**
     * @return Collection<int, object>
     */
    public static function topProductRows(string $from, string $to, int $limit = 10, ?int $terminalId = null): Collection
    {
        $filters = ['terminal_id' => $terminalId, 'kategori_id' => null];

        return self::baseSalesQuery($from, $to, $filters)
            ->join('master_produk as p', 'p.id', '=', 'd.product_id')
            ->select(
                'p.kode_produk',
                'p.nama_produk',
                DB::raw('SUM(d.qty_base) as qty'),
                DB::raw('SUM(d.jumlah) as revenue'),
                DB::raw('SUM(d.qty * d.hpp_at_time) as hpp')
            )
            ->groupBy('p.id', 'p.kode_produk', 'p.nama_produk')
            ->orderByDesc(DB::raw('SUM(d.jumlah) - SUM(d.qty * d.hpp_at_time)'))
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $revenue = (float) $row->revenue;
                $hpp = (float) $row->hpp;
                $profit = $revenue - $hpp;

                return (object) [
                    'kode_produk' => $row->kode_produk,
                    'nama_produk' => $row->nama_produk,
                    'qty' => (float) $row->qty,
                    'revenue' => $revenue,
                    'hpp' => $hpp,
                    'profit' => $profit,
                    'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
                ];
            });
    }

    private static function baseSalesQuery(string $from, string $to, array $filters)
    {
        $q = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59']);

        if ($filters['terminal_id']) {
            $q->where('s.terminal_id', $filters['terminal_id']);
        }
        if ($filters['kategori_id']) {
            $q->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('master_produk as mp')
                    ->whereColumn('mp.id', 'd.product_id')
                    ->where('mp.kategori_id', $filters['kategori_id']);
            });
        }

        return $q;
    }

    private static function baseReturnQuery(string $from, string $to, array $filters)
    {
        $q = DB::table('doc_sales_return_detail as rd')
            ->join('doc_sales_returns as r', 'r.id', '=', 'rd.return_id')
            ->whereBetween('r.tanggal', [$from.' 00:00:00', $to.' 23:59:59']);

        if ($filters['terminal_id']) {
            $q->where('r.terminal_id', $filters['terminal_id']);
        }
        if ($filters['kategori_id']) {
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
