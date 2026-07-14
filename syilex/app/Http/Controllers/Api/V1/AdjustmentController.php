<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Adjustment\ApproveAdjustmentAction;
use App\Actions\Adjustment\CreateAdjustmentAction;
use App\Actions\Adjustment\UpdateAdjustmentAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\AttachesSerialUnitsToDocDetails;
use App\Models\DocAdjustment;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Services\InventoryMasterRules;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdjustmentController extends BaseApiController
{
    use AttachesSerialUnitsToDocDetails;

    /**
     * Get validation rules for adjustment.
     */
    private function getValidationRules(): array
    {
        return [
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'tanggal' => 'required|date',
            'keterangan' => 'nullable|string|max:1000',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:master_produk,id',
            'details.*.jenis' => 'required|in:debit,kredit',
            'details.*.qty' => 'required|numeric|min:0.0001',
            'details.*.notes' => 'nullable|string|max:255',
            'details.*.serial_unit_ids' => 'nullable|array',
            'details.*.serial_unit_ids.*' => 'string',
            // Status fate per unit (kredit serial): map {ulid: rusak|hilang}, default rusak.
            'details.*.serial_unit_statuses' => 'nullable|array',
            'details.*.serial_unit_statuses.*' => 'in:rusak,hilang',
        ];
    }

    /**
     * Get validation messages for adjustment.
     */
    private function getValidationMessages(): array
    {
        return [
            'warehouse_id.required' => 'Warehouse wajib dipilih.',
            'warehouse_id.exists' => 'Warehouse tidak valid.',
            'tanggal.required' => 'Tanggal wajib diisi.',
            'details.required' => 'Minimal harus ada 1 detail produk.',
            'details.min' => 'Minimal harus ada 1 detail produk.',
            'details.*.product_id.required' => 'Produk wajib dipilih.',
            'details.*.product_id.exists' => 'Produk tidak valid.',
            'details.*.jenis.required' => 'Jenis adjustment wajib dipilih.',
            'details.*.jenis.in' => 'Jenis harus debit atau kredit.',
            'details.*.qty.required' => 'Qty wajib diisi.',
            'details.*.qty.min' => 'Qty minimal 1.',
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
     * Display a listing of adjustments.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('adjustment.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat adjustment.');
        }

        $query = DocAdjustment::with([
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
                'createdBy:id,name',
                'details:id,adjustment_id,notes'
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

        // Filter by date range
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');

        // Map sort fields
        $sortableFields = ['nomor_dokumen', 'tanggal', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal', 'desc');
        }

        // Paginate
        $perPage = $this->getPerPage($request, 15);
        $adjustments = $query->paginate($perPage);

        return $this->success([
            'items' => $adjustments->items(),
            'pagination' => [
                'current_page' => $adjustments->currentPage(),
                'last_page' => $adjustments->lastPage(),
                'per_page' => $adjustments->perPage(),
                'total' => $adjustments->total(),
            ],
        ]);
    }

    /**
     * Store a newly created adjustment.
     */
    public function store(Request $request, CreateAdjustmentAction $action): JsonResponse
    {
        if (!auth()->user()->can('adjustment.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat adjustment.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu adjustment.']],
                'Validasi gagal'
            );
        }

        if ($errors = InventoryMasterRules::warehouseWithDetailsErrors($validated['warehouse_id'], $validated['details'])) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $adjustment = $action->execute($validated);

            return $this->created([
                'adjustment' => $adjustment,
            ], 'Adjustment berhasil dibuat.');
        } catch (ValidationException $e) {
            throw $e; // biarkan handler render 422 (mis. guard produk serial)
        } catch (\Exception $e) {
            return $this->error('Gagal membuat adjustment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified adjustment.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('adjustment.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat adjustment.');
        }

        $adjustment = DocAdjustment::with([
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'details.product:id,ulid,kode_produk,nama_produk,barcode,is_serial',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'approvedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (!$adjustment) {
            return $this->notFound('Adjustment tidak ditemukan.');
        }

        // Make hidden IDs visible for form binding
        $adjustment->makeVisible('warehouse_id');
        if ($adjustment->warehouse) {
            $adjustment->warehouse->makeVisible('id');
        }
        foreach ($adjustment->details as $detail) {
            $detail->makeVisible('product_id');
            if ($detail->product) {
                $detail->product->makeVisible('id');
            }
        }

        // Tempelkan rincian unit serial + status fate (rusak/hilang) untuk tampil di detail + PDF
        $this->attachDocSerialUnits($adjustment->details, 'serial_unit_ids', 'serial_unit_statuses');

        return $this->success([
            'adjustment' => $adjustment,
        ]);
    }

    /**
     * Update the specified adjustment.
     */
    public function update(Request $request, string $ulid, UpdateAdjustmentAction $action): JsonResponse
    {
        if (!auth()->user()->can('adjustment.update')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah adjustment.');
        }

        $adjustment = DocAdjustment::where('ulid', $ulid)->first();

        if (!$adjustment) {
            return $this->notFound('Adjustment tidak ditemukan.');
        }

        if (!$adjustment->isDraft()) {
            return $this->error('Hanya adjustment dengan status draft yang dapat diedit.', 422);
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu adjustment.']],
                'Validasi gagal'
            );
        }

        if ($errors = InventoryMasterRules::warehouseWithDetailsErrors($validated['warehouse_id'], $validated['details'])) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $adjustment = $action->execute($adjustment, $validated);

            return $this->success([
                'adjustment' => $adjustment,
            ], 'Adjustment berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui adjustment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified adjustment.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('adjustment.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus adjustment.');
        }

        $adjustment = DocAdjustment::where('ulid', $ulid)->first();

        if (!$adjustment) {
            return $this->notFound('Adjustment tidak ditemukan.');
        }

        if (!$adjustment->isDraft()) {
            return $this->error('Hanya adjustment dengan status draft yang dapat dihapus.', 422);
        }

        $adjustment->delete();

        return $this->success(null, 'Adjustment berhasil dihapus.');
    }

    /**
     * Approve the specified adjustment.
     */
    public function approve(string $ulid, ApproveAdjustmentAction $action): JsonResponse
    {
        if (!auth()->user()->can('adjustment.approve')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menyetujui adjustment.');
        }

        $adjustment = DocAdjustment::where('ulid', $ulid)->first();

        if (!$adjustment) {
            return $this->notFound('Adjustment tidak ditemukan.');
        }

        if ($errors = InventoryMasterRules::adjustmentDocumentErrors($adjustment)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $adjustment = $action->execute($adjustment);

            return $this->success([
                'adjustment' => $adjustment,
            ], 'Adjustment berhasil disetujui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal menyetujui adjustment: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get products with stock for a specific warehouse.
     * Used for product autocomplete in adjustment form.
     */
    public function getProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('adjustment.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'search' => 'nullable|string|max:100',
        ]);

        $warehouseId = $request->warehouse_id;
        $search = $request->search;

        $query = MasterProduk::active()
            ->select('id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'is_serial');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('kode_produk', 'like', "%{$search}%")
                  ->orWhere('nama_produk', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        $products = $query->limit(20)->get();

        // Add stock info for each product and transform to array
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
                'is_serial' => (bool) $product->is_serial,
            ];
        });

        return $this->success([
            'items' => $items,
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
}
