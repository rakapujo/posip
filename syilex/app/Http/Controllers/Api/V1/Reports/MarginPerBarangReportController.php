<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Margin per Barang — snapshot margin dari harga jual (harga_4 = default retail)
 * vs avg_cost. Bukan margin aktual per transaksi, tapi margin setup saat ini.
 *
 * Butuh permission: laporan.keuangan + stok.view_hpp.
 */
class MarginPerBarangReportController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user->can('laporan.keuangan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }
        if (!$user->can('stok.view_hpp')) {
            return $this->forbidden('Laporan margin butuh permission stok.view_hpp.');
        }

        $request->validate([
            'brand_id' => 'nullable|integer',
            'tipe_id' => 'nullable|integer',
            'kategori_id' => 'nullable|integer',
            'grup_id' => 'nullable|integer',
            'margin_bucket' => 'nullable|in:low,medium,high,any',
            'status' => 'nullable|in:active,inactive',
            'price_field' => 'nullable|in:harga_1,harga_2,harga_3,harga_4',
            'search' => 'nullable|string|max:100',
            'sort' => 'nullable|in:margin_asc,margin_desc,nama_asc,kode_asc',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        $priceField = $request->input('price_field', 'harga_4');
        $perPage = (int) $request->input('per_page', 25);

        $q = DB::table('master_produk as p')
            ->leftJoin('master_brand as b', 'b.id', '=', 'p.brand_id')
            ->leftJoin('master_tipe as t', 't.id', '=', 'p.tipe_id')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->leftJoin('master_grup as g', 'g.id', '=', 'p.grup_id')
            ->whereNull('p.deleted_at')
            ->select(
                'p.ulid',
                'p.kode_produk',
                'p.nama_produk',
                'p.status',
                'p.avg_cost',
                "p.{$priceField} as harga_jual",
                'b.nama_brand',
                't.nama_tipe',
                'k.nama_kategori',
                'g.nama_grup',
                DB::raw("(p.{$priceField} - p.avg_cost) as margin_nominal"),
                DB::raw("CASE WHEN p.{$priceField} > 0 THEN ROUND(((p.{$priceField} - p.avg_cost) * 1.0 / p.{$priceField}) * 100, 2) ELSE 0 END as margin_percent")
            );

        if ($request->filled('brand_id')) {
            $q->where('p.brand_id', $request->brand_id);
        }
        if ($request->filled('tipe_id')) {
            $q->where('p.tipe_id', $request->tipe_id);
        }
        if ($request->filled('kategori_id')) {
            $q->where('p.kategori_id', $request->kategori_id);
        }
        if ($request->filled('grup_id')) {
            $q->where('p.grup_id', $request->grup_id);
        }
        if ($request->filled('status')) {
            $q->where('p.status', $request->status);
        }
        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('p.kode_produk', 'like', "%{$s}%")
                   ->orWhere('p.nama_produk', 'like', "%{$s}%");
            });
        }

        $marginExpr = "(CASE WHEN p.{$priceField} > 0 THEN ((p.{$priceField} - p.avg_cost) * 1.0 / p.{$priceField}) * 100 ELSE 0 END)";

        // Margin bucket (pakai raw expression, bukan alias — agar kompatibel SQLite)
        $bucket = $request->input('margin_bucket', 'any');
        if ($bucket !== 'any') {
            $q->whereRaw($marginExpr . ' ' . match ($bucket) {
                'low' => '< 10',
                'medium' => 'BETWEEN 10 AND 20',
                'high' => '> 20',
            });
        }

        // Sort (pakai raw untuk margin — alias tidak reliable di semua driver)
        $sort = $request->input('sort', 'margin_asc');
        match ($sort) {
            'margin_desc' => $q->orderByRaw($marginExpr . ' DESC'),
            'nama_asc' => $q->orderBy('p.nama_produk'),
            'kode_asc' => $q->orderBy('p.kode_produk'),
            default => $q->orderByRaw($marginExpr . ' ASC'),
        };

        $paginator = $q->paginate($perPage);

        return $this->success([
            'items' => $paginator->items(),
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'price_field' => $priceField,
                'margin_bucket' => $bucket,
            ],
        ]);
    }

    /**
     * Summary: jumlah produk per bucket margin.
     */
    public function summary(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (!$user->can('laporan.keuangan') || !$user->can('stok.view_hpp')) {
            return $this->forbidden('Akses ditolak.');
        }

        $priceField = $request->input('price_field', 'harga_4');

        $row = DB::table('master_produk as p')
            ->whereNull('p.deleted_at')
            ->where('p.status', 'active')
            ->select(
                DB::raw("COUNT(*) as total_produk"),
                DB::raw("SUM(CASE WHEN p.{$priceField} <= 0 THEN 1 ELSE 0 END) as tanpa_harga"),
                DB::raw("SUM(CASE WHEN p.{$priceField} > 0 AND ((p.{$priceField} - p.avg_cost) * 1.0 / p.{$priceField}) * 100 < 10 THEN 1 ELSE 0 END) as margin_rendah"),
                DB::raw("SUM(CASE WHEN p.{$priceField} > 0 AND ((p.{$priceField} - p.avg_cost) * 1.0 / p.{$priceField}) * 100 BETWEEN 10 AND 20 THEN 1 ELSE 0 END) as margin_sedang"),
                DB::raw("SUM(CASE WHEN p.{$priceField} > 0 AND ((p.{$priceField} - p.avg_cost) * 1.0 / p.{$priceField}) * 100 > 20 THEN 1 ELSE 0 END) as margin_tinggi"),
                DB::raw("SUM(CASE WHEN p.avg_cost > 0 AND p.{$priceField} > 0 AND p.{$priceField} < p.avg_cost THEN 1 ELSE 0 END) as rugi_margin")
            )
            ->first();

        return $this->success([
            'price_field' => $priceField,
            'total_produk' => (int) $row->total_produk,
            'tanpa_harga' => (int) $row->tanpa_harga,
            'margin_rendah' => (int) $row->margin_rendah,
            'margin_sedang' => (int) $row->margin_sedang,
            'margin_tinggi' => (int) $row->margin_tinggi,
            'rugi_margin' => (int) $row->rugi_margin,
        ]);
    }
}
