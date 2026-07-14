<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\MasterCustomer;
use App\Services\Reports\CustomerPromoReportResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Customer Dapat Promo — SETUP/ELIGIBILITY preview.
 *
 * Menjawab: "Customer X dapat diskon apa saja (nota + line promo)?"
 *
 * Sumber data:
 *  - master_tipe_customer.diskon_tipe/nilai → disc nota slot 1 (auto)
 *  - master_kategori_customer.diskon_tipe/nilai → disc nota slot 2 (auto)
 *  - doc_promo scoped by customer_type_id / customer_category_id → line promo slot 1-4
 *
 * Permission: laporan.promo.
 */
class CustomerPromoReportController extends BaseApiController
{
    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        $promos = $this->fetchScopedPromos($request);

        // Tipe customer yang punya disc nota
        $tipeTotal = DB::table('master_tipe_customer')->count();
        $tipeWithDisc = DB::table('master_tipe_customer')
            ->where('diskon_tipe', '!=', 'none')
            ->where('diskon_nilai', '>', 0)
            ->count();

        // Kategori customer yang punya disc nota
        $katTotal = DB::table('master_kategori_customer')->count();
        $katWithDisc = DB::table('master_kategori_customer')
            ->where('diskon_tipe', '!=', 'none')
            ->where('diskon_nilai', '>', 0)
            ->count();

        // Customer terjaring: minimal 1 eligible (disc nota atau line promo)
        $totalCustomer = DB::table('master_customer')->whereNull('deleted_at')->count();
        $terjaringCount = CustomerPromoReportResolver::countTerjaringCustomers($promos);

        return $this->success([
            'tipe_with_disc' => $tipeWithDisc,
            'tipe_total' => $tipeTotal,
            'kategori_with_disc' => $katWithDisc,
            'kategori_total' => $katTotal,
            'promo_aktif' => $promos->count(),
            'customer_terjaring' => $terjaringCount,
            'customer_total' => $totalCustomer,
        ]);
    }

    /**
     * Tab 1: Per Tipe Customer + promo line yang eligible.
     */
    public function byTipe(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        $promos = $this->fetchScopedPromos($request);

        $tipes = DB::table('master_tipe_customer')
            ->select('id', 'ulid', 'kode_tipe', 'nama_tipe', 'diskon_tipe', 'diskon_nilai')
            ->orderBy('kode_tipe')
            ->get();

        $items = $tipes->map(function ($t) use ($promos) {
            $customerCount = DB::table('master_customer')
                ->whereNull('deleted_at')
                ->where('tipe_customer_id', $t->id)
                ->count();

            // Promo eligible untuk tipe ini:
            //   (customer_type_id == this OR customer_type_id NULL) AND customer_category_id NULL
            // (Promo yang scope kategori — bukan termasuk di "per tipe"; itu domain Tab Kategori)
            $eligible = CustomerPromoReportResolver::eligiblePromosForTipe($t, $promos);

            return [
                'tipe_id' => $t->id,
                'tipe_ulid' => $t->ulid,
                'kode_tipe' => $t->kode_tipe,
                'nama_tipe' => $t->nama_tipe,
                'disc_nota' => CustomerPromoReportResolver::formatAutoDisc($t->diskon_tipe, (float) $t->diskon_nilai),
                'customer_count' => $customerCount,
                'promo_count' => $eligible->count(),
                'promos' => $eligible->map(fn ($p) => $this->promoBrief($p))->values(),
            ];
        });

        return $this->success(['items' => $items->values()]);
    }

    /**
     * Tab 2: Per Kategori Customer + promo line eligible.
     */
    public function byKategori(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        $promos = $this->fetchScopedPromos($request);

        $kategoris = DB::table('master_kategori_customer')
            ->select('id', 'ulid', 'kode_kategori', 'nama_kategori', 'diskon_tipe', 'diskon_nilai')
            ->orderBy('kode_kategori')
            ->get();

        $items = $kategoris->map(function ($k) use ($promos) {
            $customerCount = DB::table('master_customer')
                ->whereNull('deleted_at')
                ->where('kategori_customer_id', $k->id)
                ->count();

            $eligible = $promos->filter(function ($p) use ($k) {
                return (int) $p->customer_category_id === (int) $k->id;
            });

            return [
                'kategori_id' => $k->id,
                'kategori_ulid' => $k->ulid,
                'kode_kategori' => $k->kode_kategori,
                'nama_kategori' => $k->nama_kategori,
                'disc_nota' => CustomerPromoReportResolver::formatAutoDisc($k->diskon_tipe, (float) $k->diskon_nilai),
                'customer_count' => $customerCount,
                'promo_count' => $eligible->count(),
                'promos' => $eligible->map(fn ($p) => $this->promoBrief($p))->values(),
            ];
        });

        return $this->success(['items' => $items->values()]);
    }

    /**
     * Tab 3: Per Customer — paginated, default sort by promo count DESC, terjaring highlighted.
     */
    public function byCustomer(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        $request->validate([
            'tipe_id' => 'nullable|integer',
            'kategori_id' => 'nullable|integer',
            'search' => 'nullable|string|max:100',
            'only_terjaring' => 'nullable|boolean',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $promos = $this->fetchScopedPromos($request);
        $perPage = max(1, min(100, (int) $request->input('per_page', 25)));

        $q = DB::table('master_customer as c')
            ->leftJoin('master_tipe_customer as t', 't.id', '=', 'c.tipe_customer_id')
            ->leftJoin('master_kategori_customer as k', 'k.id', '=', 'c.kategori_customer_id')
            ->whereNull('c.deleted_at')
            ->select(
                'c.id', 'c.ulid', 'c.kode_customer', 'c.nama',
                'c.tipe_customer_id', 'c.kategori_customer_id',
                't.kode_tipe', 't.nama_tipe', 't.diskon_tipe as tipe_disc_tipe', 't.diskon_nilai as tipe_disc_nilai',
                'k.kode_kategori', 'k.nama_kategori', 'k.diskon_tipe as kat_disc_tipe', 'k.diskon_nilai as kat_disc_nilai'
            );

        if ($request->filled('tipe_id')) {
            $q->where('c.tipe_customer_id', $request->tipe_id);
        }
        if ($request->filled('kategori_id')) {
            $q->where('c.kategori_customer_id', $request->kategori_id);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('c.kode_customer', 'like', "%{$s}%")
                   ->orWhere('c.nama', 'like', "%{$s}%");
            });
        }

        // Paginate — sort by promo count di-handle post-fetch karena count dihitung di PHP
        $q->orderBy('c.kode_customer');

        $paginator = $q->paginate($perPage);

        $items = collect($paginator->items())->map(function ($c) use ($promos) {
            $tipeDisc = CustomerPromoReportResolver::formatAutoDisc($c->tipe_disc_tipe, (float) ($c->tipe_disc_nilai ?? 0));
            $katDisc = CustomerPromoReportResolver::formatAutoDisc($c->kat_disc_tipe, (float) ($c->kat_disc_nilai ?? 0));

            $eligible = CustomerPromoReportResolver::eligiblePromosFor($c->tipe_customer_id, $c->kategori_customer_id, $promos);

            $hasNotaDisc = $tipeDisc['has_disc'] || $katDisc['has_disc'];
            $hasLine = $eligible->count() > 0;

            return [
                'customer_id' => $c->id,
                'customer_ulid' => $c->ulid,
                'kode_customer' => $c->kode_customer,
                'nama_customer' => $c->nama,
                'tipe' => $c->kode_tipe ? ['kode' => $c->kode_tipe, 'nama' => $c->nama_tipe] : null,
                'kategori' => $c->kode_kategori ? ['kode' => $c->kode_kategori, 'nama' => $c->nama_kategori] : null,
                'disc_nota_tipe' => $tipeDisc,
                'disc_nota_kategori' => $katDisc,
                'promo_line_count' => $eligible->count(),
                'terjaring' => $hasNotaDisc || $hasLine,
            ];
        });

        // Filter only_terjaring post-fetch (sudah dihitung terjaring per row)
        if ($request->boolean('only_terjaring')) {
            $items = $items->filter(fn ($i) => $i['terjaring'])->values();
        }

        // Sort by promo_line_count DESC kalau default
        $sort = $request->input('sort', 'promo_desc');
        if ($sort === 'promo_desc') {
            $items = $items->sortByDesc('promo_line_count')->values();
        }

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Detail drill-down per customer: full breakdown.
     */
    public function showCustomer(Request $request, string $customerUlid): JsonResponse
    {
        if ($denied = $this->authorize()) return $denied;

        $customer = MasterCustomer::with(['tipeCustomer', 'kategoriCustomer'])
            ->where('ulid', $customerUlid)
            ->first();

        if (!$customer) {
            return $this->notFound('Customer tidak ditemukan.');
        }

        $promos = $this->fetchScopedPromos($request);

        $tipeDisc = $customer->tipeCustomer
            ? CustomerPromoReportResolver::formatAutoDisc($customer->tipeCustomer->diskon_tipe, (float) $customer->tipeCustomer->diskon_nilai)
            : CustomerPromoReportResolver::formatAutoDisc('none', 0);

        $katDisc = $customer->kategoriCustomer
            ? CustomerPromoReportResolver::formatAutoDisc($customer->kategoriCustomer->diskon_tipe, (float) $customer->kategoriCustomer->diskon_nilai)
            : CustomerPromoReportResolver::formatAutoDisc('none', 0);

        // Grouped promo eligible (via tipe / via kategori / global)
        $viaTipe = $promos->filter(fn ($p) => $customer->tipe_customer_id
            && (int) $p->customer_type_id === (int) $customer->tipe_customer_id);
        $viaKategori = $promos->filter(fn ($p) => $customer->kategori_customer_id
            && (int) $p->customer_category_id === (int) $customer->kategori_customer_id);
        $viaGlobal = $promos->filter(fn ($p) => $p->customer_type_id === null
            && $p->customer_category_id === null);

        return $this->success([
            'customer' => [
                'ulid' => $customer->ulid,
                'kode_customer' => $customer->kode_customer,
                'nama' => $customer->nama,
                'tipe' => $customer->tipeCustomer ? [
                    'kode' => $customer->tipeCustomer->kode_tipe,
                    'nama' => $customer->tipeCustomer->nama_tipe,
                ] : null,
                'kategori' => $customer->kategoriCustomer ? [
                    'kode' => $customer->kategoriCustomer->kode_kategori,
                    'nama' => $customer->kategoriCustomer->nama_kategori,
                ] : null,
            ],
            'disc_nota' => [
                'via_tipe' => $tipeDisc,
                'via_kategori' => $katDisc,
            ],
            'promo_line' => [
                'via_tipe' => $viaTipe->map(fn ($p) => $this->promoBrief($p))->values(),
                'via_kategori' => $viaKategori->map(fn ($p) => $this->promoBrief($p))->values(),
                'via_global' => $viaGlobal->map(fn ($p) => $this->promoBrief($p))->values(),
            ],
            'total_promo_eligible' => $viaTipe->count() + $viaKategori->count() + $viaGlobal->count(),
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

    private function fetchScopedPromos(Request $request)
    {
        $status = $request->input('status', 'active_now');

        return CustomerPromoReportResolver::fetchScopedPromos($status, brief: false);
    }

    private function promoBrief(object $promo): array
    {
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
            'scope' => [
                'tipe_customer_id' => $promo->customer_type_id,
                'kategori_customer_id' => $promo->customer_category_id,
                'is_global' => $promo->customer_type_id === null && $promo->customer_category_id === null,
            ],
        ];
    }
}
