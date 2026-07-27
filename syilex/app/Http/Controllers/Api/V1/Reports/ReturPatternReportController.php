<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Retur Pattern — agregasi retur penjualan per produk untuk detect masalah quality / SKU.
 *
 * Source: doc_sales_return_detail + doc_sales_returns.
 * Metric per produk:
 *  - Jumlah kali diretur (distinct return_id yang detail-nya pakai produk ini)
 *  - Qty total yang diretur
 *  - Nominal total retur (harga_satuan × qty)
 *  - Rata-rata umur barang antara sale → return (days)
 *
 * Summary: total retur, total nominal, retur rate (retur vs sales qty).
 * Permission: laporan.inventory.
 */
class ReturPatternReportController extends BaseApiController
{
    public function pattern(Request $request): JsonResponse
    {
        if (! auth()->user()->can('laporan.inventory')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'terminal_id' => 'nullable|integer',
            'kategori_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:200',
            'sort' => 'nullable|in:count_desc,qty_desc,nominal_desc',
        ]);

        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());
        $terminalId = $request->filled('terminal_id') ? (int) $request->terminal_id : null;
        $kategoriId = $request->filled('kategori_id') ? (int) $request->kategori_id : null;
        $limit = max(1, min(200, (int) $request->input('limit', 50)));
        $sort = $request->input('sort', 'count_desc');

        $base = DB::table('doc_sales_return_detail as rd')
            ->join('doc_sales_returns as r', 'r.id', '=', 'rd.return_id')
            ->join('doc_sales_detail as sd', 'sd.id', '=', 'rd.sales_detail_id')
            ->join('doc_sales as os', 'os.id', '=', 'sd.sales_id')
            ->join('master_produk as p', 'p.id', '=', 'rd.product_id')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->whereNull('p.deleted_at')
            ->whereIn('r.status', ['lock', 'approved'])
            ->whereBetween('r.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('r.terminal_id', $terminalId))
            ->when($kategoriId, fn ($q) => $q->where('p.kategori_id', $kategoriId));

        // Summary — avg_days_sale_to_return: rata-rata umur barang antara sale (os.tanggal) → return (r.tanggal).
        $summary = (clone $base)
            ->select(
                DB::raw('COUNT(DISTINCT r.id) as retur_count'),
                DB::raw('COALESCE(SUM(rd.qty_base), 0) as qty_total'),
                DB::raw('COALESCE(SUM(rd.harga_satuan * rd.qty), 0) as nominal_total'),
                DB::raw('AVG(DATEDIFF(r.tanggal, os.tanggal)) as avg_days_sale_to_return')
            )
            ->first();

        // Total qty sales di periode yang sama (untuk rate calc)
        $salesQty = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('s.terminal_id', $terminalId))
            ->when($kategoriId, fn ($q) => $q->join('master_produk as mp', 'mp.id', '=', 'd.product_id')
                ->where('mp.kategori_id', $kategoriId))
            ->sum('d.qty_base');

        $rate = $salesQty > 0 ? round(((float) $summary->qty_total / (float) $salesQty) * 100, 2) : 0;

        // Per produk breakdown
        $rows = (clone $base)
            ->select(
                'p.id as product_id',
                'p.ulid as product_ulid',
                'p.kode_produk',
                'p.nama_produk',
                'k.nama_kategori',
                DB::raw('COUNT(DISTINCT r.id) as retur_count'),
                DB::raw('SUM(rd.qty_base) as qty_total'),
                DB::raw('SUM(rd.harga_satuan * rd.qty) as nominal_total'),
                DB::raw('AVG(DATEDIFF(r.tanggal, os.tanggal)) as avg_days_sale_to_return')
            )
            ->groupBy('p.id', 'p.ulid', 'p.kode_produk', 'p.nama_produk', 'k.nama_kategori');

        match ($sort) {
            'qty_desc' => $rows->orderByDesc(DB::raw('SUM(rd.qty_base)')),
            'nominal_desc' => $rows->orderByDesc(DB::raw('SUM(rd.harga_satuan * rd.qty)')),
            default => $rows->orderByDesc(DB::raw('COUNT(DISTINCT r.id)')),
        };

        $items = $rows->limit($limit)->get()->map(fn ($r) => [
            'product_id' => $r->product_id,
            'product_ulid' => $r->product_ulid,
            'kode_produk' => $r->kode_produk,
            'nama_produk' => $r->nama_produk,
            'kategori' => $r->nama_kategori,
            'retur_count' => (int) $r->retur_count,
            'qty_total' => (float) $r->qty_total,
            'nominal_total' => (float) $r->nominal_total,
            'avg_days_sale_to_return' => $r->avg_days_sale_to_return !== null ? round((float) $r->avg_days_sale_to_return, 1) : null,
        ]);

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'summary' => [
                'retur_count' => (int) $summary->retur_count,
                'qty_total' => (float) $summary->qty_total,
                'nominal_total' => (float) $summary->nominal_total,
                'sales_qty_total' => (float) $salesQty,
                'retur_rate_percent' => $rate,
                'avg_days_sale_to_return' => $summary->avg_days_sale_to_return !== null ? round((float) $summary->avg_days_sale_to_return, 1) : null,
            ],
            'items' => $items->values(),
        ]);
    }
}
