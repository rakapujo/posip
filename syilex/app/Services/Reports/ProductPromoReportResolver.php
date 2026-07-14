<?php

namespace App\Services\Reports;

use App\Models\DocPromo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProductPromoReportResolver
{
    public static function fetchScopedPromos(string $status = 'active_now'): Collection
    {
        $query = DocPromo::query();

        match ($status) {
            'active_now' => $query->effective(),
            'approved_all' => $query->where('status', 'approved'),
            'upcoming' => $query->where('status', 'approved')
                ->where('tanggal_mulai', '>', now()->toDateString()),
            'expired' => $query->where('status', 'approved')
                ->whereNotNull('tanggal_selesai')
                ->where('tanggal_selesai', '<', now()->toDateString()),
            default => $query->effective(),
        };

        return $query->select('id', 'kode_promo', 'nama_promo')->get();
    }

    /**
     * @return array<int, list<int>>
     */
    public static function productPromoMap(Collection $promos, Collection $detailsByPromo): array
    {
        $map = [];
        foreach ($promos as $promo) {
            $details = $detailsByPromo->get($promo->id, collect());
            foreach ($details as $detail) {
                foreach (self::expandTargetToProductIds($detail) as $pid) {
                    $map[$pid][$promo->id] = $promo->id;
                }
            }
        }

        foreach ($map as $pid => $promoSet) {
            $map[$pid] = array_values($promoSet);
        }

        return $map;
    }

    /**
     * @return list<int>
     */
    public static function expandTargetToProductIds(object $detail): array
    {
        return match ($detail->target_type) {
            'semua' => DB::table('master_produk')
                ->whereNull('deleted_at')->where('status', 'active')->pluck('id')->all(),
            'produk' => $detail->target_id ? [(int) $detail->target_id] : [],
            'kategori' => DB::table('master_produk')
                ->whereNull('deleted_at')->where('kategori_id', $detail->target_id)->pluck('id')->all(),
            'grup' => DB::table('master_produk')
                ->whereNull('deleted_at')->where('grup_id', $detail->target_id)->pluck('id')->all(),
            default => [],
        };
    }

    public static function detailsGroupedByPromo(array $promoIds): Collection
    {
        if ($promoIds === []) {
            return collect();
        }

        return DB::table('doc_promo_details')
            ->whereIn('promo_id', $promoIds)
            ->get()
            ->groupBy('promo_id');
    }

    /**
     * @return Collection<int, object>
     */
    public static function byProductRows(string $status = 'active_now', bool $onlyWithPromo = false): Collection
    {
        $promos = self::fetchScopedPromos($status);
        $promoIds = $promos->pluck('id')->all();
        $detailsByPromo = self::detailsGroupedByPromo($promoIds);
        $productPromoMap = self::productPromoMap($promos, $detailsByPromo);
        $promoById = $promos->keyBy('id');

        $products = DB::table('master_produk as p')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->leftJoin('master_brand as b', 'b.id', '=', 'p.brand_id')
            ->whereNull('p.deleted_at')
            ->where('p.status', 'active')
            ->select('p.id', 'p.kode_produk', 'p.nama_produk', 'k.nama_kategori', 'b.nama_brand')
            ->orderBy('p.kode_produk')
            ->limit(5000)
            ->get();

        return $products->map(function ($p) use ($productPromoMap, $promoById, $onlyWithPromo) {
            $promoIdsForProduct = $productPromoMap[$p->id] ?? [];
            if ($onlyWithPromo && $promoIdsForProduct === []) {
                return null;
            }

            $codes = collect($promoIdsForProduct)
                ->map(fn ($pid) => $promoById->get($pid)?->kode_promo)
                ->filter()
                ->implode(', ');

            return (object) [
                'kode_produk' => $p->kode_produk,
                'nama_produk' => $p->nama_produk,
                'brand' => $p->nama_brand ?? '-',
                'kategori' => $p->nama_kategori ?? '-',
                'promo_count' => count($promoIdsForProduct),
                'kode_promos' => $codes ?: '-',
            ];
        })->filter()->values();
    }
}
