<?php

namespace App\Services\Reports;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DeadStockReportResolver
{
    /**
     * @param  array<string, mixed>  $options
     * @return array{cutoff_days: int, cutoff_date: string, total_products: int, total_value: ?float, can_view_hpp: bool, items: Collection<int, array<string, mixed>>}
     */
    public static function resolve(array $options, bool $canViewHpp): array
    {
        $minDays = max(1, (int) ($options['min_days_idle'] ?? 60));
        $includeNeverSold = (bool) ($options['include_never_sold'] ?? true);
        $minStock = (float) ($options['min_stock'] ?? 0.01);
        $sort = $options['sort'] ?? 'days_desc';
        $limit = max(1, min(500, (int) ($options['limit'] ?? 100)));

        $cutoff = now()->subDays($minDays)->toDateTimeString();

        $lastSoldMap = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->where('s.status', 'completed')
            ->select('d.product_id', DB::raw('MAX(s.tanggal) as last_sold'))
            ->groupBy('d.product_id')
            ->pluck('last_sold', 'product_id');

        $stockQ = DB::table('inventory_stock');
        if (! empty($options['warehouse_id'])) {
            $stockQ->where('warehouse_id', (int) $options['warehouse_id']);
        }
        $stockMap = $stockQ->select('product_id', DB::raw('SUM(qty) as qty_total'))
            ->groupBy('product_id')
            ->pluck('qty_total', 'product_id');

        $productQ = DB::table('master_produk as p')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->leftJoin('master_grup as g', 'g.id', '=', 'p.grup_id')
            ->whereNull('p.deleted_at')
            ->select(
                'p.id', 'p.ulid', 'p.kode_produk', 'p.nama_produk',
                'p.avg_cost', 'p.minimum_stok', 'p.status',
                'k.nama_kategori', 'g.nama_grup'
            );

        if (! empty($options['status'])) {
            $productQ->where('p.status', $options['status']);
        } else {
            $productQ->where('p.status', 'active');
        }
        if (! empty($options['kategori_id'])) {
            $productQ->where('p.kategori_id', $options['kategori_id']);
        }
        if (! empty($options['grup_id'])) {
            $productQ->where('p.grup_id', $options['grup_id']);
        }

        $items = $productQ->get()->map(function ($p) use ($lastSoldMap, $stockMap, $cutoff, $includeNeverSold, $minStock) {
            $lastSold = $lastSoldMap->get($p->id);
            $stockQty = (float) ($stockMap->get($p->id) ?? 0);

            $isDead = $lastSold === null
                ? $includeNeverSold
                : $lastSold < $cutoff;

            if (! $isDead) {
                return null;
            }
            if ($minStock > 0 && $stockQty < $minStock) {
                return null;
            }

            $daysIdle = $lastSold
                ? (int) now()->diffInDays(\Carbon\Carbon::parse($lastSold), absolute: true)
                : null;

            return [
                'product_id' => $p->id,
                'product_ulid' => $p->ulid,
                'kode_produk' => $p->kode_produk,
                'nama_produk' => $p->nama_produk,
                'kategori' => $p->nama_kategori,
                'grup' => $p->nama_grup,
                'stock_qty' => $stockQty,
                'avg_cost' => (float) $p->avg_cost,
                'stock_value' => $stockQty * (float) $p->avg_cost,
                'last_sold' => $lastSold,
                'days_idle' => $daysIdle,
                'never_sold' => $lastSold === null,
                'status' => $p->status,
            ];
        })->filter()->values();

        $items = match ($sort) {
            'value_desc' => $items->sortByDesc('stock_value')->values(),
            'qty_desc' => $items->sortByDesc('stock_qty')->values(),
            default => $items->sortBy(fn ($i) => $i['last_sold'] ?? '')->values(),
        };

        $items = $items->take($limit);

        $totalValue = $canViewHpp ? $items->sum('stock_value') : null;

        if (! $canViewHpp) {
            $items = $items->map(function ($i) {
                unset($i['avg_cost'], $i['stock_value']);

                return $i;
            })->values();
        }

        return [
            'cutoff_days' => $minDays,
            'cutoff_date' => $cutoff,
            'total_products' => $items->count(),
            'total_value' => $totalValue,
            'can_view_hpp' => $canViewHpp,
            'items' => $items,
        ];
    }
}
