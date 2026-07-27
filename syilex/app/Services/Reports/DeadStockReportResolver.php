<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeadStockReportResolver
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{cutoff_days: int, cutoff_date: string, total_products: int, total_value: ?float, can_view_hpp: bool, truncated: bool, items: Collection<int, array<string, mixed>>}
     */
    public static function resolve(array $options, bool $canViewHpp): array
    {
        $minDays = max(1, (int) ($options['min_days_idle'] ?? 60));
        $includeNeverSold = (bool) ($options['include_never_sold'] ?? true);
        $minStock = (float) ($options['min_stock'] ?? 0.01);
        $sort = $options['sort'] ?? 'days_desc';
        $limit = max(1, min(500, (int) ($options['limit'] ?? 100)));

        if (! $canViewHpp && $sort === 'value_desc') {
            $sort = 'days_desc';
        }

        $cutoff = now()->subDays($minDays)->toDateTimeString();

        $lastSoldSub = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->where('s.status', 'completed')
            ->select('d.product_id', DB::raw('MAX(s.tanggal) as last_sold'))
            ->groupBy('d.product_id');

        $stockSub = DB::table('inventory_stock');
        if (! empty($options['warehouse_id'])) {
            $stockSub->where('warehouse_id', (int) $options['warehouse_id']);
        }
        $stockSub->select('product_id', DB::raw('SUM(qty) as stock_qty'))->groupBy('product_id');

        $base = DB::table('master_produk as p')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->leftJoin('master_grup as g', 'g.id', '=', 'p.grup_id')
            ->leftJoinSub($lastSoldSub, 'ls', 'ls.product_id', '=', 'p.id')
            ->leftJoinSub($stockSub, 'st', 'st.product_id', '=', 'p.id')
            ->whereNull('p.deleted_at')
            ->where('p.status', ! empty($options['status']) ? $options['status'] : 'active')
            ->where(function ($q) use ($cutoff, $includeNeverSold) {
                if ($includeNeverSold) {
                    $q->whereNull('ls.last_sold')->orWhere('ls.last_sold', '<', $cutoff);
                } else {
                    $q->where('ls.last_sold', '<', $cutoff);
                }
            });

        if ($minStock > 0) {
            $base->where(DB::raw('COALESCE(st.stock_qty, 0)'), '>=', $minStock);
        }
        if (! empty($options['kategori_id'])) {
            $base->where('p.kategori_id', $options['kategori_id']);
        }
        if (! empty($options['grup_id'])) {
            $base->where('p.grup_id', $options['grup_id']);
        }
        if (array_key_exists('is_serial', $options) && $options['is_serial'] !== null) {
            $base->where('p.is_serial', (bool) $options['is_serial']);
        }

        // Totals computed BEFORE limit (Wave A behavior) — clone base ahead of any select/order.
        $agg = (clone $base)->select(
            DB::raw('COUNT(*) as total_products'),
            DB::raw('SUM(COALESCE(st.stock_qty, 0) * p.avg_cost) as total_value')
        )->first();

        $totalProducts = (int) $agg->total_products;
        $totalValue = $canViewHpp ? (float) ($agg->total_value ?? 0) : null;
        $truncated = $totalProducts > $limit;

        $itemsQuery = (clone $base)->select(
            'p.id', 'p.ulid', 'p.kode_produk', 'p.nama_produk',
            'p.avg_cost', 'p.minimum_stok', 'p.status', 'p.is_serial',
            'k.nama_kategori', 'g.nama_grup',
            DB::raw('COALESCE(st.stock_qty, 0) as stock_qty'),
            'ls.last_sold'
        );

        match ($sort) {
            'value_desc' => $itemsQuery->orderByDesc(DB::raw('COALESCE(st.stock_qty, 0) * p.avg_cost')),
            'qty_desc' => $itemsQuery->orderByDesc(DB::raw('COALESCE(st.stock_qty, 0)')),
            default => $itemsQuery->orderByRaw('(ls.last_sold IS NULL) DESC, ls.last_sold ASC'),
        };

        $items = $itemsQuery->limit($limit)->get()->map(function ($p) {
            $stockQty = (float) $p->stock_qty;
            $daysIdle = $p->last_sold
                ? (int) now()->diffInDays(\Carbon\Carbon::parse($p->last_sold), absolute: true)
                : null;

            return [
                'product_id' => $p->id,
                'product_ulid' => $p->ulid,
                'kode_produk' => $p->kode_produk,
                'nama_produk' => $p->nama_produk,
                'kategori' => $p->nama_kategori,
                'grup' => $p->nama_grup,
                'is_serial' => (bool) $p->is_serial,
                'stock_qty' => $stockQty,
                'avg_cost' => (float) $p->avg_cost,
                'stock_value' => $stockQty * (float) $p->avg_cost,
                'last_sold' => $p->last_sold,
                'days_idle' => $daysIdle,
                'never_sold' => $p->last_sold === null,
                'status' => $p->status,
            ];
        });

        if (! $canViewHpp) {
            $items = $items->map(function ($i) {
                unset($i['avg_cost'], $i['stock_value']);

                return $i;
            })->values();
        }

        return [
            'cutoff_days' => $minDays,
            'cutoff_date' => $cutoff,
            'total_products' => $totalProducts,
            'total_value' => $totalValue,
            'can_view_hpp' => $canViewHpp,
            'truncated' => $truncated,
            'items' => $items,
        ];
    }
}
