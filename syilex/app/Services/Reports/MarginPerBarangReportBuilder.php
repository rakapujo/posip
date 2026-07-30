<?php

namespace App\Services\Reports;

use App\Models\SerialUnit;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Shared query path for Margin per Barang (list, summary, Excel).
 * Non-serial: master harga_N vs avg_cost.
 * Serial: AVG(serial_units.harga_jual/cost_per_unit) status=tersedia.
 */
class MarginPerBarangReportBuilder
{
    public static function filtersFromRequest(Request $request): array
    {
        return [
            'price_field' => $request->input('price_field', 'harga_4'),
            'margin_bucket' => $request->input('margin_bucket', 'any'),
            'sort' => $request->input('sort', 'nama_asc'),
            'search' => $request->filled('search') ? (string) $request->search : null,
            // null = no status filter (parity list/export)
            'status' => $request->filled('status') ? (string) $request->status : null,
            'brand_id' => $request->filled('brand_id') ? (int) $request->brand_id : null,
            'tipe_id' => $request->filled('tipe_id') ? (int) $request->tipe_id : null,
            'kategori_id' => $request->filled('kategori_id') ? (int) $request->kategori_id : null,
            'grup_id' => $request->filled('grup_id') ? (int) $request->grup_id : null,
        ];
    }

    /** Effective sell price: serial AVG unit harga_jual, else master price field. */
    public static function hargaExpr(string $priceField): string
    {
        return "(CASE WHEN p.is_serial = 1 THEN COALESCE(su.avg_harga_jual, 0) ELSE p.{$priceField} END)";
    }

    public static function costExpr(): string
    {
        return '(CASE WHEN p.is_serial = 1 THEN COALESCE(su.avg_cost_per_unit, 0) ELSE p.avg_cost END)';
    }

    public static function marginPercentExpr(string $priceField): string
    {
        $h = self::hargaExpr($priceField);
        $c = self::costExpr();

        return "(CASE WHEN {$h} > 0 THEN ROUND((({$h} - {$c}) * 1.0 / {$h}) * 100, 2) ELSE 0 END)";
    }

    public static function marginNominalExpr(string $priceField): string
    {
        return '('.self::hargaExpr($priceField).' - '.self::costExpr().')';
    }

    public static function baseProductQuery(array $filters, bool $withLookups = true): Builder
    {
        $priceField = $filters['price_field'] ?? 'harga_4';

        $unitAgg = DB::table('serial_units')
            ->whereNull('deleted_at')
            ->where('status', SerialUnit::STATUS_TERSEDIA)
            ->groupBy('product_id')
            ->select(
                'product_id',
                DB::raw('AVG(harga_jual) as avg_harga_jual'),
                DB::raw('AVG(cost_per_unit) as avg_cost_per_unit'),
                DB::raw('COUNT(*) as unit_count')
            );

        $q = DB::table('master_produk as p')
            ->leftJoinSub($unitAgg, 'su', 'su.product_id', '=', 'p.id')
            ->whereNull('p.deleted_at');

        if ($withLookups) {
            $q->leftJoin('master_brand as b', 'b.id', '=', 'p.brand_id')
                ->leftJoin('master_tipe as t', 't.id', '=', 'p.tipe_id')
                ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
                ->leftJoin('master_grup as g', 'g.id', '=', 'p.grup_id');
        } else {
            $q->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id');
        }

        self::applyFilters($q, $filters);

        $harga = self::hargaExpr($priceField);
        $cost = self::costExpr();
        $marginPct = self::marginPercentExpr($priceField);
        $marginNom = self::marginNominalExpr($priceField);

        $select = [
            'p.id',
            'p.ulid',
            'p.kode_produk',
            'p.nama_produk',
            'p.status',
            'p.is_serial',
            'k.nama_kategori',
            DB::raw("{$cost} as avg_cost"),
            DB::raw("{$harga} as harga_jual"),
            DB::raw("{$marginNom} as margin_nominal"),
            DB::raw("{$marginPct} as margin_percent"),
            DB::raw('CASE WHEN p.is_serial = 1 THEN COALESCE(su.unit_count, 0) ELSE 0 END as unit_count'),
        ];

        if ($withLookups) {
            $select[] = 'b.nama_brand';
            $select[] = 't.nama_tipe';
            $select[] = 'g.nama_grup';
        }

        $q->select($select);

        $bucket = $filters['margin_bucket'] ?? 'any';
        if ($bucket !== 'any') {
            $q->whereRaw($marginPct.' '.match ($bucket) {
                'low' => '< 10',
                'medium' => 'BETWEEN 10 AND 20',
                'high' => '> 20',
                default => '>= 0',
            });
        }

        $sort = $filters['sort'] ?? 'nama_asc';
        match ($sort) {
            'margin_desc' => $q->orderByRaw($marginPct.' DESC'),
            'margin_asc' => $q->orderByRaw($marginPct.' ASC'),
            'kode_asc' => $q->orderBy('p.kode_produk'),
            default => $q->orderBy('p.nama_produk'),
        };

        return $q;
    }

    public static function applyFilters(Builder $q, array $filters): void
    {
        if (! empty($filters['status'])) {
            $q->where('p.status', $filters['status']);
        }
        if (! empty($filters['brand_id'])) {
            $q->where('p.brand_id', $filters['brand_id']);
        }
        if (! empty($filters['tipe_id'])) {
            $q->where('p.tipe_id', $filters['tipe_id']);
        }
        if (! empty($filters['kategori_id'])) {
            $q->where('p.kategori_id', $filters['kategori_id']);
        }
        if (! empty($filters['grup_id'])) {
            $q->where('p.grup_id', $filters['grup_id']);
        }
        if (! empty($filters['search'])) {
            $s = $filters['search'];
            $q->where(function ($qq) use ($s) {
                $qq->where('p.kode_produk', 'like', "%{$s}%")
                    ->orWhere('p.nama_produk', 'like', "%{$s}%");
            });
        }
    }

    /**
     * Attach units[] for serial rows on the current page (1 query).
     *
     * @param  array<int, object>  $items
     * @return array<int, object>
     */
    public static function attachUnits(array $items): array
    {
        $serialIds = [];
        foreach ($items as $row) {
            if ((int) ($row->is_serial ?? 0) === 1) {
                $serialIds[] = (int) $row->id;
            }
        }

        $unitsByProduct = collect();
        if ($serialIds !== []) {
            $unitsByProduct = DB::table('serial_units')
                ->whereNull('deleted_at')
                ->where('status', SerialUnit::STATUS_TERSEDIA)
                ->whereIn('product_id', $serialIds)
                ->orderBy('kode_internal')
                ->get([
                    'ulid',
                    'product_id',
                    'kode_internal',
                    'serial_number',
                    'status',
                    'cost_per_unit',
                    'harga_jual',
                ])
                ->groupBy('product_id');
        }

        return array_map(function ($row) use ($unitsByProduct) {
            $isSerial = (bool) ($row->is_serial ?? false);
            $harga = (float) $row->harga_jual;
            $cost = (float) $row->avg_cost;
            $pct = (float) $row->margin_percent;
            $tanpaHarga = $harga <= 0;

            $units = [];
            if ($isSerial) {
                $raw = $unitsByProduct->get((int) $row->id, collect());
                foreach ($raw as $u) {
                    $uh = (float) $u->harga_jual;
                    $uc = (float) $u->cost_per_unit;
                    $uTanpa = $uh <= 0;
                    $units[] = (object) [
                        'ulid' => $u->ulid,
                        'kode_internal' => $u->kode_internal,
                        'serial_number' => $u->serial_number,
                        'status' => $u->status,
                        'cost_per_unit' => $uc,
                        'harga_jual' => $uh,
                        'margin_nominal' => round($uh - $uc, 4),
                        'margin_percent' => $uTanpa ? 0.0 : round((($uh - $uc) / $uh) * 100, 2),
                        'tanpa_harga' => $uTanpa,
                    ];
                }
            }

            return (object) [
                'ulid' => $row->ulid,
                'kode_produk' => $row->kode_produk,
                'nama_produk' => $row->nama_produk,
                'status' => $row->status,
                'is_serial' => $isSerial,
                'unit_count' => (int) ($row->unit_count ?? count($units)),
                'avg_cost' => $cost,
                'harga_jual' => $harga,
                'margin_nominal' => (float) $row->margin_nominal,
                'margin_percent' => $pct,
                'tanpa_harga' => $tanpaHarga,
                'nama_brand' => $row->nama_brand ?? null,
                'nama_tipe' => $row->nama_tipe ?? null,
                'nama_kategori' => $row->nama_kategori ?? null,
                'nama_grup' => $row->nama_grup ?? null,
                'units' => $units,
            ];
        }, $items);
    }

    public static function summary(array $filters): array
    {
        $priceField = $filters['price_field'] ?? 'harga_4';
        $harga = self::hargaExpr($priceField);
        $cost = self::costExpr();
        $pct = "(CASE WHEN {$harga} > 0 THEN (({$harga} - {$cost}) * 1.0 / {$harga}) * 100 ELSE 0 END)";

        $unitAgg = DB::table('serial_units')
            ->whereNull('deleted_at')
            ->where('status', SerialUnit::STATUS_TERSEDIA)
            ->groupBy('product_id')
            ->select(
                'product_id',
                DB::raw('AVG(harga_jual) as avg_harga_jual'),
                DB::raw('AVG(cost_per_unit) as avg_cost_per_unit'),
                DB::raw('COUNT(*) as unit_count')
            );

        $q = DB::table('master_produk as p')
            ->leftJoinSub($unitAgg, 'su', 'su.product_id', '=', 'p.id')
            ->whereNull('p.deleted_at');

        // Summary cards: active only unless status filter set
        $summaryFilters = $filters;
        if (empty($summaryFilters['status'])) {
            $summaryFilters['status'] = 'active';
        }
        self::applyFilters($q, $summaryFilters);

        $row = $q->select(
            DB::raw('COUNT(*) as total_produk'),
            DB::raw("SUM(CASE WHEN {$harga} <= 0 THEN 1 ELSE 0 END) as tanpa_harga"),
            DB::raw("SUM(CASE WHEN {$harga} > 0 AND {$pct} < 10 THEN 1 ELSE 0 END) as margin_rendah"),
            DB::raw("SUM(CASE WHEN {$harga} > 0 AND {$pct} BETWEEN 10 AND 20 THEN 1 ELSE 0 END) as margin_sedang"),
            DB::raw("SUM(CASE WHEN {$harga} > 0 AND {$pct} > 20 THEN 1 ELSE 0 END) as margin_tinggi"),
            DB::raw("SUM(CASE WHEN {$cost} > 0 AND {$harga} > 0 AND {$harga} < {$cost} THEN 1 ELSE 0 END) as rugi_margin")
        )->first();

        return [
            'price_field' => $priceField,
            'total_produk' => (int) $row->total_produk,
            'tanpa_harga' => (int) $row->tanpa_harga,
            'margin_rendah' => (int) $row->margin_rendah,
            'margin_sedang' => (int) $row->margin_sedang,
            'margin_tinggi' => (int) $row->margin_tinggi,
            'rugi_margin' => (int) $row->rugi_margin,
        ];
    }

    /**
     * Flat rows for Excel/PDF: RETAIL 1×, SERIAL 1× per tersedia unit.
     *
     * @return Collection<int, object>
     */
    public static function flatExportRows(array $filters): Collection
    {
        $products = self::baseProductQuery($filters, withLookups: false)->get();
        $serialIds = $products->where('is_serial', 1)->pluck('id')->all();

        $unitsByProduct = collect();
        if ($serialIds !== []) {
            $unitsByProduct = DB::table('serial_units')
                ->whereNull('deleted_at')
                ->where('status', SerialUnit::STATUS_TERSEDIA)
                ->whereIn('product_id', $serialIds)
                ->orderBy('kode_internal')
                ->get()
                ->groupBy('product_id');
        }

        $rows = collect();
        foreach ($products as $p) {
            if ((int) $p->is_serial === 1) {
                $units = $unitsByProduct->get((int) $p->id, collect());
                if ($units->isEmpty()) {
                    continue;
                }
                $rows->push((object) [
                    'tipe' => 'SERIAL',
                    'kode_produk' => $p->kode_produk,
                    'nama_produk' => $p->nama_produk,
                    'nama_kategori' => $p->nama_kategori,
                    'kode_internal' => '-',
                    'serial_number' => '-',
                    'avg_cost' => (float) $p->avg_cost,
                    'harga_jual' => (float) $p->harga_jual,
                    'margin_nominal' => (float) $p->margin_nominal,
                    'margin_percent' => (float) $p->margin_percent,
                    'tanpa_harga' => property_exists($p, 'tanpa_harga') ? (bool) $p->tanpa_harga : ((float) $p->harga_jual <= 0),
                ]);
                foreach ($units as $u) {
                    $uh = (float) $u->harga_jual;
                    $uc = (float) $u->cost_per_unit;
                    $uTanpa = $uh <= 0;
                    $rows->push((object) [
                        'tipe' => 'UNIT',
                        'kode_produk' => $p->kode_produk,
                        'nama_produk' => $p->nama_produk,
                        'nama_kategori' => $p->nama_kategori,
                        'kode_internal' => $u->kode_internal ?? '-',
                        'serial_number' => $u->serial_number ?? '-',
                        'avg_cost' => $uc,
                        'harga_jual' => $uh,
                        'margin_nominal' => round($uh - $uc, 4),
                        'margin_percent' => $uTanpa ? 0.0 : round((($uh - $uc) / $uh) * 100, 2),
                        'tanpa_harga' => $uTanpa,
                    ]);
                }
            } else {
                $harga = (float) $p->harga_jual;
                $rows->push((object) [
                    'tipe' => 'RETAIL',
                    'kode_produk' => $p->kode_produk,
                    'nama_produk' => $p->nama_produk,
                    'nama_kategori' => $p->nama_kategori,
                    'kode_internal' => '-',
                    'serial_number' => '-',
                    'avg_cost' => (float) $p->avg_cost,
                    'harga_jual' => $harga,
                    'margin_nominal' => (float) $p->margin_nominal,
                    'margin_percent' => (float) $p->margin_percent,
                    'tanpa_harga' => $harga <= 0,
                ]);
            }
        }

        return $rows;
    }
}
