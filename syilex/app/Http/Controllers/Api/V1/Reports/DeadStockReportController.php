<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use App\Services\Reports\DeadStockReportResolver;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Dead Stock — produk yang tidak laku dalam N hari terakhir (default 60).
 *
 * Logic:
 *  - Last sold = MAX(doc_sales.tanggal) untuk produk di doc_sales_detail (status=completed)
 *  - Dead kalau (now - last_sold) >= min_days_idle, ATAU tidak pernah terjual
 *  - Filter: minimal ada stok (qty > 0) supaya actionable
 *
 * Permission: laporan.inventory.
 */
class DeadStockReportController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.inventory')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }
        $canViewHpp = auth()->user()->can('stok.view_hpp');

        $validated = $request->validate([
            'min_days_idle' => 'nullable|integer|min:1|max:3650',
            'include_never_sold' => 'nullable|boolean',
            'kategori_id' => 'nullable|integer',
            'grup_id' => 'nullable|integer',
            'warehouse_id' => 'nullable|integer',
            'status' => 'nullable|in:active,inactive',
            'min_stock' => 'nullable|numeric|min:0',
            'sort' => 'nullable|in:days_desc,value_desc,qty_desc',
            'limit' => 'nullable|integer|min:1|max:500',
        ]);

        $result = DeadStockReportResolver::resolve([
            'min_days_idle' => $validated['min_days_idle'] ?? 60,
            'include_never_sold' => $request->boolean('include_never_sold', true),
            'kategori_id' => $validated['kategori_id'] ?? null,
            'grup_id' => $validated['grup_id'] ?? null,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'status' => $validated['status'] ?? null,
            'min_stock' => $validated['min_stock'] ?? 0.01,
            'sort' => $validated['sort'] ?? 'days_desc',
            'limit' => $validated['limit'] ?? 100,
        ], $canViewHpp);

        return $this->success([
            'cutoff_days' => $result['cutoff_days'],
            'cutoff_date' => $result['cutoff_date'],
            'total_products' => $result['total_products'],
            'total_value' => $result['total_value'],
            'can_view_hpp' => $result['can_view_hpp'],
            'items' => $result['items']->values(),
        ]);
    }
}
