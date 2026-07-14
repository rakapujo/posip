<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\StockOpname\ApproveStockOpnameAction;
use App\Actions\StockOpname\CreateStockOpnameAction;
use App\Actions\StockOpname\UpdateStockOpnameAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\AttachesSerialUnitsToDocDetails;
use App\Models\DocStockOpname;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Services\InventoryMasterRules;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class StockOpnameController extends BaseApiController
{
    use AttachesSerialUnitsToDocDetails;

    /**
     * Get validation rules for stock opname.
     */
    private function getValidationRules(): array
    {
        return [
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'tanggal_opname' => 'required|date',
            'mode' => 'required|in:full,partial',
            'notes' => 'nullable|string|max:1000',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:master_produk,id',
            'details.*.qty_physical' => 'required|numeric|min:0',
            'details.*.notes' => 'nullable|string|max:255',
            'details.*.serial_unit_ids_present' => 'nullable|array',
            'details.*.serial_unit_ids_present.*' => 'string',
        ];
    }

    /**
     * Get validation messages for stock opname.
     */
    private function getValidationMessages(): array
    {
        return [
            'warehouse_id.required' => 'Warehouse wajib dipilih.',
            'warehouse_id.exists' => 'Warehouse tidak valid.',
            'tanggal_opname.required' => 'Tanggal opname wajib diisi.',
            'mode.required' => 'Mode opname wajib dipilih.',
            'mode.in' => 'Mode harus full atau partial.',
            'details.required' => 'Minimal harus ada 1 detail produk.',
            'details.min' => 'Minimal harus ada 1 detail produk.',
            'details.*.product_id.required' => 'Produk wajib dipilih.',
            'details.*.product_id.exists' => 'Produk tidak valid.',
            'details.*.qty_physical.required' => 'Qty fisik wajib diisi.',
            'details.*.qty_physical.min' => 'Qty fisik minimal 0.',
        ];
    }

    /**
     * Check for duplicate products in details.
     */
    private function hasDuplicateProducts(array $details): bool
    {
        $productIds = collect($details)->pluck('product_id');
        return $productIds->count() !== $productIds->unique()->count();
    }

    /**
     * Display a listing of stock opnames.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('opname.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat stock opname.');
        }

        $query = DocStockOpname::with([
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
                'createdBy:id,name',
            ])
            ->withCount('details');

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by warehouse
        if ($request->filled('warehouse_id')) {
            $query->byWarehouse($request->warehouse_id);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        // Filter by mode
        if ($request->filled('mode')) {
            $query->where('mode', $request->mode);
        }

        // Filter by date range
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal_opname');
        $sortOrder = $request->input('sort_order', 'desc');

        // Map sort fields
        $sortableFields = ['nomor_dokumen', 'tanggal_opname', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal_opname', 'desc');
        }

        // Paginate
        $perPage = $this->getPerPage($request, 15);
        $opnames = $query->paginate($perPage);

        return $this->success([
            'items' => $opnames->items(),
            'pagination' => [
                'current_page' => $opnames->currentPage(),
                'last_page' => $opnames->lastPage(),
                'per_page' => $opnames->perPage(),
                'total' => $opnames->total(),
            ],
        ]);
    }

    /**
     * Store a newly created stock opname.
     */
    public function store(Request $request, CreateStockOpnameAction $action): JsonResponse
    {
        if (!auth()->user()->can('opname.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat stock opname.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu stock opname.']],
                'Validasi gagal'
            );
        }

        if ($errors = InventoryMasterRules::warehouseWithDetailsErrors($validated['warehouse_id'], $validated['details'])) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $opname = $action->execute($validated);

            return $this->created([
                'opname' => $opname,
            ], 'Stock opname berhasil dibuat.');
        } catch (\Exception $e) {
            return $this->error('Gagal membuat stock opname: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified stock opname.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('opname.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat stock opname.');
        }

        $opname = DocStockOpname::with([
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'details.product:id,ulid,kode_produk,nama_produk,barcode,avg_cost,is_serial',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'approvedBy:id,name,email',
            'adjustment:id,ulid,nomor_dokumen,status',
        ])->where('ulid', $ulid)->first();

        if (!$opname) {
            return $this->notFound('Stock opname tidak ditemukan.');
        }

        // Make hidden IDs visible for form binding
        $opname->makeVisible('warehouse_id');
        if ($opname->warehouse) {
            $opname->warehouse->makeVisible('id');
        }
        foreach ($opname->details as $detail) {
            $detail->makeVisible('product_id');
            if ($detail->product) {
                $detail->product->makeVisible('id');
            }
        }

        // Tempelkan rincian unit serial yang HADIR (serial_unit_ids_present) untuk detail + PDF
        $this->attachDocSerialUnits($opname->details, 'serial_unit_ids_present');

        return $this->success([
            'opname' => $opname,
        ]);
    }

    /**
     * Update the specified stock opname.
     */
    public function update(Request $request, string $ulid, UpdateStockOpnameAction $action): JsonResponse
    {
        if (!auth()->user()->can('opname.update')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah stock opname.');
        }

        $opname = DocStockOpname::where('ulid', $ulid)->first();

        if (!$opname) {
            return $this->notFound('Stock opname tidak ditemukan.');
        }

        if (!$opname->isDraft()) {
            return $this->error('Hanya stock opname dengan status draft yang dapat diedit.', 422);
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu stock opname.']],
                'Validasi gagal'
            );
        }

        if ($errors = InventoryMasterRules::warehouseWithDetailsErrors($validated['warehouse_id'], $validated['details'])) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $opname = $action->execute($opname, $validated);

            return $this->success([
                'opname' => $opname,
            ], 'Stock opname berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui stock opname: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified stock opname.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('opname.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus stock opname.');
        }

        $opname = DocStockOpname::where('ulid', $ulid)->first();

        if (!$opname) {
            return $this->notFound('Stock opname tidak ditemukan.');
        }

        if (!$opname->isDraft()) {
            return $this->error('Hanya stock opname dengan status draft yang dapat dihapus.', 422);
        }

        $opname->delete();

        return $this->success(null, 'Stock opname berhasil dihapus.');
    }

    /**
     * Approve the specified stock opname.
     */
    public function approve(string $ulid, ApproveStockOpnameAction $action): JsonResponse
    {
        if (!auth()->user()->can('opname.approve')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menyetujui stock opname.');
        }

        $opname = DocStockOpname::where('ulid', $ulid)->first();

        if (!$opname) {
            return $this->notFound('Stock opname tidak ditemukan.');
        }

        if ($errors = InventoryMasterRules::opnameDocumentErrors($opname)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $opname = $action->execute($opname);

            return $this->success([
                'opname' => $opname,
            ], 'Stock opname berhasil disetujui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal menyetujui stock opname: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get products with stock for a specific warehouse.
     * Used for product autocomplete in opname form (partial mode).
     */
    public function getProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('opname.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'search' => 'nullable|string|max:100',
        ]);

        $warehouseId = $request->warehouse_id;
        $search = $request->search;

        $query = MasterProduk::active()
            ->select('id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'avg_cost', 'is_serial');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('kode_produk', 'like', "%{$search}%")
                  ->orWhere('nama_produk', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $products = $query->limit(20)->get();

        // Add stock info for each product
        $items = $products->map(function ($product) use ($warehouseId) {
            $stock = InventoryStock::where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->first();

            return [
                'id' => $product->id,
                'ulid' => $product->ulid,
                'kode_produk' => $product->kode_produk,
                'nama_produk' => $product->nama_produk,
                'barcode' => $product->barcode,
                'stok' => $stock ? (int) $stock->qty : 0,
                'avg_cost' => (float) $product->avg_cost,
                'is_serial' => (bool) $product->is_serial,
            ];
        });

        return $this->success([
            'items' => $items,
        ]);
    }

    /**
     * Get all products with stock for a specific warehouse.
     * Used for full mode opname - loads products in batches for performance.
     */
    public function loadAllProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('opname.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:10|max:100',
        ]);

        $warehouseId = $request->warehouse_id;
        $perPage = $this->getPerPage($request, 50);

        // Get all products that have stock in this warehouse (qty > 0 or has record)
        // Join with inventory_stock to get current qty
        $query = MasterProduk::active()
            ->select('master_produk.id', 'master_produk.ulid', 'master_produk.kode_produk', 'master_produk.nama_produk', 'master_produk.barcode', 'master_produk.avg_cost', 'master_produk.is_serial')
            ->leftJoin('inventory_stock', function ($join) use ($warehouseId) {
                $join->on('master_produk.id', '=', 'inventory_stock.product_id')
                     ->where('inventory_stock.warehouse_id', '=', $warehouseId);
            })
            ->addSelect('inventory_stock.qty as stok')
            ->whereNotNull('inventory_stock.id') // Only products with inventory record
            ->orderBy('master_produk.kode_produk');

        $products = $query->paginate($perPage);

        // Transform data
        $items = collect($products->items())->map(function ($product) {
            return [
                'id' => $product->id,
                'ulid' => $product->ulid,
                'kode_produk' => $product->kode_produk,
                'nama_produk' => $product->nama_produk,
                'barcode' => $product->barcode,
                'stok' => (int) ($product->stok ?? 0),
                'avg_cost' => (float) $product->avg_cost,
                'is_serial' => (bool) $product->is_serial,
            ];
        });

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $products->currentPage(),
                'last_page' => $products->lastPage(),
                'per_page' => $products->perPage(),
                'total' => $products->total(),
            ],
        ]);
    }

    /**
     * Get stock setting for frontend validation.
     */
    public function getStockSetting(): JsonResponse
    {
        return $this->success([
            'negative_stock_allowed' => SettingService::isNegativeStockAllowed(),
        ]);
    }

    /**
     * Refresh stock system for products in opname form.
     * Returns updated stock and avg_cost for given product IDs.
     */
    public function refreshStock(Request $request): JsonResponse
    {
        if (!auth()->user()->can('opname.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'product_ids' => 'required|array|min:1',
            'product_ids.*' => 'required|integer|exists:master_produk,id',
        ]);

        $warehouseId = $request->warehouse_id;
        $productIds = $request->product_ids;

        // Get products with their stock
        $products = MasterProduk::whereIn('id', $productIds)
            ->select('id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'avg_cost')
            ->get();

        // Map products with stock info
        $items = $products->map(function ($product) use ($warehouseId) {
            $stock = InventoryStock::where('product_id', $product->id)
                ->where('warehouse_id', $warehouseId)
                ->first();

            return [
                'id' => $product->id,
                'ulid' => $product->ulid,
                'kode_produk' => $product->kode_produk,
                'nama_produk' => $product->nama_produk,
                'barcode' => $product->barcode,
                'stok' => $stock ? (int) $stock->qty : 0,
                'avg_cost' => (float) $product->avg_cost,
            ];
        })->keyBy('id');

        return $this->success([
            'items' => $items,
        ]);
    }

    /**
     * Check if there's an existing draft opname for a warehouse.
     */
    public function checkDraft(Request $request): JsonResponse
    {
        if (!auth()->user()->can('opname.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'warehouse_id' => 'required|exists:master_warehouse,id',
        ]);

        $existingDraft = DocStockOpname::where('warehouse_id', $request->warehouse_id)
            ->where('status', 'draft')
            ->select('ulid', 'nomor_dokumen', 'created_at')
            ->with('createdBy:id,name')
            ->first();

        return $this->success([
            'has_draft' => $existingDraft !== null,
            'draft' => $existingDraft,
        ]);
    }
}
