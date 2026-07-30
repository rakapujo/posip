<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Reports\MarginPerBarangReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Margin per Barang — snapshot setup (bukan margin nota).
 * Non-serial: harga_N vs avg_cost. Serial: AVG unit tersedia (+ expand units[]).
 */
class MarginPerBarangReportController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user->can('laporan.keuangan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }
        if (! $user->can('stok.view_hpp')) {
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

        $filters = MarginPerBarangReportBuilder::filtersFromRequest($request);
        $perPage = (int) $request->input('per_page', 25);

        $paginator = MarginPerBarangReportBuilder::baseProductQuery($filters)->paginate($perPage);
        $items = MarginPerBarangReportBuilder::attachUnits($paginator->items());

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'filters' => [
                'price_field' => $filters['price_field'],
                'margin_bucket' => $filters['margin_bucket'],
            ],
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = auth()->user();
        if (! $user->can('laporan.keuangan') || ! $user->can('stok.view_hpp')) {
            return $this->forbidden('Akses ditolak.');
        }

        $request->validate([
            'brand_id' => 'nullable|integer',
            'tipe_id' => 'nullable|integer',
            'kategori_id' => 'nullable|integer',
            'grup_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'price_field' => 'nullable|in:harga_1,harga_2,harga_3,harga_4',
        ]);

        $filters = MarginPerBarangReportBuilder::filtersFromRequest($request);

        return $this->success(MarginPerBarangReportBuilder::summary($filters));
    }
}
