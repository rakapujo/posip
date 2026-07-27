<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocPromo;
use App\Services\Reports\ProductPromoReportResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Produk Dapat Promo — SETUP preview (bukan transaction).
 *
 * Menjawab: "Produk X saat ini ada promo apa saja?" / "Promo Y cover produk apa?"
 *
 * Source:
 *  - doc_promo (status + periode aktif via scopeEffective, atau semua approved)
 *  - doc_promo_details.target_type (semua/produk/grup/kategori) + target_id
 *
 * Resolver: target → list produk aktual via join chain.
 *
 * Permission: laporan.promo.
 */
class ProductPromoReportController extends BaseApiController
{
    /**
     * Tab A: per produk, show promo eligible.
     */
    public function byProduct(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        $promos = $this->fetchScopedPromos($request);
        $promoIds = $promos->pluck('id')->all();
        $detailsByPromo = ProductPromoReportResolver::detailsGroupedByPromo($promoIds);

        // Build mapping: product_id → [promo_id, promo_id, ...]
        $productPromoMap = ProductPromoReportResolver::productPromoMap($promos, $detailsByPromo);

        // Fetch produk list (with filter)
        $productsQuery = DB::table('master_produk as p')
            ->leftJoin('master_brand as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('master_tipe as t', 't.id', '=', 'p.tipe_id')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->leftJoin('master_grup as g', 'g.id', '=', 'p.grup_id')
            ->whereNull('p.deleted_at')
            ->select(
                'p.id', 'p.ulid', 'p.kode_produk', 'p.nama_produk', 'p.status',
                'p.kategori_id', 'p.grup_id',
                'b.nama_brand', 't.nama_tipe', 'k.nama_kategori', 'g.nama_grup'
            );

        if ($request->filled('product_status')) {
            $productsQuery->where('p.status', $request->product_status);
        } else {
            $productsQuery->where('p.status', 'active');
        }
        if ($request->filled('brand_id')) {
            $productsQuery->where('p.brand_id', $request->brand_id);
        }
        if ($request->filled('kategori_id')) {
            $productsQuery->where('p.kategori_id', $request->kategori_id);
        }
        if ($request->filled('grup_id')) {
            $productsQuery->where('p.grup_id', $request->grup_id);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $productsQuery->where(function ($q) use ($s) {
                $q->where('p.kode_produk', 'like', "%{$s}%")
                  ->orWhere('p.nama_produk', 'like', "%{$s}%");
            });
        }
        if ($request->boolean('only_with_promo')) {
            $productsQuery->whereIn('p.id', array_keys($productPromoMap));
        }

        $sort = $request->input('sort', 'promo_count_desc');
        match ($sort) {
            'kode_asc' => $productsQuery->orderBy('p.kode_produk'),
            'nama_asc' => $productsQuery->orderBy('p.nama_produk'),
            // promo_count_desc: hitung dulu di PHP (dari $productPromoMap), sort global sebelum paginate.
            default => $productsQuery->orderBy('p.kode_produk'),
        };

        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        $items = $productsQuery->get()->map(function ($p) use ($productPromoMap, $promos, $detailsByPromo) {
            $promoIdsForProduct = $productPromoMap[$p->id] ?? [];
            $promoList = collect($promoIdsForProduct)->map(function ($pid) use ($promos, $detailsByPromo, $p) {
                $promo = $promos->firstWhere('id', $pid);
                if (!$promo) return null;
                // Find which detail covers this product
                $matchingDetail = $this->findMatchingDetailForProduct($p, $detailsByPromo->get($pid, collect()));
                return [
                    'promo_id' => $promo->id,
                    'promo_ulid' => $promo->ulid,
                    'kode_promo' => $promo->kode_promo,
                    'nama_promo' => $promo->nama_promo,
                    'periode' => [
                        'tanggal_mulai' => $promo->tanggal_mulai,
                        'tanggal_selesai' => $promo->tanggal_selesai,
                        'jam_mulai' => $promo->jam_mulai,
                        'jam_selesai' => $promo->jam_selesai,
                    ],
                    'cover_type' => $matchingDetail?->target_type,
                    'diskon' => $matchingDetail ? $this->formatDiskon($matchingDetail) : null,
                ];
            })->filter()->values();

            return [
                'product_id' => $p->id,
                'product_ulid' => $p->ulid,
                'kode_produk' => $p->kode_produk,
                'nama_produk' => $p->nama_produk,
                'brand' => $p->nama_brand,
                'tipe' => $p->nama_tipe,
                'kategori' => $p->nama_kategori,
                'grup' => $p->nama_grup,
                'status' => $p->status,
                'promo_count' => $promoList->count(),
                'promos' => $promoList,
            ];
        });

        if ($sort === 'promo_count_desc') {
            $items = $items->sortByDesc('promo_count')->values();
        }

        $total = $items->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $currentPage = max(1, min($lastPage, (int) $request->input('page', 1)));
        $paged = $items->slice(($currentPage - 1) * $perPage, $perPage)->values();

        return $this->success([
            'items' => $paged,
            'pagination' => [
                'current_page' => $currentPage,
                'last_page' => $lastPage,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Tab B: per promo, show produk yang ter-cover.
     */
    public function byPromo(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        $promos = $this->fetchScopedPromos($request);
        $promoIds = $promos->pluck('id')->all();
        $detailsByPromo = ProductPromoReportResolver::detailsGroupedByPromo($promoIds);
        $targetNames = $this->batchLookupTargetNames($detailsByPromo);

        // B3.4: cap payload — promo "semua produk" bisa cover ribuan baris.
        $productsCap = 50;

        $items = $promos->map(function ($promo) use ($detailsByPromo, $targetNames, $productsCap) {
            $details = $detailsByPromo->get($promo->id, collect());
            $productsCovered = array_values($this->resolveProductsForSinglePromo($details));
            $totalProducts = count($productsCovered);

            return [
                'promo_id' => $promo->id,
                'promo_ulid' => $promo->ulid,
                'kode_promo' => $promo->kode_promo,
                'nama_promo' => $promo->nama_promo,
                'periode' => [
                    'tanggal_mulai' => $promo->tanggal_mulai,
                    'tanggal_selesai' => $promo->tanggal_selesai,
                    'jam_mulai' => $promo->jam_mulai,
                    'jam_selesai' => $promo->jam_selesai,
                ],
                'status' => $promo->status,
                'details' => $details->map(fn ($d) => [
                    'target_type' => $d->target_type,
                    'target_id' => $d->target_id,
                    'target_label' => $this->describeTarget($d, $targetNames),
                    'min_qty' => (int) $d->min_qty,
                    'diskon' => $this->formatDiskon($d),
                ])->values(),
                'product_count' => $totalProducts,
                'products' => array_slice($productsCovered, 0, $productsCap),
                'products_total' => $totalProducts,
                'products_truncated' => $totalProducts > $productsCap,
            ];
        });

        return $this->success(['items' => $items->values()]);
    }

    /**
     * Batch-fetch nama produk/kategori/grup untuk semua detail sekaligus (anti N+1).
     *
     * @return array{produk: array<int,string>, kategori: array<int,string>, grup: array<int,string>}
     */
    private function batchLookupTargetNames($detailsByPromo): array
    {
        $ids = ['produk' => [], 'kategori' => [], 'grup' => []];
        foreach ($detailsByPromo as $details) {
            foreach ($details as $d) {
                if (isset($ids[$d->target_type]) && $d->target_id) {
                    $ids[$d->target_type][] = (int) $d->target_id;
                }
            }
        }

        return [
            'produk' => $ids['produk']
                ? DB::table('master_produk')->whereIn('id', array_unique($ids['produk']))->pluck('nama_produk', 'id')->all()
                : [],
            'kategori' => $ids['kategori']
                ? DB::table('master_kategori')->whereIn('id', array_unique($ids['kategori']))->pluck('nama_kategori', 'id')->all()
                : [],
            'grup' => $ids['grup']
                ? DB::table('master_grup')->whereIn('id', array_unique($ids['grup']))->pluck('nama_grup', 'id')->all()
                : [],
        ];
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function authorize(): ?JsonResponse
    {
        if (!auth()->user()->can('laporan.promo')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }
        return null;
    }

    /**
     * Ambil promo sesuai filter status. Default: aktif sekarang (effective).
     */
    private function fetchScopedPromos(Request $request)
    {
        $status = $request->input('status', 'active_now');
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

        if ($request->filled('customer_type_id')) {
            $query->where('customer_type_id', $request->customer_type_id);
        }
        if ($request->filled('customer_category_id')) {
            $query->where('customer_category_id', $request->customer_category_id);
        }

        return $query->select('id', 'ulid', 'kode_promo', 'nama_promo',
            'tanggal_mulai', 'tanggal_selesai', 'jam_mulai', 'jam_selesai',
            'customer_type_id', 'customer_category_id', 'status')->get();
    }

    /**
     * For Tab B — resolve semua produk yang ter-cover oleh 1 promo.
     * Return: assoc array product_id → { id, kode_produk, nama_produk }
     */
    private function resolveProductsForSinglePromo($details): array
    {
        $ids = [];
        foreach ($details as $d) {
            $ids = array_merge($ids, ProductPromoReportResolver::expandTargetToProductIds($d));
        }
        $ids = array_values(array_unique($ids));
        if (empty($ids)) return [];

        $rows = DB::table('master_produk')
            ->whereIn('id', $ids)
            ->whereNull('deleted_at')
            ->select('id', 'ulid', 'kode_produk', 'nama_produk')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[$r->id] = [
                'id' => $r->id,
                'ulid' => $r->ulid,
                'kode_produk' => $r->kode_produk,
                'nama_produk' => $r->nama_produk,
            ];
        }
        return $out;
    }

    /**
     * Cari detail yang meng-cover produk $p dari 1 promo.
     * Urutan prioritas: produk spesifik > kategori > grup > semua.
     */
    private function findMatchingDetailForProduct(object $product, $details): ?object
    {
        // 1) Produk spesifik
        foreach ($details as $d) {
            if ($d->target_type === 'produk' && (int) $d->target_id === (int) $product->id) {
                return $d;
            }
        }
        // 2) Kategori (kategori_id/grup_id already selected on $product — no extra query)
        foreach ($details as $d) {
            if ($d->target_type === 'kategori' && (int) $d->target_id === (int) ($product->kategori_id ?? 0)) {
                return $d;
            }
        }
        // 3) Grup
        foreach ($details as $d) {
            if ($d->target_type === 'grup' && (int) $d->target_id === (int) ($product->grup_id ?? 0)) {
                return $d;
            }
        }
        // 4) Semua
        foreach ($details as $d) {
            if ($d->target_type === 'semua') return $d;
        }
        return null;
    }

    private function describeTarget(object $detail, array $targetNames): string
    {
        $id = (int) $detail->target_id;

        return match ($detail->target_type) {
            'semua' => 'Semua produk',
            'produk' => 'Produk: ' . ($targetNames['produk'][$id] ?? "#{$detail->target_id}"),
            'kategori' => 'Kategori: ' . ($targetNames['kategori'][$id] ?? "#{$detail->target_id}"),
            'grup' => 'Grup: ' . ($targetNames['grup'][$id] ?? "#{$detail->target_id}"),
            default => $detail->target_type,
        };
    }

    private function formatDiskon(object $detail): array
    {
        $out = [];
        for ($i = 1; $i <= 4; $i++) {
            $tipe = $detail->{"diskon_{$i}_tipe"} ?? 'none';
            $nilai = (float) ($detail->{"diskon_{$i}_nilai"} ?? 0);
            if ($tipe !== 'none' && $nilai > 0) {
                $out["slot_{$i}"] = ['tipe' => $tipe, 'nilai' => $nilai];
            }
        }
        return $out;
    }
}
