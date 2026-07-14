<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\HppCorrection\ApproveHppCorrectionAction;
use App\Actions\HppCorrection\CreateHppCorrectionAction;
use App\Actions\HppCorrection\UpdateHppCorrectionAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocHppCorrection;
use App\Models\DocHppCorrectionDetail;
use App\Models\MasterProduk;
use App\Services\InventoryMasterRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class HppCorrectionController extends BaseApiController
{
    /**
     * Alasan options for frontend.
     */
    private const ALASAN_OPTIONS = [
        ['value' => 'KOREKSI_HARGA_BELI', 'label' => 'Koreksi Harga Beli'],
        ['value' => 'KOREKSI_DATA', 'label' => 'Koreksi Data'],
        ['value' => 'MIGRASI_SISTEM', 'label' => 'Migrasi Sistem'],
        ['value' => 'LAINNYA', 'label' => 'Lainnya'],
    ];

    /**
     * Get validation rules.
     */
    private function getValidationRules(): array
    {
        return [
            'tanggal_koreksi' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:master_produk,id',
            'details.*.hpp_baru' => 'required|numeric|gt:0',
            'details.*.alasan' => 'required|in:KOREKSI_HARGA_BELI,KOREKSI_DATA,MIGRASI_SISTEM,LAINNYA',
            'details.*.notes' => 'nullable|string|max:255',
        ];
    }

    /**
     * Get validation messages.
     */
    private function getValidationMessages(): array
    {
        return [
            'tanggal_koreksi.required' => 'Tanggal koreksi wajib diisi.',
            'details.required' => 'Minimal harus ada 1 detail produk.',
            'details.min' => 'Minimal harus ada 1 detail produk.',
            'details.*.product_id.required' => 'Produk wajib dipilih.',
            'details.*.product_id.exists' => 'Produk tidak valid.',
            'details.*.hpp_baru.required' => 'HPP Baru wajib diisi.',
            'details.*.hpp_baru.gt' => 'HPP Baru harus lebih dari 0.',
            'details.*.alasan.required' => 'Alasan wajib dipilih.',
            'details.*.alasan.in' => 'Alasan tidak valid.',
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
     * Validate that "LAINNYA" alasan has notes.
     */
    private function validateLainnyaNotes(array $details): array
    {
        $errors = [];
        foreach ($details as $index => $detail) {
            if ($detail['alasan'] === 'LAINNYA' && empty($detail['notes'])) {
                $errors["details.{$index}.notes"] = ['Notes wajib diisi jika alasan adalah "Lainnya".'];
            }
        }
        return $errors;
    }

    /**
     * Display a listing of HPP corrections.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('hpp.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat koreksi HPP.');
        }

        $query = DocHppCorrection::with([
                'createdBy:id,name',
                'approvedBy:id,name',
            ])
            ->withCount('details');

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by status
        if ($request->filled('status')) {
            $query->byStatus($request->status);
        }

        // Filter by date range
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal_koreksi');
        $sortOrder = $request->input('sort_order', 'desc');

        $sortableFields = ['nomor_dokumen', 'tanggal_koreksi', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal_koreksi', 'desc');
        }

        // Paginate
        $perPage = $this->getPerPage($request, 15);
        $corrections = $query->paginate($perPage);

        return $this->success([
            'items' => $corrections->items(),
            'pagination' => [
                'current_page' => $corrections->currentPage(),
                'last_page' => $corrections->lastPage(),
                'per_page' => $corrections->perPage(),
                'total' => $corrections->total(),
            ],
        ]);
    }

    /**
     * Store a newly created HPP correction.
     */
    public function store(Request $request, CreateHppCorrectionAction $action): JsonResponse
    {
        if (!auth()->user()->can('hpp.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat koreksi HPP.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu koreksi HPP.']],
                'Validasi gagal'
            );
        }

        // Validate LAINNYA notes
        $lainnyaErrors = $this->validateLainnyaNotes($validated['details']);
        if (!empty($lainnyaErrors)) {
            return $this->validationError($lainnyaErrors, 'Validasi gagal');
        }

        if ($errors = InventoryMasterRules::hppCorrectionPayloadErrors($validated)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $correction = $action->execute($validated);

            return $this->created([
                'correction' => $correction,
            ], 'Koreksi HPP berhasil dibuat.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal membuat koreksi HPP: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified HPP correction.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('hpp.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat koreksi HPP.');
        }

        $correction = DocHppCorrection::with([
            'details.product:id,ulid,kode_produk,nama_produk,barcode,avg_cost',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'approvedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (!$correction) {
            return $this->notFound('Koreksi HPP tidak ditemukan.');
        }

        // Make hidden IDs visible for form binding
        foreach ($correction->details as $detail) {
            $detail->makeVisible('product_id');
            if ($detail->product) {
                $detail->product->makeVisible('id');
            }
        }

        return $this->success([
            'correction' => $correction,
            'alasan_options' => self::ALASAN_OPTIONS,
        ]);
    }

    /**
     * Update the specified HPP correction.
     */
    public function update(Request $request, string $ulid, UpdateHppCorrectionAction $action): JsonResponse
    {
        if (!auth()->user()->can('hpp.update')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah koreksi HPP.');
        }

        $correction = DocHppCorrection::where('ulid', $ulid)->first();

        if (!$correction) {
            return $this->notFound('Koreksi HPP tidak ditemukan.');
        }

        if (!$correction->isDraft()) {
            return $this->error('Hanya koreksi HPP dengan status draft yang dapat diedit.', 422);
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu koreksi HPP.']],
                'Validasi gagal'
            );
        }

        // Validate LAINNYA notes
        $lainnyaErrors = $this->validateLainnyaNotes($validated['details']);
        if (!empty($lainnyaErrors)) {
            return $this->validationError($lainnyaErrors, 'Validasi gagal');
        }

        if ($errors = InventoryMasterRules::hppCorrectionPayloadErrors($validated)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $correction = $action->execute($correction, $validated);

            return $this->success([
                'correction' => $correction,
            ], 'Koreksi HPP berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui koreksi HPP: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified HPP correction.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('hpp.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus koreksi HPP.');
        }

        $correction = DocHppCorrection::where('ulid', $ulid)->first();

        if (!$correction) {
            return $this->notFound('Koreksi HPP tidak ditemukan.');
        }

        if (!$correction->isDraft()) {
            return $this->error('Hanya koreksi HPP dengan status draft yang dapat dihapus.', 422);
        }

        $correction->delete();

        return $this->success(null, 'Koreksi HPP berhasil dihapus.');
    }

    /**
     * Approve the specified HPP correction.
     */
    public function approve(string $ulid, ApproveHppCorrectionAction $action): JsonResponse
    {
        if (!auth()->user()->can('hpp.approve')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menyetujui koreksi HPP.');
        }

        $correction = DocHppCorrection::where('ulid', $ulid)->first();

        if (!$correction) {
            return $this->notFound('Koreksi HPP tidak ditemukan.');
        }

        if ($errors = InventoryMasterRules::hppCorrectionDocumentErrors($correction)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $correction = $action->execute($correction);

            return $this->success([
                'correction' => $correction,
            ], 'Koreksi HPP berhasil disetujui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal menyetujui koreksi HPP: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get products for autocomplete.
     */
    public function getProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('hpp.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'search' => 'nullable|string|max:100',
        ]);

        $search = $request->search;

        $query = MasterProduk::active()
            ->where('is_serial', false) // produk serial pakai menu Koreksi HPP Serial (per-unit)
            ->select('id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'avg_cost');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('kode_produk', 'like', "%{$search}%")
                  ->orWhere('nama_produk', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Exclude locked products (products already in a draft)
        $lockedProductIds = $this->getLockedProductIds();
        if (!empty($lockedProductIds)) {
            $query->whereNotIn('id', $lockedProductIds);
        }

        $products = $query->limit(20)->get();

        $items = $products->map(function ($product) {
            return [
                'id' => $product->id,
                'ulid' => $product->ulid,
                'kode_produk' => $product->kode_produk,
                'nama_produk' => $product->nama_produk,
                'barcode' => $product->barcode,
                'avg_cost' => (float) $product->avg_cost,
            ];
        });

        return $this->success([
            'items' => $items,
        ]);
    }

    /**
     * Check if there's an existing draft HPP correction.
     */
    public function checkDraft(): JsonResponse
    {
        if (!auth()->user()->can('hpp.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $existingDraft = DocHppCorrection::where('status', 'draft')
            ->select('ulid', 'nomor_dokumen', 'created_at')
            ->with('createdBy:id,name')
            ->first();

        return $this->success([
            'has_draft' => $existingDraft !== null,
            'draft' => $existingDraft,
        ]);
    }

    /**
     * Get locked product IDs (products already in a draft).
     */
    public function getLockedProducts(): JsonResponse
    {
        if (!auth()->user()->can('hpp.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $lockedProductIds = $this->getLockedProductIds();

        return $this->success([
            'locked_product_ids' => $lockedProductIds,
        ]);
    }

    /**
     * Get alasan options.
     */
    public function getAlasanOptions(): JsonResponse
    {
        return $this->success([
            'alasan_options' => self::ALASAN_OPTIONS,
        ]);
    }

    /**
     * Get IDs of products that are locked in any draft.
     */
    private function getLockedProductIds(): array
    {
        return DocHppCorrectionDetail::whereHas('correction', function ($query) {
            $query->where('status', 'draft');
        })->pluck('product_id')->toArray();
    }
}
