<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Repack\ApproveRepackAction;
use App\Actions\Repack\CreateRepackAction;
use App\Actions\Repack\UpdateRepackAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocRepack;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Services\InventoryMasterRules;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class RepackController extends BaseApiController
{
    /**
     * Get validation rules for repack.
     */
    private function getValidationRules(): array
    {
        return [
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'tipe' => 'required|in:pecah,gabung',
            'tanggal' => 'required|date',
            'biaya_repack' => 'nullable|numeric|min:0',
            'notes' => 'nullable|string|max:1000',
            'inputs' => 'required|array|min:1',
            'inputs.*.product_id' => 'required|exists:master_produk,id',
            'inputs.*.qty' => 'required|numeric|min:0.0001',
            'outputs' => 'required|array|min:1',
            'outputs.*.product_id' => 'required|exists:master_produk,id',
            'outputs.*.qty' => 'required|numeric|min:0.0001',
        ];
    }

    /**
     * Get validation messages for repack.
     */
    private function getValidationMessages(): array
    {
        return [
            'warehouse_id.required' => 'Gudang wajib dipilih.',
            'warehouse_id.exists' => 'Gudang tidak valid.',
            'tipe.required' => 'Tipe repack wajib dipilih.',
            'tipe.in' => 'Tipe harus pecah atau gabung.',
            'tanggal.required' => 'Tanggal wajib diisi.',
            'biaya_repack.min' => 'Biaya repack tidak boleh negatif.',
            'inputs.required' => 'Minimal harus ada 1 bahan input.',
            'inputs.min' => 'Minimal harus ada 1 bahan input.',
            'inputs.*.product_id.required' => 'Produk bahan wajib dipilih.',
            'inputs.*.product_id.exists' => 'Produk bahan tidak valid.',
            'inputs.*.qty.required' => 'Qty bahan wajib diisi.',
            'inputs.*.qty.min' => 'Qty bahan minimal 1.',
            'outputs.required' => 'Minimal harus ada 1 hasil output.',
            'outputs.min' => 'Minimal harus ada 1 hasil output.',
            'outputs.*.product_id.required' => 'Produk hasil wajib dipilih.',
            'outputs.*.product_id.exists' => 'Produk hasil tidak valid.',
            'outputs.*.qty.required' => 'Qty hasil wajib diisi.',
            'outputs.*.qty.min' => 'Qty hasil minimal 1.',
        ];
    }

    /**
     * Check for duplicate products in array.
     */
    private function hasDuplicateProducts(array $items): bool
    {
        $productIds = collect($items)->pluck('product_id');
        return $productIds->count() !== $productIds->unique()->count();
    }

    /**
     * Check if input and output have overlapping products.
     */
    private function hasOverlappingProducts(array $inputs, array $outputs): bool
    {
        $inputIds = collect($inputs)->pluck('product_id')->toArray();
        $outputIds = collect($outputs)->pluck('product_id')->toArray();
        return !empty(array_intersect($inputIds, $outputIds));
    }

    /**
     * Validate item count based on tipe.
     * - pecah: max 1 input, unlimited outputs
     * - gabung: unlimited inputs, max 1 output
     */
    private function validateItemCountByTipe(string $tipe, array $inputs, array $outputs): ?array
    {
        if ($tipe === 'pecah' && count($inputs) > 1) {
            return ['inputs' => ['Tipe Pecah hanya boleh memiliki 1 produk bahan.']];
        }

        if ($tipe === 'gabung' && count($outputs) > 1) {
            return ['outputs' => ['Tipe Gabung hanya boleh memiliki 1 produk hasil.']];
        }

        return null;
    }

    /**
     * Display a listing of repacks.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('repack.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat repack.');
        }

        $query = DocRepack::with([
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
                'createdBy:id,name',
            ])
            ->withCount(['inputs', 'outputs']);

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by warehouse
        if ($request->filled('warehouse_id')) {
            $query->byWarehouse($request->warehouse_id);
        }

        // Filter by tipe
        if ($request->filled('tipe')) {
            $query->byTipe($request->tipe);
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
        $sortableFields = ['nomor_dokumen', 'tanggal', 'tipe', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal', 'desc');
        }

        // Paginate
        $perPage = $this->getPerPage($request, 15);
        $repacks = $query->paginate($perPage);

        return $this->success([
            'items' => $repacks->items(),
            'pagination' => [
                'current_page' => $repacks->currentPage(),
                'last_page' => $repacks->lastPage(),
                'per_page' => $repacks->perPage(),
                'total' => $repacks->total(),
            ],
        ]);
    }

    /**
     * Store a newly created repack.
     */
    public function store(Request $request, CreateRepackAction $action): JsonResponse
    {
        if (!auth()->user()->can('repack.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat repack.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products in inputs
        if ($this->hasDuplicateProducts($validated['inputs'])) {
            return $this->validationError(
                ['inputs' => ['Tidak boleh ada produk bahan yang sama dalam satu repack.']],
                'Validasi gagal'
            );
        }

        // Check for duplicate products in outputs
        if ($this->hasDuplicateProducts($validated['outputs'])) {
            return $this->validationError(
                ['outputs' => ['Tidak boleh ada produk hasil yang sama dalam satu repack.']],
                'Validasi gagal'
            );
        }

        // Check for overlapping products between input and output
        if ($this->hasOverlappingProducts($validated['inputs'], $validated['outputs'])) {
            return $this->validationError(
                ['outputs' => ['Produk hasil tidak boleh sama dengan produk bahan.']],
                'Validasi gagal'
            );
        }

        // Validate item count based on tipe
        $itemCountError = $this->validateItemCountByTipe($validated['tipe'], $validated['inputs'], $validated['outputs']);
        if ($itemCountError) {
            return $this->validationError($itemCountError, 'Validasi gagal');
        }

        if ($errors = InventoryMasterRules::repackPayloadErrors($validated)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $repack = $action->execute($validated);

            return $this->created([
                'repack' => $repack,
            ], 'Repack berhasil dibuat.');
        } catch (\Exception $e) {
            return $this->error('Gagal membuat repack: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified repack.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('repack.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat repack.');
        }

        $repack = DocRepack::with([
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'inputs.product:id,ulid,kode_produk,nama_produk,barcode',
            'outputs.product:id,ulid,kode_produk,nama_produk,barcode',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'approvedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (!$repack) {
            return $this->notFound('Repack tidak ditemukan.');
        }

        // Make hidden IDs visible for form binding
        $repack->makeVisible('warehouse_id');
        if ($repack->warehouse) {
            $repack->warehouse->makeVisible('id');
        }
        foreach ($repack->inputs as $input) {
            $input->makeVisible('product_id');
            if ($input->product) {
                $input->product->makeVisible('id');
            }
        }
        foreach ($repack->outputs as $output) {
            $output->makeVisible('product_id');
            if ($output->product) {
                $output->product->makeVisible('id');
            }
        }

        return $this->success([
            'repack' => $repack,
        ]);
    }

    /**
     * Update the specified repack.
     */
    public function update(Request $request, string $ulid, UpdateRepackAction $action): JsonResponse
    {
        if (!auth()->user()->can('repack.update')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah repack.');
        }

        $repack = DocRepack::where('ulid', $ulid)->first();

        if (!$repack) {
            return $this->notFound('Repack tidak ditemukan.');
        }

        if (!$repack->isDraft()) {
            return $this->error('Hanya repack dengan status draft yang dapat diedit.', 422);
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products in inputs
        if ($this->hasDuplicateProducts($validated['inputs'])) {
            return $this->validationError(
                ['inputs' => ['Tidak boleh ada produk bahan yang sama dalam satu repack.']],
                'Validasi gagal'
            );
        }

        // Check for duplicate products in outputs
        if ($this->hasDuplicateProducts($validated['outputs'])) {
            return $this->validationError(
                ['outputs' => ['Tidak boleh ada produk hasil yang sama dalam satu repack.']],
                'Validasi gagal'
            );
        }

        // Check for overlapping products between input and output
        if ($this->hasOverlappingProducts($validated['inputs'], $validated['outputs'])) {
            return $this->validationError(
                ['outputs' => ['Produk hasil tidak boleh sama dengan produk bahan.']],
                'Validasi gagal'
            );
        }

        // Validate item count based on tipe
        $itemCountError = $this->validateItemCountByTipe($validated['tipe'], $validated['inputs'], $validated['outputs']);
        if ($itemCountError) {
            return $this->validationError($itemCountError, 'Validasi gagal');
        }

        if ($errors = InventoryMasterRules::repackPayloadErrors($validated)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $repack = $action->execute($repack, $validated);

            return $this->success([
                'repack' => $repack,
            ], 'Repack berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui repack: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified repack.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('repack.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus repack.');
        }

        $repack = DocRepack::where('ulid', $ulid)->first();

        if (!$repack) {
            return $this->notFound('Repack tidak ditemukan.');
        }

        if (!$repack->isDraft()) {
            return $this->error('Hanya repack dengan status draft yang dapat dihapus.', 422);
        }

        $repack->delete();

        return $this->success(null, 'Repack berhasil dihapus.');
    }

    /**
     * Approve the specified repack.
     */
    public function approve(string $ulid, ApproveRepackAction $action): JsonResponse
    {
        if (!auth()->user()->can('repack.approve')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menyetujui repack.');
        }

        $repack = DocRepack::where('ulid', $ulid)->first();

        if (!$repack) {
            return $this->notFound('Repack tidak ditemukan.');
        }

        if ($errors = InventoryMasterRules::repackDocumentErrors($repack)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $repack = $action->execute($repack);

            return $this->success([
                'repack' => $repack,
            ], 'Repack berhasil disetujui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal menyetujui repack: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get products with stock for a specific warehouse.
     * Used for product autocomplete in repack form.
     */
    public function getProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('repack.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'search' => 'nullable|string|max:100',
        ]);

        $warehouseId = $request->warehouse_id;
        $search = $request->search;

        $query = MasterProduk::active()
            ->where('is_serial', false) // produk serial tidak bisa di-repack (unit lahir via Pembelian Serial)
            ->select('id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'avg_cost');

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
                'avg_cost' => (float) $product->avg_cost,
                'stok' => $stock ? (int) $stock->qty : 0,
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
