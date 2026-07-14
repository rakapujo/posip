<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PurchaseOrder\ApprovePurchaseOrderAction;
use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Actions\PurchaseOrder\UpdatePurchaseOrderAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocPurchaseOrder;
use App\Models\HistoryHargaBeli;
use App\Models\MasterProduk;
use App\Services\PurchaseMasterRules;
use App\Services\PurchaseOrderCalculationService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchaseOrderController extends BaseApiController
{
    /**
     * Get validation rules for PO.
     */
    private function getValidationRules(): array
    {
        return [
            'tanggal_po' => 'required|date',
            'supplier_id' => 'required|exists:master_supplier,id',
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'no_doc_referensi' => 'nullable|string|max:100',
            'tempo_hari' => 'nullable|integer|min:0',
            'notes' => 'nullable|string|max:1000',

            // Cash / lunas langsung — hutang dibuat lalu auto-lunas saat approve.
            // Panjang max disamakan dgn kolom doc_pembayaran_hutang (no_referensi/bank_nama 50, bank_rekening 30)
            // agar tak rollback saat settle. Metode wajib bila cash dicentang (cegah fallback diam ke 'cash').
            'cash_payment' => 'nullable|boolean',
            'cash_metode' => 'nullable|required_if:cash_payment,true,1|in:cash,transfer',
            'cash_no_referensi' => 'nullable|string|max:50',
            'cash_bank_nama' => 'nullable|string|max:50',
            'cash_bank_rekening' => 'nullable|string|max:30',

            // Header discounts (3 lines)
            'diskon_1_tipe' => 'nullable|in:percent,nominal,none',
            'diskon_1_nilai' => 'nullable|numeric|min:0',
            'diskon_2_tipe' => 'nullable|in:percent,nominal,none',
            'diskon_2_nilai' => 'nullable|numeric|min:0',
            'diskon_3_tipe' => 'nullable|in:percent,nominal,none',
            'diskon_3_nilai' => 'nullable|numeric|min:0',

            // Additional costs
            'biaya_kirim_tipe' => 'nullable|in:percent,nominal,none',
            'biaya_kirim_nilai' => 'nullable|numeric|min:0',
            'biaya_lain_nama' => 'nullable|string|max:100',
            'biaya_lain_tipe' => 'nullable|in:percent,nominal,none',
            'biaya_lain_nilai' => 'nullable|numeric|min:0',

            // Details
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:master_produk,id',
            'details.*.unit_used' => 'required|string|max:30',
            'details.*.unit_konversi' => 'required|integer|min:1',
            'details.*.qty_in_unit' => 'required|numeric|min:1',
            'details.*.harga_per_unit' => 'required|numeric|min:0',

            // Detail discounts (5 lines)
            'details.*.diskon_1_tipe' => 'nullable|in:percent,nominal,none',
            'details.*.diskon_1_nilai' => 'nullable|numeric|min:0',
            'details.*.diskon_2_tipe' => 'nullable|in:percent,nominal,none',
            'details.*.diskon_2_nilai' => 'nullable|numeric|min:0',
            'details.*.diskon_3_tipe' => 'nullable|in:percent,nominal,none',
            'details.*.diskon_3_nilai' => 'nullable|numeric|min:0',
            'details.*.diskon_4_tipe' => 'nullable|in:percent,nominal,none',
            'details.*.diskon_4_nilai' => 'nullable|numeric|min:0',
            'details.*.diskon_5_tipe' => 'nullable|in:percent,nominal,none',
            'details.*.diskon_5_nilai' => 'nullable|numeric|min:0',
        ];
    }

    /**
     * Get validation messages for PO.
     */
    private function getValidationMessages(): array
    {
        return [
            'tanggal_po.required' => 'Tanggal PO wajib diisi.',
            'supplier_id.required' => 'Supplier wajib dipilih.',
            'supplier_id.exists' => 'Supplier tidak valid.',
            'warehouse_id.required' => 'Warehouse wajib dipilih.',
            'warehouse_id.exists' => 'Warehouse tidak valid.',
            'details.required' => 'Minimal harus ada 1 detail produk.',
            'details.min' => 'Minimal harus ada 1 detail produk.',
            'details.*.product_id.required' => 'Produk wajib dipilih.',
            'details.*.product_id.exists' => 'Produk tidak valid.',
            'details.*.unit_used.required' => 'Satuan wajib dipilih.',
            'details.*.unit_konversi.required' => 'Konversi satuan wajib diisi.',
            'details.*.qty_in_unit.required' => 'Qty wajib diisi.',
            'details.*.qty_in_unit.min' => 'Qty minimal 1.',
            'details.*.harga_per_unit.required' => 'Harga wajib diisi.',
            'details.*.harga_per_unit.min' => 'Harga tidak boleh negatif.',
        ];
    }

    /**
     * Check for duplicate products in details.
     */
    private function hasDuplicateProducts(array $details): bool
    {
        $keys = collect($details)->map(function ($detail) {
            return $detail['product_id'] . '-' . $detail['unit_used'];
        });
        return $keys->count() !== $keys->unique()->count();
    }

    private function validatePoMasterReferences(array $validated): ?JsonResponse
    {
        $errors = array_merge(
            PurchaseMasterRules::supplierAndWarehouseErrors(
                $validated['supplier_id'],
                $validated['warehouse_id'],
            ) ?? [],
            PurchaseMasterRules::poDetailProductErrors($validated['details']) ?? [],
        );

        if ($errors !== []) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        return null;
    }

    private function validatePoDocumentState(DocPurchaseOrder $po): ?JsonResponse
    {
        if ($errors = PurchaseMasterRules::poDocumentErrors($po)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        return null;
    }

    /**
     * Display a listing of POs.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('po.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat purchase order.');
        }

        $query = DocPurchaseOrder::with([
                'supplier:id,ulid,kode_supplier,nama_supplier',
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
                'createdBy:id,name',
            ])
            ->withCount('details');

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by supplier
        if ($request->filled('supplier_id')) {
            $query->bySupplier($request->supplier_id);
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
        $sortField = $request->input('sort_field', 'tanggal_po');
        $sortOrder = $request->input('sort_order', 'desc');

        // Map sort fields
        $sortableFields = ['nomor_dokumen', 'tanggal_po', 'grand_total', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal_po', 'desc');
        }

        // Paginate
        $perPage = $this->getPerPage($request, 15);
        $items = $query->paginate($perPage);

        // Check if user can view harga
        $canViewHarga = auth()->user()->can('po.view_harga');

        // Hide sensitive fields if not allowed
        $transformedItems = collect($items->items())->map(function ($item) use ($canViewHarga) {
            if (!$canViewHarga) {
                $item->makeHidden([
                    'subtotal', 'total_diskon_header', 'total_setelah_diskon',
                    'total_biaya_tambahan', 'dpp', 'pajak_nominal', 'pembulatan', 'grand_total',
                ]);
            }
            return $item;
        });

        return $this->success([
            'items' => $transformedItems,
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Get simple list of approved POs for dropdown.
     */
    public function list(Request $request): JsonResponse
    {
        if (!auth()->user()->can('po.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $query = DocPurchaseOrder::select(['id', 'ulid', 'nomor_dokumen', 'tanggal_po', 'supplier_id'])
            ->approved()
            ->orderBy('tanggal_po', 'desc');

        // Filter by supplier if provided
        if ($request->filled('supplier_id')) {
            $query->bySupplier($request->supplier_id);
        }

        $items = $query->limit(100)->get();

        // Make 'id' visible since it's in $hidden by default
        $items->each(function ($item) {
            $item->makeVisible('id');
        });

        return $this->success([
            'items' => $items,
        ]);
    }

    /**
     * Store a newly created PO.
     */
    public function store(Request $request, CreatePurchaseOrderAction $action): JsonResponse
    {
        if (!auth()->user()->can('po.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat purchase order.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu PO.']],
                'Validasi gagal'
            );
        }

        if ($response = $this->validatePoMasterReferences($validated)) {
            return $response;
        }

        try {
            $po = $action->execute($validated);

            return $this->created([
                'purchase_order' => $po,
            ], 'Purchase order berhasil dibuat.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Gagal membuat purchase order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified PO.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('po.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat purchase order.');
        }

        $po = DocPurchaseOrder::with([
            'supplier:id,ulid,kode_supplier,nama_supplier,tempo_default',
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'details.product:id,ulid,kode_produk,nama_produk,barcode,unit_1,konversi_1,unit_2,konversi_2,unit_3,konversi_3,unit_4,konversi_4',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'approvedBy:id,name,email',
            'hutang',
        ])->where('ulid', $ulid)->first();

        if (!$po) {
            return $this->notFound('Purchase order tidak ditemukan.');
        }

        // Check if user can view harga
        $canViewHarga = auth()->user()->can('po.view_harga');

        // Make hidden IDs visible for form binding
        $po->makeVisible(['supplier_id', 'warehouse_id']);
        if ($po->supplier) {
            $po->supplier->makeVisible('id');
        }
        if ($po->warehouse) {
            $po->warehouse->makeVisible('id');
        }

        foreach ($po->details as $detail) {
            $detail->makeVisible('product_id');
            if ($detail->product) {
                $detail->product->makeVisible('id');
            }

            // Hide sensitive fields if not allowed
            if (!$canViewHarga) {
                $detail->makeHidden([
                    'harga_per_unit', 'harga_per_base', 'harga_bruto',
                    'diskon_1_hasil', 'diskon_2_hasil', 'diskon_3_hasil',
                    'diskon_4_hasil', 'diskon_5_hasil', 'total_diskon_item',
                    'subtotal', 'cost_per_unit',
                ]);
            }
        }

        // Hide sensitive header fields if not allowed
        if (!$canViewHarga) {
            $po->makeHidden([
                'subtotal', 'diskon_1_hasil', 'diskon_2_hasil', 'diskon_3_hasil',
                'total_diskon_header', 'total_setelah_diskon',
                'biaya_kirim_hasil', 'biaya_lain_hasil', 'total_biaya_tambahan',
                'dpp', 'pajak_nominal', 'pembulatan', 'grand_total',
            ]);
        }

        return $this->success([
            'purchase_order' => $po,
        ]);
    }

    /**
     * Update the specified PO.
     */
    public function update(Request $request, string $ulid, UpdatePurchaseOrderAction $action): JsonResponse
    {
        if (!auth()->user()->can('po.edit')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah purchase order.');
        }

        $po = DocPurchaseOrder::where('ulid', $ulid)->first();

        if (!$po) {
            return $this->notFound('Purchase order tidak ditemukan.');
        }

        if (!$po->isDraft()) {
            return $this->error('Hanya PO dengan status draft yang dapat diedit.', 422);
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu PO.']],
                'Validasi gagal'
            );
        }

        if ($response = $this->validatePoMasterReferences($validated)) {
            return $response;
        }

        try {
            $po = $action->execute($po, $validated);

            return $this->success([
                'purchase_order' => $po,
            ], 'Purchase order berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui purchase order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified PO.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('po.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus purchase order.');
        }

        $po = DocPurchaseOrder::where('ulid', $ulid)->first();

        if (!$po) {
            return $this->notFound('Purchase order tidak ditemukan.');
        }

        if (!$po->isDraft()) {
            return $this->error('Hanya PO dengan status draft yang dapat dihapus.', 422);
        }

        $po->delete();

        return $this->success(null, 'Purchase order berhasil dihapus.');
    }

    /**
     * Approve the specified PO.
     */
    public function approve(string $ulid, ApprovePurchaseOrderAction $action): JsonResponse
    {
        if (!auth()->user()->can('po.approve')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menyetujui purchase order.');
        }

        $po = DocPurchaseOrder::where('ulid', $ulid)->first();

        if (!$po) {
            return $this->notFound('Purchase order tidak ditemukan.');
        }

        if ($response = $this->validatePoDocumentState($po)) {
            return $response;
        }

        try {
            $po = $action->execute($po);

            return $this->success([
                'purchase_order' => $po,
            ], 'Purchase order berhasil disetujui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal menyetujui purchase order: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get products for PO form.
     */
    public function getProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('po.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'search' => 'nullable|string|max:100',
        ]);

        $search = $request->search;

        $query = MasterProduk::active()
            ->where('is_serial', false) // produk serial dibeli lewat PO Serial, bukan PO standar
            ->select([
                'id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'avg_cost',
                'unit_1', 'konversi_1', 'harga_1',
                'unit_2', 'konversi_2', 'harga_2',
                'unit_3', 'konversi_3', 'harga_3',
                'unit_4', 'konversi_4', 'harga_4',
            ]);

        if ($search) {
            $query->search($search);
        }

        $products = $query->limit(20)->get();

        // Transform to include units array (filter duplicates)
        $items = $products->map(function ($product) {
            $units = [];
            $seenUnits = [];

            for ($i = 1; $i <= 4; $i++) {
                $unit = $product->{"unit_{$i}"};
                // Skip if unit is empty or already added (duplicate)
                if ($unit && !in_array($unit, $seenUnits)) {
                    $seenUnits[] = $unit;
                    $units[] = [
                        'unit' => $unit,
                        'konversi' => $product->{"konversi_{$i}"},
                        'harga_jual' => $product->{"harga_{$i}"},
                    ];
                }
            }

            return [
                'id' => $product->id,
                'ulid' => $product->ulid,
                'kode_produk' => $product->kode_produk,
                'nama_produk' => $product->nama_produk,
                'barcode' => $product->barcode,
                'avg_cost' => $product->avg_cost,
                'units' => $units,
            ];
        });

        return $this->success([
            'items' => $items,
        ]);
    }

    /**
     * Get last purchase price for a product.
     */
    public function getLastPrice(Request $request): JsonResponse
    {
        if (!auth()->user()->can('po.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'product_id' => 'required|exists:master_produk,id',
            'supplier_id' => 'nullable|exists:master_supplier,id',
            'unit' => 'nullable|string|max:30',
        ]);

        $lastPrice = HistoryHargaBeli::getLastPrice(
            $request->product_id,
            $request->supplier_id,
            $request->unit
        );

        if (!$lastPrice) {
            return $this->success([
                'last_price' => null,
            ]);
        }

        return $this->success([
            'last_price' => [
                'tanggal' => $lastPrice->tanggal,
                'unit_used' => $lastPrice->unit_used,
                'harga_per_unit' => $lastPrice->harga_per_unit,
                'harga_per_base' => $lastPrice->harga_per_base,
                'qty_in_unit' => $lastPrice->qty_in_unit,
            ],
        ]);
    }

    /**
     * Get price history for a product.
     */
    public function getPriceHistory(Request $request): JsonResponse
    {
        if (!auth()->user()->can('po.view_harga')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'product_id' => 'required|exists:master_produk,id',
            'supplier_id' => 'nullable|exists:master_supplier,id',
            'unit' => 'nullable|string|max:30',
            'limit' => 'nullable|integer|min:1|max:50',
        ]);

        $history = HistoryHargaBeli::getPriceHistory(
            $request->product_id,
            $request->supplier_id,
            $request->unit,
            $request->input('limit', 10)
        );

        return $this->success([
            'items' => $history,
        ]);
    }

    /**
     * Calculate PO totals (preview before save).
     */
    public function calculate(Request $request): JsonResponse
    {
        if (!auth()->user()->can('po.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        try {
            $calculated = PurchaseOrderCalculationService::calculateTotals($validated);

            return $this->success([
                'calculation' => $calculated,
            ]);
        } catch (\Exception $e) {
            return $this->error('Gagal menghitung: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get tax settings for frontend.
     */
    public function getTaxSettings(): JsonResponse
    {
        return $this->success([
            'tax' => SettingService::getPurchaseTaxSettings(),
        ]);
    }
}
