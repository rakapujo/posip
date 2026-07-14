<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Top Customer — rank customer by omzet/frekuensi dalam periode.
 *
 * Source: doc_sales yang status = completed, grouped by customer_id.
 * Metric:
 *  - Jumlah transaksi
 *  - Qty items total (via join ke doc_sales_detail)
 *  - Omzet (SUM grand_total)
 *  - Avg per trx
 *  - Last trx tanggal
 *
 * Filter: date range, tipe customer, kategori customer, limit top N.
 * Permission: laporan.performa.
 */
class TopCustomerReportController extends BaseApiController
{
    public function top(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.performa')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'tipe_customer_id' => 'nullable|integer',
            'kategori_customer_id' => 'nullable|integer',
            'limit' => 'nullable|integer|min:1|max:200',
            'sort' => 'nullable|in:omzet_desc,trx_desc,avg_desc,last_desc',
        ]);

        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());
        $limit = max(1, min(200, (int) $request->input('limit', 50)));
        $sort = $request->input('sort', 'omzet_desc');

        $rows = DB::table('doc_sales as s')
            ->join('master_customer as c', 'c.id', '=', 's.customer_id')
            ->leftJoin('master_tipe_customer as t', 't.id', '=', 'c.tipe_customer_id')
            ->leftJoin('master_kategori_customer as k', 'k.id', '=', 'c.kategori_customer_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($request->filled('tipe_customer_id'),
                fn ($q) => $q->where('c.tipe_customer_id', $request->tipe_customer_id))
            ->when($request->filled('kategori_customer_id'),
                fn ($q) => $q->where('c.kategori_customer_id', $request->kategori_customer_id))
            ->select(
                'c.id as customer_id',
                'c.ulid as customer_ulid',
                'c.kode_customer',
                'c.nama as customer_nama',
                't.nama_tipe',
                'k.nama_kategori',
                DB::raw('COUNT(DISTINCT s.id) as trx_count'),
                DB::raw('COALESCE(SUM(s.grand_total), 0) as omzet'),
                DB::raw('MAX(s.tanggal) as last_trx_at')
            )
            ->groupBy('c.id', 'c.ulid', 'c.kode_customer', 'c.nama', 't.nama_tipe', 'k.nama_kategori');

        // Sort
        match ($sort) {
            'trx_desc' => $rows->orderByDesc(DB::raw('COUNT(DISTINCT s.id)')),
            'avg_desc' => $rows->orderByDesc(DB::raw('COALESCE(SUM(s.grand_total), 0) * 1.0 / COUNT(DISTINCT s.id)')),
            'last_desc' => $rows->orderByDesc(DB::raw('MAX(s.tanggal)')),
            default => $rows->orderByDesc(DB::raw('COALESCE(SUM(s.grand_total), 0)')),
        };

        $results = $rows->limit($limit)->get();

        // Qty items lookup (separate query for clarity)
        $customerIds = $results->pluck('customer_id')->all();
        $qtyMap = collect();
        if (!empty($customerIds)) {
            $qtyMap = DB::table('doc_sales_detail as d')
                ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
                ->where('s.status', 'completed')
                ->whereBetween('s.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59'])
                ->whereIn('s.customer_id', $customerIds)
                ->select('s.customer_id', DB::raw('COALESCE(SUM(d.qty_base), 0) as qty_total'))
                ->groupBy('s.customer_id')
                ->get()
                ->keyBy('customer_id');
        }

        $items = $results->map(function ($r, $idx) use ($qtyMap) {
            $trx = (int) $r->trx_count;
            $omzet = (float) $r->omzet;
            return [
                'rank' => $idx + 1,
                'customer_id' => $r->customer_id,
                'customer_ulid' => $r->customer_ulid,
                'kode_customer' => $r->kode_customer,
                'customer_nama' => $r->customer_nama,
                'tipe' => $r->nama_tipe,
                'kategori' => $r->nama_kategori,
                'trx_count' => $trx,
                'qty_total' => (float) ($qtyMap->get($r->customer_id)->qty_total ?? 0),
                'omzet' => $omzet,
                'avg_per_trx' => $trx > 0 ? round($omzet / $trx, 2) : 0,
                'last_trx_at' => $r->last_trx_at,
            ];
        });

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'limit' => $limit,
            'items' => $items->values(),
        ]);
    }
}
