<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\StockCard;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\InventoryStock;
use App\Exports\StockCardExport;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class StockCardController extends BaseApiController
{
    /**
     * Display a listing of stock card entries.
     * Product is required.
     */
    public function index(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('stok.view')) {
            return $this->error('Unauthorized', 403);
        }

        // Product is required
        if (!$request->filled('product_id')) {
            return $this->success([
                'stock_cards' => [],
                'product' => null,
                'warehouses' => $this->getWarehouseOptions(),
                'transaction_types' => $this->getTransactionTypeOptions(),
                'pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 0,
                ],
                'can_view_hpp' => auth()->user()->can('stok.view_hpp'),
            ]);
        }

        $canViewHpp = auth()->user()->can('stok.view_hpp');

        // Find product - check if numeric (id) or string (ulid)
        $productId = $request->product_id;
        $product = MasterProduk::with('brand:id,kode_brand,nama_brand')
            ->where(function ($q) use ($productId) {
                if (is_numeric($productId)) {
                    $q->where('id', $productId);
                } else {
                    $q->where('ulid', $productId);
                }
            })
            ->first();

        if (!$product) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        // Build query
        $query = StockCard::query()
            ->with([
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
                'createdBy:id,name',
            ])
            ->byProduct($product->id);

        // Filter by warehouse
        if ($request->filled('warehouse_id')) {
            $query->byWarehouse($request->warehouse_id);
        }

        // Filter by date range
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        if ($startDate || $endDate) {
            $query->byDateRange($startDate, $endDate);
        }

        // Filter by transaction type
        if ($request->filled('transaction_type')) {
            $query->byTransactionType($request->transaction_type);
        }

        // Search by transaction_no
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter only records where HPP changed (for Pergerakan HPP page)
        if ($request->boolean('hpp_changed_only')) {
            $query->whereColumn('avg_cost_before', '!=', 'avg_cost_after');
        }

        // Sort (default: tanggal desc, id desc) - whitelist allowed columns
        $sortableFields = ['tanggal', 'tipe_transaksi', 'qty_masuk', 'qty_keluar', 'saldo', 'created_at'];
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';

        if ($sortField === 'tanggal') {
            $query->orderBy('tanggal', $sortOrder)->orderBy('id', $sortOrder);
        } elseif (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal', $sortOrder)->orderBy('id', $sortOrder);
        }

        // Paginate
        $perPage = $this->getPerPage($request, 20);
        $stockCards = $query->paginate($perPage);

        // Resolve dokumen sumber untuk baris PURCHASE produk serial → bisa diklik buka dokumen PBS.
        // Produk serial: semua entry PURCHASE berasal dari Pembelian Serial (transaction_id = intake id).
        $serialIntakeMap = [];
        if ($product->is_serial) {
            $purchaseIds = $stockCards->getCollection()
                ->where('transaction_type', 'PURCHASE')
                ->pluck('transaction_id')
                ->filter()
                ->unique()
                ->all();
            if (!empty($purchaseIds)) {
                $serialIntakeMap = \App\Models\DocSerialIntake::whereIn('id', $purchaseIds)
                    ->pluck('ulid', 'id')
                    ->all();
            }
        }

        // Transform response (LocalDateTime cast already handles timezone)
        $items = $stockCards->getCollection()->map(function ($card) use ($canViewHpp, $serialIntakeMap) {
            $data = [
                'ulid' => $card->ulid,
                'tanggal' => $card->tanggal->format('Y-m-d H:i:s'),
                'transaction_type' => $card->transaction_type,
                'transaction_type_label' => $card->transaction_type_label,
                'transaction_no' => $card->transaction_no,
                'warehouse' => $card->warehouse ? [
                    'ulid' => $card->warehouse->ulid,
                    'kode' => $card->warehouse->kode_warehouse,
                    'nama' => $card->warehouse->nama_warehouse,
                ] : null,
                'qty_in' => $card->qty_in,
                'qty_out' => $card->qty_out,
                'qty_balance' => $card->qty_balance,
                'notes' => $card->notes,
                'created_by' => $card->createdBy?->name,
                'created_at' => $card->created_at?->format('Y-m-d H:i:s'),
            ];

            // HPP columns (only if user has permission)
            if ($canViewHpp) {
                $data['cost_per_unit'] = (float) $card->cost_per_unit;
                $data['total_cost'] = (float) $card->total_cost;
                $data['avg_cost_before'] = (float) $card->avg_cost_before;
                $data['avg_cost_after'] = (float) $card->avg_cost_after;
            }

            // Dokumen sumber (untuk baris intake serial → klik buka dokumen PBS)
            if ($card->transaction_type === 'PURCHASE' && isset($serialIntakeMap[$card->transaction_id])) {
                $data['source_doc'] = [
                    'type' => 'serial-intake',
                    'ulid' => $serialIntakeMap[$card->transaction_id],
                ];
            }

            return $data;
        });

        return $this->success([
            'stock_cards' => $items,
            'product' => [
                'id' => $product->id,
                'ulid' => $product->ulid,
                'kode_produk' => $product->kode_produk,
                'barcode' => $product->barcode,
                'nama_produk' => $product->nama_produk,
                'brand' => $product->brand ? [
                    'kode' => $product->brand->kode_brand,
                    'nama' => $product->brand->nama_brand,
                ] : null,
            ],
            'warehouses' => $this->getWarehouseOptions(),
            'transaction_types' => $this->getTransactionTypeOptions(),
            'pagination' => [
                'current_page' => $stockCards->currentPage(),
                'last_page' => $stockCards->lastPage(),
                'per_page' => $stockCards->perPage(),
                'total' => $stockCards->total(),
            ],
            'can_view_hpp' => $canViewHpp,
        ]);
    }

    /**
     * Get summary for stock card (opening, total in/out, ending).
     */
    public function summary(Request $request): JsonResponse
    {
        // Check permission
        if (!auth()->user()->can('stok.view')) {
            return $this->error('Unauthorized', 403);
        }

        // Product is required
        if (!$request->filled('product_id')) {
            return $this->success([
                'summary' => [
                    'opening_balance' => 0,
                    'total_in' => 0,
                    'total_out' => 0,
                    'ending_balance' => 0,
                ],
            ]);
        }

        // Find product - check if numeric (id) or string (ulid)
        $productId = $request->product_id;
        $product = MasterProduk::where(function ($q) use ($productId) {
            if (is_numeric($productId)) {
                $q->where('id', $productId);
            } else {
                $q->where('ulid', $productId);
            }
        })->first();

        if (!$product) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        $warehouseId = $request->input('warehouse_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');

        // Build base query
        $query = StockCard::query()->byProduct($product->id);

        if ($warehouseId) {
            $query->byWarehouse($warehouseId);
        }

        // Get opening balance (balance before start_date)
        $openingBalance = 0;
        if ($startDate) {
            $openingQuery = StockCard::query()->byProduct($product->id);
            if ($warehouseId) {
                $openingQuery->byWarehouse($warehouseId);
            }
            $lastBefore = $openingQuery
                ->where('tanggal', '<', $startDate . ' 00:00:00')
                ->orderBy('tanggal', 'desc')
                ->orderBy('id', 'desc')
                ->first();
            $openingBalance = $lastBefore ? $lastBefore->qty_balance : 0;
        } else {
            // If no start_date filter, opening balance is 0 (beginning of time)
            $openingBalance = 0;
        }

        // Get totals within date range
        $periodQuery = clone $query;
        if ($startDate || $endDate) {
            $periodQuery->byDateRange($startDate, $endDate);
        }

        // Filter by transaction type if specified
        if ($request->filled('transaction_type')) {
            $periodQuery->byTransactionType($request->transaction_type);
        }

        $totalIn = (int) $periodQuery->sum('qty_in');
        $totalOut = (int) (clone $periodQuery)->sum('qty_out');

        // Get ending balance - prefer from stock_card, fallback to inventory_stock
        $endingBalance = 0;

        // First try to get from stock_card
        $endingQuery = StockCard::query()->byProduct($product->id);
        if ($warehouseId) {
            $endingQuery->byWarehouse($warehouseId);
        }
        if ($endDate) {
            $endingQuery->where('tanggal', '<=', $endDate . ' 23:59:59');
        }
        $lastRecord = $endingQuery
            ->orderBy('tanggal', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($lastRecord) {
            $endingBalance = $lastRecord->qty_balance;
        } else {
            // Fallback: get current stock from inventory_stock
            $stockQuery = InventoryStock::where('product_id', $product->id);
            if ($warehouseId) {
                $stockQuery->where('warehouse_id', $warehouseId);
            }
            $endingBalance = (int) $stockQuery->sum('qty');

            // If no date filter and no stock_card records, opening = ending (current stock)
            if (!$startDate && !$endDate) {
                $openingBalance = $endingBalance;
            }
        }

        return $this->success([
            'summary' => [
                'opening_balance' => (int) $openingBalance,
                'total_in' => (int) $totalIn,
                'total_out' => (int) $totalOut,
                'ending_balance' => (int) $endingBalance,
            ],
        ]);
    }

    /**
     * Export stock card to Excel.
     */
    public function export(Request $request)
    {
        // Check permission
        if (!auth()->user()->can('stok.view')) {
            return $this->error('Unauthorized', 403);
        }

        // Product is required
        if (!$request->filled('product_id')) {
            return $this->error('Produk harus dipilih untuk export', 422);
        }

        // Find product - check if numeric (id) or string (ulid)
        $productId = $request->product_id;
        $product = MasterProduk::where(function ($q) use ($productId) {
            if (is_numeric($productId)) {
                $q->where('id', $productId);
            } else {
                $q->where('ulid', $productId);
            }
        })->first();

        if (!$product) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        $canViewHpp = auth()->user()->can('stok.view_hpp');
        $warehouseId = $request->input('warehouse_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $transactionType = $request->input('transaction_type');
        $hppChangedOnly = $request->boolean('hpp_changed_only');

        $filename = 'stock_card_' . $product->kode_produk . '_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(
            new StockCardExport($product->id, $canViewHpp, $warehouseId, $startDate, $endDate, $transactionType, $hppChangedOnly),
            $filename
        );
    }

    /**
     * Get warehouse options for filter dropdown.
     */
    private function getWarehouseOptions(): array
    {
        return MasterWarehouse::active()
            ->select('id', 'ulid', 'kode_warehouse', 'nama_warehouse')
            ->orderBy('nama_warehouse')
            ->get()
            ->makeVisible('id')
            ->toArray();
    }

    /**
     * Get transaction type options for filter dropdown.
     */
    private function getTransactionTypeOptions(): array
    {
        return collect(StockCard::TRANSACTION_TYPES)->map(function ($label, $value) {
            return [
                'value' => $value,
                'label' => $label,
            ];
        })->values()->toArray();
    }

    /**
     * Get HPP movement summary (avg_cost awal/akhir, total nilai masuk/keluar).
     * HPP is GLOBAL (not per-warehouse), but warehouse filter can be used for total nilai calculations.
     */
    public function hppSummary(Request $request): JsonResponse
    {
        // Check permission - requires stok.view_hpp
        if (!auth()->user()->can('stok.view_hpp')) {
            return $this->error('Unauthorized', 403);
        }

        // Product is required
        if (!$request->filled('product_id')) {
            return $this->success([
                'summary' => [
                    'avg_cost_awal' => 0,
                    'total_nilai_masuk' => 0,
                    'total_nilai_keluar' => 0,
                    'avg_cost_akhir' => 0,
                ],
            ]);
        }

        // Find product - check if numeric (id) or string (ulid)
        $productId = $request->product_id;
        $product = MasterProduk::where(function ($q) use ($productId) {
            if (is_numeric($productId)) {
                $q->where('id', $productId);
            } else {
                $q->where('ulid', $productId);
            }
        })->first();

        if (!$product) {
            return $this->error('Produk tidak ditemukan', 404);
        }

        $warehouseId = $request->input('warehouse_id');
        $startDate = $request->input('start_date');
        $endDate = $request->input('end_date');
        $transactionType = $request->input('transaction_type');

        // HPP is GLOBAL - don't filter by warehouse for avg_cost queries
        // Only use warehouse filter for total nilai calculations

        // Get GLOBAL avg_cost BEFORE start_date (HPP Awal)
        $avgCostAwal = 0;
        if ($startDate) {
            // Get last record before start_date (GLOBAL - no warehouse filter)
            $lastBefore = StockCard::query()
                ->byProduct($product->id)
                ->where('tanggal', '<', $startDate . ' 00:00:00')
                ->orderBy('tanggal', 'desc')
                ->orderBy('id', 'desc')
                ->first();
            $avgCostAwal = $lastBefore ? (float) $lastBefore->avg_cost_after : 0;
        } else {
            // If no start_date, get first record's avg_cost_before (GLOBAL)
            $firstRecord = StockCard::query()
                ->byProduct($product->id)
                ->orderBy('tanggal', 'asc')
                ->orderBy('id', 'asc')
                ->first();
            $avgCostAwal = $firstRecord ? (float) $firstRecord->avg_cost_before : 0;
        }

        // Build period query for total nilai (can be filtered by warehouse)
        $periodQuery = StockCard::query()->byProduct($product->id);
        if ($warehouseId) {
            $periodQuery->byWarehouse($warehouseId);
        }
        if ($startDate || $endDate) {
            $periodQuery->byDateRange($startDate, $endDate);
        }
        if ($transactionType) {
            $periodQuery->byTransactionType($transactionType);
        }

        // Filter only records where HPP actually changed (for accurate Pergerakan HPP)
        if ($request->boolean('hpp_changed_only')) {
            $periodQuery->whereColumn('avg_cost_before', '!=', 'avg_cost_after');
        }

        // Total nilai masuk (qty_in > 0)
        $totalNilaiMasuk = (float) (clone $periodQuery)
            ->where('qty_in', '>', 0)
            ->sum('total_cost');

        // Total nilai keluar (qty_out > 0)
        $totalNilaiKeluar = (float) (clone $periodQuery)
            ->where('qty_out', '>', 0)
            ->sum('total_cost');

        // Get GLOBAL avg_cost AFTER end_date (HPP Akhir)
        $avgCostAkhir = 0;
        $akhirQuery = StockCard::query()->byProduct($product->id);
        // No warehouse filter for HPP - it's global
        if ($endDate) {
            $akhirQuery->where('tanggal', '<=', $endDate . ' 23:59:59');
        }
        $lastRecord = $akhirQuery
            ->orderBy('tanggal', 'desc')
            ->orderBy('id', 'desc')
            ->first();

        if ($lastRecord) {
            $avgCostAkhir = (float) $lastRecord->avg_cost_after;
        } else {
            // Fallback: get current GLOBAL avg_cost from product
            $avgCostAkhir = (float) $product->avg_cost;

            // If no date filter and no stock_card records
            if (!$startDate && !$endDate) {
                $avgCostAwal = $avgCostAkhir;
            }
        }

        return $this->success([
            'summary' => [
                'avg_cost_awal' => $avgCostAwal,
                'total_nilai_masuk' => $totalNilaiMasuk,
                'total_nilai_keluar' => $totalNilaiKeluar,
                'avg_cost_akhir' => $avgCostAkhir,
            ],
        ]);
    }
}
