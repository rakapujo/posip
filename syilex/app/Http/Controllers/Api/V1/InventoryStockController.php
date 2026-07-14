<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Exports\InventoryStockExport;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class InventoryStockController extends BaseApiController
{
    /**
     * Display a listing of inventory stocks (grouped by product).
     */
    public function index(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('stok.view')) {
            return $this->error('Unauthorized', 403);
        }

        $canViewHpp = auth()->user()->can('stok.view_hpp');

        // Get warehouses for filter dropdown
        $warehouses = MasterWarehouse::active()
            ->select('id', 'ulid', 'kode_warehouse', 'nama_warehouse')
            ->orderBy('nama_warehouse')
            ->get()
            ->makeVisible('id');

        // Build query for products with stock
        $warehouseId = $request->input('warehouse_id');

        $query = MasterProduk::query()
            ->with(['brand:id,kode_brand,nama_brand'])
            ->withCount(['inventoryStocks as total_warehouses']);

        // If specific warehouse is selected, filter stocks and aggregates
        if ($warehouseId) {
            // Load only stocks for selected warehouse with warehouse relation
            $query->with(['inventoryStocks' => function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId)
                  ->with('warehouse:id,ulid,kode_warehouse,nama_warehouse');
            }]);
            // Sum qty only for selected warehouse
            $query->withSum(['inventoryStocks as inventory_stocks_sum_qty' => function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            }], 'qty');
            // Only show products that have stock in selected warehouse
            $query->whereHas('inventoryStocks', function ($q) use ($warehouseId) {
                $q->where('warehouse_id', $warehouseId);
            });
        } else {
            // Load all stocks with warehouse relation
            $query->with(['inventoryStocks.warehouse:id,ulid,kode_warehouse,nama_warehouse']);
            // Sum qty from all warehouses
            $query->withSum('inventoryStocks', 'qty');
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('kode_produk', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%")
                  ->orWhere('nama_produk', 'like', "%{$search}%");
            });
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        } else {
            // Default: only active products
            $query->where('status', 'active');
        }

        // Filter low stock only
        if ($request->boolean('low_stock')) {
            $query->whereHas('inventoryStocks', function ($q) {
                $q->whereColumn('qty', '<', 'master_produk.minimum_stok');
            });
        }

        // Sort (whitelist allowed columns)
        $sortableFields = ['kode_produk', 'barcode', 'nama_produk', 'status', 'total_qty'];
        $sortField = $request->input('sort_field', 'kode_produk');
        $sortOrder = $request->input('sort_order', 'asc') === 'asc' ? 'asc' : 'desc';

        // Handle special sort cases
        if ($sortField === 'total_qty') {
            $query->orderBy('inventory_stocks_sum_qty', $sortOrder);
        } elseif (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('kode_produk', $sortOrder);
        }

        // Paginate
        $perPage = $this->getPerPage($request);
        $products = $query->paginate($perPage);

        // Transform response
        $items = $products->getCollection()->map(function ($product) use ($canViewHpp, $warehouseId) {
            $data = [
                'ulid' => $product->ulid,
                'kode_produk' => $product->kode_produk,
                'barcode' => $product->barcode,
                'is_serial' => (bool) $product->is_serial,
                'nama_produk' => $product->nama_produk,
                'brand' => $product->brand ? [
                    'kode_brand' => $product->brand->kode_brand,
                    'nama_brand' => $product->brand->nama_brand,
                ] : null,
                'minimum_stok' => $product->minimum_stok,
                'total_qty' => (int) $product->inventory_stocks_sum_qty ?? 0,
                'total_warehouses' => $product->total_warehouses ?? 0,
                'status' => $product->status,
                // Unit data for hierarchy and breakdown display
                'unit_1' => $product->unit_1,
                'unit_2' => $product->unit_2,
                'unit_3' => $product->unit_3,
                'unit_4' => $product->unit_4,
                'konversi_1' => $product->konversi_1,
                'konversi_2' => $product->konversi_2,
                'konversi_3' => $product->konversi_3,
                'konversi_4' => $product->konversi_4,
                // Global HPP (avg_cost is global, not per-warehouse)
                'avg_cost' => $canViewHpp ? (float) $product->avg_cost : null,
            ];

            // Add warehouse stocks for expansion
            if ($warehouseId) {
                // Single warehouse
                $stock = $product->inventoryStocks->first();
                $data['stocks'] = $stock ? [[
                    'warehouse_id' => $stock->warehouse_id,
                    'warehouse_ulid' => $stock->warehouse?->ulid,
                    'warehouse_kode' => $stock->warehouse?->kode_warehouse,
                    'warehouse_nama' => $stock->warehouse?->nama_warehouse,
                    'qty' => $stock->qty,
                    'avg_cost' => $canViewHpp ? $stock->avg_cost : null,
                    'total_value' => $canViewHpp ? $stock->total_value : null,
                    'is_low_stock' => $stock->qty < $product->minimum_stok,
                ]] : [];
            } else {
                // All warehouses
                $data['stocks'] = $product->inventoryStocks->map(function ($stock) use ($canViewHpp, $product) {
                    return [
                        'warehouse_id' => $stock->warehouse_id,
                        'warehouse_ulid' => $stock->warehouse?->ulid,
                        'warehouse_kode' => $stock->warehouse?->kode_warehouse,
                        'warehouse_nama' => $stock->warehouse?->nama_warehouse,
                        'qty' => $stock->qty,
                        'avg_cost' => $canViewHpp ? $stock->avg_cost : null,
                        'total_value' => $canViewHpp ? $stock->total_value : null,
                        'is_low_stock' => $stock->qty < $product->minimum_stok,
                    ];
                })->values();
            }

            // Calculate total value if user can view HPP
            if ($canViewHpp) {
                $data['total_value'] = $product->inventoryStocks->sum(function ($stock) {
                    return $stock->qty * $stock->avg_cost;
                });
            }

            // Check if any stock is low
            $data['has_low_stock'] = $product->inventoryStocks->contains(function ($stock) use ($product) {
                return $stock->qty < $product->minimum_stok;
            });

            return $data;
        });

        return $this->success([
            'products' => $items,
            'warehouses' => $warehouses,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
            'can_view_hpp' => $canViewHpp,
        ]);
    }

    /**
     * Get stock details for a specific product (all warehouses).
     */
    public function showByProduct(string $ulid): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('stok.view')) {
            return $this->error('Unauthorized', 403);
        }

        $canViewHpp = auth()->user()->can('stok.view_hpp');

        $product = MasterProduk::with([
            'brand:id,kode_brand,nama_brand',
            'inventoryStocks.warehouse:id,ulid,kode_warehouse,nama_warehouse,status',
        ])->where('ulid', $ulid)->first();

        if (!$product) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        $stocks = $product->inventoryStocks->map(function ($stock) use ($canViewHpp, $product) {
            return [
                'warehouse_ulid' => $stock->warehouse->ulid,
                'warehouse_kode' => $stock->warehouse->kode_warehouse,
                'warehouse_nama' => $stock->warehouse->nama_warehouse,
                'warehouse_status' => $stock->warehouse->status,
                'qty' => $stock->qty,
                'avg_cost' => $canViewHpp ? $stock->avg_cost : null,
                'total_value' => $canViewHpp ? $stock->total_value : null,
                'is_low_stock' => $stock->qty < $product->minimum_stok,
                'updated_at' => $stock->updated_at,
            ];
        })->sortBy('warehouse_kode')->values();

        $totalQty = $product->inventoryStocks->sum('qty');
        $totalValue = $canViewHpp ? $product->inventoryStocks->sum(function ($stock) {
            return $stock->qty * $stock->avg_cost;
        }) : null;

        return $this->success([
            'product' => [
                'ulid' => $product->ulid,
                'kode_produk' => $product->kode_produk,
                'barcode' => $product->barcode,
                'is_serial' => (bool) $product->is_serial,
                'nama_produk' => $product->nama_produk,
                'brand' => $product->brand ? [
                    'kode_brand' => $product->brand->kode_brand,
                    'nama_brand' => $product->brand->nama_brand,
                ] : null,
                'minimum_stok' => $product->minimum_stok,
                'gambar_url' => $product->gambar_url,
                // Global HPP (avg_cost is global, not per-warehouse)
                'avg_cost' => $canViewHpp ? (float) $product->avg_cost : null,
                // Unit data for hierarchy and breakdown display
                'unit_1' => $product->unit_1,
                'unit_2' => $product->unit_2,
                'unit_3' => $product->unit_3,
                'unit_4' => $product->unit_4,
                'konversi_1' => $product->konversi_1,
                'konversi_2' => $product->konversi_2,
                'konversi_3' => $product->konversi_3,
                'konversi_4' => $product->konversi_4,
            ],
            'stocks' => $stocks,
            'summary' => [
                'total_qty' => $totalQty,
                'total_value' => $totalValue,
                'total_warehouses' => $stocks->count(),
                'low_stock_warehouses' => $stocks->where('is_low_stock', true)->count(),
            ],
            'can_view_hpp' => $canViewHpp,
        ]);
    }

    /**
     * Get summary of inventory stocks.
     */
    public function summary(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('stok.view')) {
            return $this->error('Unauthorized', 403);
        }

        $canViewHpp = auth()->user()->can('stok.view_hpp');

        $warehouseId = $request->input('warehouse_id');

        $query = InventoryStock::query()
            ->activeProduct()
            ->activeWarehouse();

        if ($warehouseId) {
            $query->where('warehouse_id', $warehouseId);
        }

        $totalItems = MasterProduk::active()->count();
        $totalWarehouses = MasterWarehouse::active()->count();

        // Get all stocks for calculations
        $stocks = $query->with('product:id,minimum_stok')->get();

        $totalQty = $stocks->sum('qty');
        $totalValue = $canViewHpp ? $stocks->sum(function ($stock) {
            return $stock->qty * $stock->avg_cost;
        }) : null;

        $lowStockCount = $stocks->filter(function ($stock) {
            return $stock->product && $stock->qty < $stock->product->minimum_stok;
        })->count();

        $negativeStockCount = $stocks->where('qty', '<', 0)->count();

        return $this->success([
            'summary' => [
                'total_products' => $totalItems,
                'total_warehouses' => $totalWarehouses,
                'total_qty' => $totalQty,
                'total_value' => $totalValue,
                'low_stock_count' => $lowStockCount,
                'negative_stock_count' => $negativeStockCount,
            ],
            'can_view_hpp' => $canViewHpp,
        ]);
    }

    /**
     * Valuation per warehouse — qty × avg_cost per warehouse.
     * Butuh permission stok.view_hpp untuk expose nominal.
     */
    public function valuationByWarehouse(Request $request): JsonResponse
    {
        if (!auth()->user()->can('stok.view')) {
            return $this->error('Unauthorized', 403);
        }
        if (!auth()->user()->can('stok.view_hpp')) {
            return $this->forbidden('Akses valuation butuh permission stok.view_hpp.');
        }

        $rows = \DB::table('inventory_stock as s')
            ->join('master_warehouse as w', 'w.id', '=', 's.warehouse_id')
            ->join('master_produk as p', 'p.id', '=', 's.product_id')
            ->where('w.status', 'active')
            ->where('p.status', 'active')
            ->whereNull('p.deleted_at')
            ->select(
                'w.id as warehouse_id',
                'w.ulid as warehouse_ulid',
                'w.kode_warehouse',
                'w.nama_warehouse',
                \DB::raw('COALESCE(SUM(s.qty), 0) as qty_total'),
                \DB::raw('COALESCE(SUM(s.qty * p.avg_cost), 0) as value_total'),
                \DB::raw('COUNT(DISTINCT CASE WHEN s.qty != 0 THEN s.product_id END) as product_count')
            )
            ->groupBy('w.id', 'w.ulid', 'w.kode_warehouse', 'w.nama_warehouse')
            ->orderBy('w.nama_warehouse')
            ->get();

        $grandTotal = $rows->sum('value_total');
        $grandQty = $rows->sum('qty_total');

        return $this->success([
            'grand_total_value' => (float) $grandTotal,
            'grand_total_qty' => (float) $grandQty,
            'items' => $rows->map(fn ($r) => [
                'warehouse_id' => $r->warehouse_id,
                'warehouse_ulid' => $r->warehouse_ulid,
                'kode_warehouse' => $r->kode_warehouse,
                'nama_warehouse' => $r->nama_warehouse,
                'qty_total' => (float) $r->qty_total,
                'value_total' => (float) $r->value_total,
                'product_count' => (int) $r->product_count,
                'percent' => $grandTotal > 0
                    ? round(((float) $r->value_total / $grandTotal) * 100, 2)
                    : 0,
            ])->values(),
        ]);
    }

    /**
     * Export inventory stocks to Excel (flat view: 1 product per warehouse).
     */
    public function export(Request $request)
    {
        // Check permission
        if (!auth()->user()->can('stok.view')) {
            return $this->error('Unauthorized', 403);
        }

        $canViewHpp = auth()->user()->can('stok.view_hpp');
        $warehouseId = $request->input('warehouse_id');
        $search = $request->input('search');
        $lowStockOnly = $request->boolean('low_stock');
        $status = $request->input('status');

        $filename = 'inventory_stock_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new InventoryStockExport($canViewHpp, $warehouseId, $search, $lowStockOnly, $status),
            $filename
        );
    }
}
