<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PriceChange\ApplyPriceChangeAction;
use App\Actions\PriceChange\ApprovePriceChangeAction;
use App\Actions\PriceChange\CancelPriceChangeAction;
use App\Actions\PriceChange\CreatePriceChangeAction;
use App\Actions\PriceChange\UpdatePriceChangeAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocPriceChange;
use App\Models\DocPriceChangeDetail;
use App\Models\MasterProduk;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PriceChangeController extends BaseApiController
{
    /**
     * Alasan options for frontend.
     */
    private const ALASAN_OPTIONS = [
        ['value' => 'PENYESUAIAN_PASAR', 'label' => 'Penyesuaian Harga Pasar'],
        ['value' => 'KENAIKAN_BIAYA', 'label' => 'Kenaikan Biaya Operasional'],
        ['value' => 'PROMO', 'label' => 'Program Promo'],
        ['value' => 'KOREKSI_DATA', 'label' => 'Koreksi Data'],
        ['value' => 'LAINNYA', 'label' => 'Lainnya'],
    ];

    /**
     * Get validation rules.
     */
    private function getValidationRules(): array
    {
        $priceMode = SettingService::getPriceInputMode();

        $rules = [
            'tanggal_pengajuan' => 'required|date',
            'tanggal_berlaku' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:master_produk,id',
            'details.*.harga_1_baru' => 'required|numeric|gte:0',
            'details.*.alasan' => 'required|in:PENYESUAIAN_PASAR,KENAIKAN_BIAYA,PROMO,KOREKSI_DATA,LAINNYA',
            'details.*.notes' => 'nullable|string|max:255',
        ];

        // If manual mode, require all prices
        if ($priceMode === 'manual') {
            $rules['details.*.harga_2_baru'] = 'required|numeric|gte:0';
            $rules['details.*.harga_3_baru'] = 'required|numeric|gte:0';
            $rules['details.*.harga_4_baru'] = 'required|numeric|gte:0';
        }

        return $rules;
    }

    /**
     * Get validation messages.
     */
    private function getValidationMessages(): array
    {
        return [
            'tanggal_pengajuan.required' => 'Tanggal pengajuan wajib diisi.',
            'tanggal_berlaku.required' => 'Tanggal berlaku wajib diisi.',
            'details.required' => 'Minimal harus ada 1 detail produk.',
            'details.min' => 'Minimal harus ada 1 detail produk.',
            'details.*.product_id.required' => 'Produk wajib dipilih.',
            'details.*.product_id.exists' => 'Produk tidak valid.',
            'details.*.harga_1_baru.required' => 'Harga baru wajib diisi.',
            'details.*.harga_1_baru.gte' => 'Harga baru tidak boleh negatif.',
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
     * Display a listing of price changes.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('price-change.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat perubahan harga.');
        }

        $query = DocPriceChange::with([
                'createdBy:id,name',
                'approvedBy:id,name',
                'appliedBy:id,name',
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

        // Filter by date range (tanggal_pengajuan)
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal_pengajuan');
        $sortOrder = $request->input('sort_order', 'desc');

        $sortableFields = ['nomor_dokumen', 'tanggal_pengajuan', 'tanggal_berlaku', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal_pengajuan', 'desc');
        }

        // Paginate
        $perPage = $this->getPerPage($request, 15);
        $priceChanges = $query->paginate($perPage);

        return $this->success([
            'items' => $priceChanges->items(),
            'pagination' => [
                'current_page' => $priceChanges->currentPage(),
                'last_page' => $priceChanges->lastPage(),
                'per_page' => $priceChanges->perPage(),
                'total' => $priceChanges->total(),
            ],
        ]);
    }

    /**
     * Store a newly created price change.
     */
    public function store(Request $request, CreatePriceChangeAction $action): JsonResponse
    {
        if (!auth()->user()->can('price-change.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat perubahan harga.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu dokumen perubahan harga.']],
                'Validasi gagal'
            );
        }

        // Validate LAINNYA notes
        $lainnyaErrors = $this->validateLainnyaNotes($validated['details']);
        if (!empty($lainnyaErrors)) {
            return $this->validationError($lainnyaErrors, 'Validasi gagal');
        }

        try {
            $priceChange = $action->execute($validated);

            return $this->created([
                'price_change' => $priceChange,
            ], 'Perubahan harga berhasil dibuat.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal membuat perubahan harga: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified price change.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('price-change.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat perubahan harga.');
        }

        $priceChange = DocPriceChange::with([
            'details.product:id,ulid,kode_produk,nama_produk,barcode,harga_1,harga_2,harga_3,harga_4,unit_1,unit_2,unit_3,unit_4,konversi_1,konversi_2,konversi_3,konversi_4',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'approvedBy:id,name,email',
            'appliedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (!$priceChange) {
            return $this->notFound('Perubahan harga tidak ditemukan.');
        }

        // Make hidden IDs visible for form binding
        foreach ($priceChange->details as $detail) {
            $detail->makeVisible('product_id');
            if ($detail->product) {
                $detail->product->makeVisible('id');
            }
        }

        return $this->success([
            'price_change' => $priceChange,
            'alasan_options' => self::ALASAN_OPTIONS,
            'price_mode' => SettingService::getPriceInputMode(),
        ]);
    }

    /**
     * Update the specified price change.
     */
    public function update(Request $request, string $ulid, UpdatePriceChangeAction $action): JsonResponse
    {
        if (!auth()->user()->can('price-change.update')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah perubahan harga.');
        }

        $priceChange = DocPriceChange::where('ulid', $ulid)->first();

        if (!$priceChange) {
            return $this->notFound('Perubahan harga tidak ditemukan.');
        }

        if (!$priceChange->isDraft()) {
            return $this->error('Hanya dokumen dengan status draft yang dapat diedit.', 422);
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu dokumen perubahan harga.']],
                'Validasi gagal'
            );
        }

        // Validate LAINNYA notes
        $lainnyaErrors = $this->validateLainnyaNotes($validated['details']);
        if (!empty($lainnyaErrors)) {
            return $this->validationError($lainnyaErrors, 'Validasi gagal');
        }

        try {
            $priceChange = $action->execute($priceChange, $validated);

            return $this->success([
                'price_change' => $priceChange,
            ], 'Perubahan harga berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui perubahan harga: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified price change.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('price-change.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus perubahan harga.');
        }

        $priceChange = DocPriceChange::where('ulid', $ulid)->first();

        if (!$priceChange) {
            return $this->notFound('Perubahan harga tidak ditemukan.');
        }

        if (!$priceChange->isDraft()) {
            return $this->error('Hanya dokumen dengan status draft yang dapat dihapus.', 422);
        }

        $priceChange->delete();

        return $this->success(null, 'Perubahan harga berhasil dihapus.');
    }

    /**
     * Approve the specified price change (draft → scheduled).
     */
    public function approve(string $ulid, ApprovePriceChangeAction $action): JsonResponse
    {
        if (!auth()->user()->can('price-change.approve')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menyetujui perubahan harga.');
        }

        $priceChange = DocPriceChange::where('ulid', $ulid)->first();

        if (!$priceChange) {
            return $this->notFound('Perubahan harga tidak ditemukan.');
        }

        try {
            $priceChange = $action->execute($priceChange);

            return $this->success([
                'price_change' => $priceChange,
            ], 'Perubahan harga berhasil disetujui dan dijadwalkan.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal menyetujui perubahan harga: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Cancel the specified price change (scheduled → draft).
     */
    public function cancel(string $ulid, CancelPriceChangeAction $action): JsonResponse
    {
        if (!auth()->user()->can('price-change.approve')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membatalkan perubahan harga.');
        }

        $priceChange = DocPriceChange::where('ulid', $ulid)->first();

        if (!$priceChange) {
            return $this->notFound('Perubahan harga tidak ditemukan.');
        }

        try {
            $priceChange = $action->execute($priceChange);

            return $this->success([
                'price_change' => $priceChange,
            ], 'Perubahan harga berhasil dibatalkan dan dikembalikan ke draft.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal membatalkan perubahan harga: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Apply the specified price change (scheduled → applied).
     */
    public function apply(string $ulid, ApplyPriceChangeAction $action): JsonResponse
    {
        if (!auth()->user()->can('price-change.apply')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menerapkan perubahan harga.');
        }

        $priceChange = DocPriceChange::where('ulid', $ulid)->first();

        if (!$priceChange) {
            return $this->notFound('Perubahan harga tidak ditemukan.');
        }

        try {
            $priceChange = $action->execute($priceChange, auth()->id(), 'manual');

            return $this->success([
                'price_change' => $priceChange,
            ], 'Perubahan harga berhasil diterapkan.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal menerapkan perubahan harga: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get products for autocomplete.
     * Returns all products with lock info (instead of excluding locked products).
     */
    public function getProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('price-change.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'search' => 'nullable|string|max:100',
            'exclude_document_ulid' => 'nullable|string|max:26',
        ]);

        $search = $request->search;

        // Get exclude document ID if provided (for edit mode self-exclusion)
        $excludeDocumentId = null;
        if ($request->filled('exclude_document_ulid')) {
            $doc = DocPriceChange::where('ulid', $request->exclude_document_ulid)->first();
            $excludeDocumentId = $doc?->id;
        }

        // Get locked products with info (which draft locks them)
        $lockedProductsInfo = $this->getLockedProductsWithInfo($excludeDocumentId);

        $query = MasterProduk::active()
            ->where('is_serial', false) // produk serial pakai menu Perubahan Harga Serial
            ->select('id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'harga_1', 'harga_2', 'harga_3', 'harga_4', 'unit_1', 'unit_2', 'unit_3', 'unit_4', 'konversi_1', 'konversi_2', 'konversi_3', 'konversi_4');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('kode_produk', 'like', "%{$search}%")
                  ->orWhere('nama_produk', 'like', "%{$search}%")
                  ->orWhere('barcode', 'like', "%{$search}%");
            });
        }

        // Don't exclude locked products - we'll return them with lock info

        $products = $query->limit(20)->get();

        // Map to array with explicit id and lock info
        $items = $products->map(function ($product) use ($lockedProductsInfo) {
            $lockInfo = $lockedProductsInfo[$product->id] ?? null;

            return [
                'id' => $product->id,
                'ulid' => $product->ulid,
                'kode_produk' => $product->kode_produk,
                'nama_produk' => $product->nama_produk,
                'barcode' => $product->barcode,
                'harga_1' => (float) $product->harga_1,
                'harga_2' => (float) $product->harga_2,
                'harga_3' => (float) $product->harga_3,
                'harga_4' => (float) $product->harga_4,
                'unit_1' => $product->unit_1,
                'unit_2' => $product->unit_2,
                'unit_3' => $product->unit_3,
                'unit_4' => $product->unit_4,
                'konversi_1' => (float) $product->konversi_1,
                'konversi_2' => (float) $product->konversi_2,
                'konversi_3' => (float) $product->konversi_3,
                'konversi_4' => (float) $product->konversi_4,
                'locked_by' => $lockInfo,
            ];
        });

        return $this->success([
            'items' => $items,
        ]);
    }

    /**
     * Get locked products with document info.
     */
    public function getLockedProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('price-change.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'exclude_document_ulid' => 'nullable|string|max:26',
        ]);

        // Get exclude document ID if provided (for edit mode self-exclusion)
        $excludeDocumentId = null;
        if ($request->filled('exclude_document_ulid')) {
            $doc = DocPriceChange::where('ulid', $request->exclude_document_ulid)->first();
            $excludeDocumentId = $doc?->id;
        }

        $lockedProductsInfo = $this->getLockedProductsWithInfo($excludeDocumentId);

        // Also return just the IDs for backward compatibility
        $productIds = array_keys($lockedProductsInfo);

        return $this->success([
            'product_ids' => $productIds,
            'locked_products' => $lockedProductsInfo,
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
     * Get count of pending (scheduled and due) price changes.
     */
    public function getPendingCount(): JsonResponse
    {
        if (!auth()->user()->can('price-change.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $count = DocPriceChange::pending()->count();

        return $this->success([
            'pending_count' => $count,
        ]);
    }

    /**
     * Get IDs of products that are locked (in any draft or scheduled document).
     * @deprecated Use getLockedProductsWithInfo() instead
     */
    private function getLockedProductIds(): array
    {
        return DocPriceChangeDetail::whereHas('priceChange', function ($query) {
            $query->whereIn('status', ['draft', 'scheduled']);
        })->pluck('product_id')->toArray();
    }

    /**
     * Get locked products with document info.
     * Returns array keyed by product_id with document info as value.
     */
    private function getLockedProductsWithInfo(?int $excludeDocumentId = null): array
    {
        $query = DocPriceChangeDetail::with(['priceChange:id,ulid,nomor_dokumen,status'])
            ->whereHas('priceChange', function ($q) use ($excludeDocumentId) {
                $q->whereIn('status', ['draft', 'scheduled']);
                if ($excludeDocumentId) {
                    $q->where('id', '!=', $excludeDocumentId);
                }
            });

        $details = $query->get();

        $result = [];
        foreach ($details as $detail) {
            $result[$detail->product_id] = [
                'ulid' => $detail->priceChange->ulid,
                'nomor_dokumen' => $detail->priceChange->nomor_dokumen,
                'status' => $detail->priceChange->status,
            ];
        }

        return $result;
    }

    /**
     * Check if there are other draft/scheduled documents.
     */
    public function hasOtherDrafts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('price-change.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'exclude_document_ulid' => 'nullable|string|max:26',
        ]);

        $query = DocPriceChange::whereIn('status', ['draft', 'scheduled']);

        if ($request->filled('exclude_document_ulid')) {
            $query->where('ulid', '!=', $request->exclude_document_ulid);
        }

        $count = $query->count();
        $drafts = $query->select('ulid', 'nomor_dokumen', 'status')
            ->orderBy('created_at', 'desc')
            ->limit(5)
            ->get();

        return $this->success([
            'has_other_drafts' => $count > 0,
            'count' => $count,
            'drafts' => $drafts,
        ]);
    }
}
