<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocPromo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Promo Usage & ROI — transaction-based analytics.
 *
 * Source: doc_sales_detail.promo_id (LINE-level, set saat checkout oleh CheckoutSalesAction).
 * Hanya sales completed yang dihitung.
 *
 * Metric per promo:
 *  - Jumlah transaksi pakai (distinct sales_id)
 *  - Qty item terjual via promo (SUM qty_base)
 *  - Total diskon (SUM diskon_total dari item yang promo_id = X)
 *  - Revenue item setelah diskon (SUM jumlah)
 *
 * Permission: laporan.promo.
 */
class PromoUsageReportController extends BaseApiController
{
    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        [$from, $to] = $this->parsePeriod($request);
        $filters = $this->parseFilters($request);

        $base = $this->baseQuery($from, $to, $filters);

        $agg = (clone $base)
            ->select(
                DB::raw('COUNT(DISTINCT d.promo_id) as promo_used'),
                DB::raw('COUNT(DISTINCT d.sales_id) as trx_count'),
                DB::raw('COALESCE(SUM(d.qty_base), 0) as qty_total'),
                DB::raw('COALESCE(SUM(d.diskon_total), 0) as diskon_total'),
                DB::raw('COALESCE(SUM(d.jumlah), 0) as revenue_net')
            )
            ->first();

        // Total promo yang approved + periode aktif (denominator)
        $totalPromos = DocPromo::query()
            ->where('status', 'approved')
            ->count();

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'promo_used' => (int) $agg->promo_used,
            'total_promos_approved' => (int) $totalPromos,
            'trx_count' => (int) $agg->trx_count,
            'qty_total' => (float) $agg->qty_total,
            'diskon_total' => (float) $agg->diskon_total,
            'revenue_net' => (float) $agg->revenue_net,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        [$from, $to] = $this->parsePeriod($request);
        $filters = $this->parseFilters($request);
        $sort = $request->input('sort', 'diskon_desc');

        $used = $this->baseQuery($from, $to, $filters)
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
            ->select('id', 'ulid', 'kode_promo', 'nama_promo', 'tanggal_mulai', 'tanggal_selesai', 'jam_mulai', 'jam_selesai', 'status')
            ->get()
            ->keyBy('id');

        $rows = $used->map(function ($u) use ($promos) {
            $promo = $promos->get($u->promo_id);
            return [
                'promo_id' => $u->promo_id,
                'promo_ulid' => $promo?->ulid,
                'kode_promo' => $promo?->kode_promo,
                'nama_promo' => $promo?->nama_promo,
                'periode' => [
                    'tanggal_mulai' => $promo?->tanggal_mulai,
                    'tanggal_selesai' => $promo?->tanggal_selesai,
                    'jam_mulai' => $promo?->jam_mulai,
                    'jam_selesai' => $promo?->jam_selesai,
                ],
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

        // Tambah promo yang approved tapi tidak dipakai di periode (zero usage)
        if ($request->boolean('include_unused')) {
            $unusedPromos = DocPromo::where('status', 'approved')
                ->whereNotIn('id', $promoIds)
                ->select('id', 'ulid', 'kode_promo', 'nama_promo', 'tanggal_mulai', 'tanggal_selesai', 'jam_mulai', 'jam_selesai', 'status')
                ->get();

            $unusedRows = $unusedPromos->map(fn ($p) => [
                'promo_id' => $p->id,
                'promo_ulid' => $p->ulid,
                'kode_promo' => $p->kode_promo,
                'nama_promo' => $p->nama_promo,
                'periode' => [
                    'tanggal_mulai' => $p->tanggal_mulai,
                    'tanggal_selesai' => $p->tanggal_selesai,
                    'jam_mulai' => $p->jam_mulai,
                    'jam_selesai' => $p->jam_selesai,
                ],
                'status' => $p->status,
                'trx_count' => 0,
                'qty_total' => 0,
                'diskon_total' => 0,
                'revenue_net' => 0,
            ]);

            $rows = $rows->concat($unusedRows);
        }

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'items' => $rows->values(),
        ]);
    }

    /**
     * Detail per promo: top produk + top customer yang pakai promo ini.
     */
    public function show(Request $request, string $promoUlid): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        $promo = DocPromo::where('ulid', $promoUlid)->first();
        if (!$promo) {
            return $this->notFound('Promo tidak ditemukan.');
        }

        [$from, $to] = $this->parsePeriod($request);
        $filters = $this->parseFilters($request);
        $limit = max(1, min(50, (int) $request->input('limit', 5)));

        $topProducts = $this->baseQuery($from, $to, $filters)
            ->where('d.promo_id', $promo->id)
            ->join('master_produk as p', 'p.id', '=', 'd.product_id')
            ->select(
                'p.id as product_id',
                'p.kode_produk',
                'p.nama_produk',
                DB::raw('SUM(d.qty_base) as qty'),
                DB::raw('SUM(d.diskon_total) as diskon'),
                DB::raw('SUM(d.jumlah) as revenue')
            )
            ->groupBy('p.id', 'p.kode_produk', 'p.nama_produk')
            ->orderByDesc(DB::raw('SUM(d.diskon_total)'))
            ->limit($limit)
            ->get();

        $topCustomers = $this->baseQuery($from, $to, $filters)
            ->where('d.promo_id', $promo->id)
            ->leftJoin('master_customer as c', 'c.id', '=', 's.customer_id')
            ->select(
                's.customer_id',
                'c.kode_customer',
                'c.nama as customer_nama',
                DB::raw('COUNT(DISTINCT d.sales_id) as trx_count'),
                DB::raw('SUM(d.diskon_total) as diskon_total')
            )
            ->groupBy('s.customer_id', 'c.kode_customer', 'c.nama')
            ->orderByDesc(DB::raw('SUM(d.diskon_total)'))
            ->limit($limit)
            ->get();

        return $this->success([
            'promo' => [
                'id' => $promo->id,
                'ulid' => $promo->ulid,
                'kode_promo' => $promo->kode_promo,
                'nama_promo' => $promo->nama_promo,
                'periode' => [
                    'tanggal_mulai' => $promo->tanggal_mulai,
                    'tanggal_selesai' => $promo->tanggal_selesai,
                    'jam_mulai' => $promo->jam_mulai,
                    'jam_selesai' => $promo->jam_selesai,
                ],
                'status' => $promo->status,
            ],
            'top_products' => $topProducts,
            'top_customers' => $topCustomers,
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function authorize(): ?JsonResponse
    {
        if (!auth()->user()->can('laporan.promo')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
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
            'customer_type_id' => $request->filled('customer_type_id') ? (int) $request->customer_type_id : null,
        ];
    }

    private function baseQuery(string $from, string $to, array $filters)
    {
        $q = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->where('s.status', 'completed')
            ->whereNotNull('d.promo_id')
            ->whereBetween('s.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59']);

        if ($filters['terminal_id']) {
            $q->where('s.terminal_id', $filters['terminal_id']);
        }
        if ($filters['customer_type_id']) {
            $q->join('master_customer as c', 'c.id', '=', 's.customer_id')
              ->where('c.tipe_customer_id', $filters['customer_type_id']);
        }
        return $q;
    }
}
