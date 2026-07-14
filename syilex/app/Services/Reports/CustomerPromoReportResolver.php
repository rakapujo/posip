<?php

namespace App\Services\Reports;

use App\Models\DocPromo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CustomerPromoReportResolver
{
    public static function fetchScopedPromos(string $status = 'active_now', bool $brief = false): Collection
    {
        $query = DocPromo::query();

        match ($status) {
            'active_now' => $query->effective(),
            'approved_all' => $query->where('status', 'approved'),
            default => $query->effective(),
        };

        $columns = $brief
            ? ['id', 'customer_type_id', 'customer_category_id']
            : [
                'id', 'ulid', 'kode_promo', 'nama_promo',
                'tanggal_mulai', 'tanggal_selesai', 'jam_mulai', 'jam_selesai',
                'customer_type_id', 'customer_category_id', 'status',
            ];

        return $query->select($columns)->get();
    }

    public static function formatAutoDiscDisplay(?string $tipe, $nilai): string
    {
        if (! $tipe || $tipe === 'none' || (float) $nilai <= 0) {
            return '-';
        }

        return $tipe === 'percent' ? "{$nilai}%" : (string) $nilai;
    }

    public static function formatAutoDisc(?string $tipe, float $nilai): array
    {
        $hasDisc = $tipe && $tipe !== 'none' && $nilai > 0;

        return [
            'has_disc' => $hasDisc,
            'tipe' => $hasDisc ? $tipe : null,
            'nilai' => $hasDisc ? $nilai : 0,
            'display' => $hasDisc
                ? ($tipe === 'percent' ? "{$nilai}%" : 'Rp '.number_format($nilai, 0, ',', '.'))
                : '-',
        ];
    }

    public static function eligiblePromosFor(?int $tipeId, ?int $kategoriId, Collection $promos): Collection
    {
        return $promos->filter(function ($p) use ($tipeId, $kategoriId) {
            if ($p->customer_type_id === null && $p->customer_category_id === null) {
                return true;
            }
            if ($p->customer_type_id !== null && (int) $p->customer_type_id === (int) $tipeId) {
                return true;
            }
            if ($p->customer_category_id !== null && (int) $p->customer_category_id === (int) $kategoriId) {
                return true;
            }

            return false;
        });
    }

    public static function eligiblePromosForTipe(object $tipe, Collection $promos): Collection
    {
        return $promos->filter(function ($p) use ($tipe) {
            if ($p->customer_category_id !== null) {
                return false;
            }

            return $p->customer_type_id === null || (int) $p->customer_type_id === (int) $tipe->id;
        });
    }

    public static function eligiblePromosForKategori(object $kategori, Collection $promos): Collection
    {
        return $promos->filter(function ($p) use ($kategori) {
            if ($p->customer_type_id !== null) {
                return false;
            }

            return $p->customer_category_id === null || (int) $p->customer_category_id === (int) $kategori->id;
        });
    }

    public static function countTerjaringCustomers(Collection $promos): int
    {
        return DB::table('master_customer as c')
            ->leftJoin('master_tipe_customer as t', 't.id', '=', 'c.tipe_customer_id')
            ->leftJoin('master_kategori_customer as k', 'k.id', '=', 'c.kategori_customer_id')
            ->whereNull('c.deleted_at')
            ->select(
                'c.id', 'c.tipe_customer_id', 'c.kategori_customer_id',
                't.diskon_tipe as t_tipe', 't.diskon_nilai as t_nilai',
                'k.diskon_tipe as k_tipe', 'k.diskon_nilai as k_nilai'
            )
            ->get()
            ->filter(function ($c) use ($promos) {
                $hasTipeDisc = $c->t_tipe && $c->t_tipe !== 'none' && (float) $c->t_nilai > 0;
                $hasKatDisc = $c->k_tipe && $c->k_tipe !== 'none' && (float) $c->k_nilai > 0;
                $hasLine = self::eligiblePromosFor($c->tipe_customer_id, $c->kategori_customer_id, $promos)->isNotEmpty();

                return $hasTipeDisc || $hasKatDisc || $hasLine;
            })
            ->count();
    }

    /**
     * @return Collection<int, object>
     */
    public static function summaryExportRows(string $status = 'active_now'): Collection
    {
        $promos = self::fetchScopedPromos($status, brief: true);

        $tipeTotal = DB::table('master_tipe_customer')->count();
        $tipeWithDisc = DB::table('master_tipe_customer')
            ->where('diskon_tipe', '!=', 'none')
            ->where('diskon_nilai', '>', 0)
            ->count();

        $katTotal = DB::table('master_kategori_customer')->count();
        $katWithDisc = DB::table('master_kategori_customer')
            ->where('diskon_tipe', '!=', 'none')
            ->where('diskon_nilai', '>', 0)
            ->count();

        $totalCustomer = DB::table('master_customer')->whereNull('deleted_at')->count();
        $terjaring = self::countTerjaringCustomers($promos);

        return collect([
            (object) ['metric' => 'Tipe dengan disc nota', 'value' => "{$tipeWithDisc} / {$tipeTotal}"],
            (object) ['metric' => 'Kategori dengan disc nota', 'value' => "{$katWithDisc} / {$katTotal}"],
            (object) ['metric' => 'Promo aktif (scoped)', 'value' => (string) $promos->count()],
            (object) ['metric' => 'Customer terjaring', 'value' => "{$terjaring} / {$totalCustomer}"],
        ]);
    }

    /**
     * @return Collection<int, object>
     */
    public static function byTipeExportRows(string $status = 'active_now'): Collection
    {
        $promos = self::fetchScopedPromos($status, brief: true);

        return DB::table('master_tipe_customer')
            ->select('id', 'kode_tipe', 'nama_tipe', 'diskon_tipe', 'diskon_nilai')
            ->orderBy('kode_tipe')
            ->get()
            ->map(function ($t) use ($promos) {
                $customerCount = DB::table('master_customer')
                    ->whereNull('deleted_at')
                    ->where('tipe_customer_id', $t->id)
                    ->count();

                $eligible = self::eligiblePromosForTipe($t, $promos);

                return (object) [
                    'kode_tipe' => $t->kode_tipe,
                    'nama_tipe' => $t->nama_tipe,
                    'disc_nota' => self::formatAutoDiscDisplay($t->diskon_tipe, $t->diskon_nilai),
                    'customer_count' => $customerCount,
                    'promo_count' => $eligible->count(),
                ];
            });
    }

    /**
     * @return Collection<int, object>
     */
    public static function byKategoriExportRows(string $status = 'active_now'): Collection
    {
        $promos = self::fetchScopedPromos($status, brief: true);

        return DB::table('master_kategori_customer')
            ->select('id', 'kode_kategori', 'nama_kategori', 'diskon_tipe', 'diskon_nilai')
            ->orderBy('kode_kategori')
            ->get()
            ->map(function ($k) use ($promos) {
                $customerCount = DB::table('master_customer')
                    ->whereNull('deleted_at')
                    ->where('kategori_customer_id', $k->id)
                    ->count();

                $eligible = self::eligiblePromosForKategori($k, $promos);

                return (object) [
                    'kode_kategori' => $k->kode_kategori,
                    'nama_kategori' => $k->nama_kategori,
                    'disc_nota' => self::formatAutoDiscDisplay($k->diskon_tipe, $k->diskon_nilai),
                    'customer_count' => $customerCount,
                    'promo_count' => $eligible->count(),
                ];
            });
    }
}
