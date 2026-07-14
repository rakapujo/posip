<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\ReportHelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Gross Profit = Revenue - HPP, dihitung dari doc_sales_detail.hpp_at_time (snapshot saat jual).
 * Return sales (doc_sales_return_detail) kurangi revenue + hpp dari periode yang sama.
 *
 * Semua endpoint butuh permission `laporan.keuangan` + `stok.view_hpp` (karena tampilkan HPP + profit).
 */
class GrossProfitReportController extends BaseApiController
{
    /**
     * Ringkasan profit periode: revenue, HPP, gross profit, margin %.
     */
    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeView()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $filters = $this->parseFilters($request);

        $salesAgg = $this->salesAggregate($from, $to, $filters);
        $returnAgg = $this->returnAggregate($from, $to, $filters);

        $revenue = (float) $salesAgg->revenue - (float) $returnAgg->revenue;
        $hpp = (float) $salesAgg->hpp - (float) $returnAgg->hpp;
        $profit = $revenue - $hpp;
        $margin = $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0;

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'revenue_gross' => (float) $salesAgg->revenue,
            'revenue_return' => (float) $returnAgg->revenue,
            'revenue_net' => $revenue,
            'hpp_gross' => (float) $salesAgg->hpp,
            'hpp_return' => (float) $returnAgg->hpp,
            'hpp_net' => $hpp,
            'gross_profit' => $profit,
            'margin_percent' => $margin,
            'trx_count' => (int) $salesAgg->trx_count,
        ]);
    }

    /**
     * Profit harian — untuk chart line.
     */
    public function daily(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeView()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $filters = $this->parseFilters($request);

        $sales = $this->baseSalesQuery($from, $to, $filters)
            ->select(
                DB::raw('DATE(s.tanggal) as tanggal'),
                DB::raw('SUM(d.jumlah) as revenue'),
                DB::raw('SUM(d.qty * d.hpp_at_time) as hpp'),
                DB::raw('COUNT(DISTINCT s.id) as trx_count')
            )
            ->groupBy(DB::raw('DATE(s.tanggal)'))
            ->get();

        $returns = $this->baseReturnQuery($from, $to, $filters)
            ->select(
                DB::raw('DATE(r.tanggal) as tanggal'),
                DB::raw('SUM(rd.harga_satuan * rd.qty) as revenue'),
                DB::raw('SUM(rd.qty * rd.hpp_at_time) as hpp')
            )
            ->groupBy(DB::raw('DATE(r.tanggal)'))
            ->get()
            ->keyBy('tanggal');

        $rows = $sales->map(function ($row) use ($returns) {
            $ret = $returns->get($row->tanggal);
            $revenue = (float) $row->revenue - (float) ($ret->revenue ?? 0);
            $hpp = (float) $row->hpp - (float) ($ret->hpp ?? 0);
            $profit = $revenue - $hpp;
            return [
                'tanggal' => $row->tanggal,
                'revenue' => $revenue,
                'hpp' => $hpp,
                'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
                'trx_count' => (int) $row->trx_count,
            ];
        });

        return $this->success(['items' => $rows->values()]);
    }

    /**
     * Profit per kategori produk.
     */
    public function byKategori(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeView()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $filters = $this->parseFilters($request);

        $sales = $this->baseSalesQuery($from, $to, $filters)
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

        $returns = $this->baseReturnQuery($from, $to, $filters)
            ->leftJoin('master_produk as p', 'p.id', '=', 'rd.product_id')
            ->select(
                'p.kategori_id',
                DB::raw('SUM(rd.harga_satuan * rd.qty) as revenue'),
                DB::raw('SUM(rd.qty * rd.hpp_at_time) as hpp')
            )
            ->groupBy('p.kategori_id')
            ->get()
            ->keyBy('kategori_id');

        $rows = $sales->map(function ($row) use ($returns) {
            $ret = $returns->get($row->kategori_id);
            $revenue = (float) $row->revenue - (float) ($ret->revenue ?? 0);
            $hpp = (float) $row->hpp - (float) ($ret->hpp ?? 0);
            $profit = $revenue - $hpp;
            return [
                'kategori_id' => $row->kategori_id,
                'nama_kategori' => $row->nama_kategori ?? '(Tanpa Kategori)',
                'revenue' => $revenue,
                'hpp' => $hpp,
                'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            ];
        });

        return $this->success(['items' => $rows->values()]);
    }

    /**
     * Top N produk by profit.
     */
    public function topProducts(Request $request): JsonResponse
    {
        if ($denied = $this->authorizeView()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $filters = $this->parseFilters($request);
        $limit = max(1, min(100, (int) $request->input('limit', 10)));

        $sales = $this->baseSalesQuery($from, $to, $filters)
            ->join('master_produk as p', 'p.id', '=', 'd.product_id')
            ->select(
                'p.id as product_id',
                'p.ulid as product_ulid',
                'p.kode_produk',
                'p.nama_produk',
                DB::raw('SUM(d.qty_base) as qty'),
                DB::raw('SUM(d.jumlah) as revenue'),
                DB::raw('SUM(d.qty * d.hpp_at_time) as hpp')
            )
            ->groupBy('p.id', 'p.ulid', 'p.kode_produk', 'p.nama_produk')
            ->orderByDesc(DB::raw('SUM(d.jumlah) - SUM(d.qty * d.hpp_at_time)'))
            ->limit($limit)
            ->get();

        $rows = $sales->map(function ($row) {
            $revenue = (float) $row->revenue;
            $hpp = (float) $row->hpp;
            $profit = $revenue - $hpp;
            return [
                'product_id' => $row->product_id,
                'product_ulid' => $row->product_ulid,
                'kode_produk' => $row->kode_produk,
                'nama_produk' => $row->nama_produk,
                'qty' => (float) $row->qty,
                'revenue' => $revenue,
                'hpp' => $hpp,
                'profit' => $profit,
                'margin_percent' => $revenue > 0 ? round(($profit / $revenue) * 100, 2) : 0,
            ];
        });

        return $this->success(['items' => $rows->values(), 'limit' => $limit]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function authorizeView(): ?JsonResponse
    {
        $user = auth()->user();
        if (!$user->can('laporan.keuangan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }
        if (!$user->can('stok.view_hpp')) {
            return $this->forbidden('Laporan Gross Profit butuh permission stok.view_hpp.');
        }
        return null;
    }

    private function parsePeriod(Request $request): array
    {
        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());
        return [$from, $to];
    }

    private function parseFilters(Request $request): array
    {
        return [
            'terminal_id' => $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            'kategori_id' => $request->filled('kategori_id') ? (int) $request->kategori_id : null,
        ];
    }

    private function baseSalesQuery(string $from, string $to, array $filters)
    {
        $q = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59']);

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

    private function baseReturnQuery(string $from, string $to, array $filters)
    {
        $q = DB::table('doc_sales_return_detail as rd')
            ->join('doc_sales_returns as r', 'r.id', '=', 'rd.return_id')
            ->whereBetween('r.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59']);

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

    private function salesAggregate(string $from, string $to, array $filters): object
    {
        return $this->baseSalesQuery($from, $to, $filters)
            ->select(
                DB::raw('COALESCE(SUM(d.jumlah), 0) as revenue'),
                DB::raw('COALESCE(SUM(d.qty * d.hpp_at_time), 0) as hpp'),
                DB::raw('COUNT(DISTINCT s.id) as trx_count')
            )
            ->first();
    }

    private function returnAggregate(string $from, string $to, array $filters): object
    {
        return $this->baseReturnQuery($from, $to, $filters)
            ->select(
                DB::raw('COALESCE(SUM(rd.harga_satuan * rd.qty), 0) as revenue'),
                DB::raw('COALESCE(SUM(rd.qty * rd.hpp_at_time), 0) as hpp')
            )
            ->first();
    }
}
