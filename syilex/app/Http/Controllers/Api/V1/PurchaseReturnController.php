<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PurchaseReturn\ApprovePurchaseReturnAction;
use App\Actions\PurchaseReturn\CreatePurchaseReturnAction;
use App\Actions\PurchaseReturn\LockPurchaseReturnAction;
use App\Actions\PurchaseReturn\UpdatePurchaseReturnAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\AttachesSerialUnitsToDocDetails;
use App\Models\DocPurchaseOrder;
use App\Models\DocPurchaseOrderDetail;
use App\Models\DocPurchaseReturn;
use App\Models\DocPurchaseReturnDetail;
use App\Models\HistoryHargaBeli;
use App\Models\MasterProduk;
use App\Services\PurchaseReturnCalculationService;
use App\Services\PurchaseMasterRules;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PurchaseReturnController extends BaseApiController
{
    use AttachesSerialUnitsToDocDetails;

    /**
     * Get validation rules for Purchase Return.
     */
    private function getValidationRules(): array
    {
        return [
            'tanggal' => 'required|date',
            'supplier_id' => 'required|exists:master_supplier,id',
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'po_id' => 'nullable|exists:doc_purchase_order,id',
            'notes' => 'nullable|string|max:1000',

            // Header discounts (3 lines)
            'diskon_1_tipe' => 'nullable|in:percent,nominal,none',
            'diskon_1_nilai' => 'nullable|numeric|min:0',
            'diskon_2_tipe' => 'nullable|in:percent,nominal,none',
            'diskon_2_nilai' => 'nullable|numeric|min:0',
            'diskon_3_tipe' => 'nullable|in:percent,nominal,none',
            'diskon_3_nilai' => 'nullable|numeric|min:0',

            // Details
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:master_produk,id',
            'details.*.po_detail_id' => 'nullable|exists:doc_purchase_order_detail,id',
            'details.*.unit_used' => 'required|string|max:30',
            'details.*.unit_konversi' => 'required|integer|min:1',
            'details.*.qty_in_unit' => 'required|numeric|min:0.0001',
            'details.*.harga_per_unit' => 'required|numeric|min:0',
            'details.*.serial_unit_ids' => 'nullable|array',
            'details.*.serial_unit_ids.*' => 'string',

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
     * Get validation messages.
     */
    private function getValidationMessages(): array
    {
        return [
            'tanggal.required' => 'Tanggal wajib diisi.',
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
            'details.*.qty_in_unit.min' => 'Qty minimal harus lebih dari 0.',
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

    private function validatePurchaseReturnMasterReferences(array $validated): ?JsonResponse
    {
        $errors = array_merge(
            PurchaseMasterRules::supplierAndWarehouseErrors(
                $validated['supplier_id'],
                $validated['warehouse_id'],
            ) ?? [],
            PurchaseMasterRules::returDetailProductErrors($validated['details']) ?? [],
        );

        if ($errors !== []) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        return null;
    }

    private function validatePurchaseReturnDocumentState(DocPurchaseReturn $retur): ?JsonResponse
    {
        if ($errors = PurchaseMasterRules::purchaseReturnDocumentErrors($retur)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        return null;
    }

    /**
     * Display a listing of purchase returns.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat retur pembelian.');
        }

        $query = DocPurchaseReturn::with([
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
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');

        // Map sort fields
        $sortableFields = ['nomor_dokumen', 'tanggal', 'nilai_kalkulasi', 'nilai_diakui', 'created_at'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal', 'desc');
        }

        // Paginate
        $perPage = $this->getPerPage($request, 15);
        $items = $query->paginate($perPage);

        return $this->success([
            'items' => $items->items(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
            ],
        ]);
    }

    /**
     * Store a newly created purchase return.
     */
    public function store(Request $request, CreatePurchaseReturnAction $action): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat retur pembelian.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu retur.']],
                'Validasi gagal'
            );
        }

        if ($response = $this->validatePurchaseReturnMasterReferences($validated)) {
            return $response;
        }

        try {
            $retur = $action->execute($validated);

            return $this->created([
                'purchase_return' => $retur,
            ], 'Retur pembelian berhasil dibuat.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Gagal membuat retur pembelian: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified purchase return.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat retur pembelian.');
        }

        $retur = DocPurchaseReturn::with([
            'supplier:id,ulid,kode_supplier,nama_supplier',
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'purchaseOrder:id,ulid,nomor_dokumen',
            'details.product:id,ulid,kode_produk,nama_produk,barcode,is_serial,unit_1,konversi_1,unit_2,konversi_2,unit_3,konversi_3,unit_4,konversi_4',
            'deposit',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'lockedBy:id,name,email',
            'approvedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (!$retur) {
            return $this->notFound('Retur pembelian tidak ditemukan.');
        }

        // Make hidden IDs visible for form binding
        $retur->makeVisible(['supplier_id', 'warehouse_id', 'po_id']);
        if ($retur->supplier) {
            $retur->supplier->makeVisible('id');
        }
        if ($retur->warehouse) {
            $retur->warehouse->makeVisible('id');
        }
        if ($retur->purchaseOrder) {
            $retur->purchaseOrder->makeVisible('id');
        }

        // Tempelkan rincian unit serial (kode_internal/SN) untuk tampil di detail + PDF
        $this->attachDocSerialUnits($retur->details);

        // Calculate qty_max for each detail if PO is linked
        $qtyMaxMap = [];
        if ($retur->po_id) {
            // Get all po_detail_ids from current retur details
            $poDetailIds = $retur->details->pluck('po_detail_id')->filter()->toArray();

            if (!empty($poDetailIds)) {
                // Get qty already returned for each PO detail (excluding current return's details)
                $returnedQtys = DocPurchaseReturnDetail::whereIn('po_detail_id', $poDetailIds)
                    ->where('retur_id', '!=', $retur->id) // Exclude current return
                    ->whereHas('purchaseReturn', function ($q) {
                        $q->whereIn('status', ['lock', 'approved']);
                    })
                    ->selectRaw('po_detail_id, SUM(qty_in_base) as total_returned')
                    ->groupBy('po_detail_id')
                    ->pluck('total_returned', 'po_detail_id');

                // Get PO details to know original qty
                $poDetails = DocPurchaseOrderDetail::whereIn('id', $poDetailIds)
                    ->get()
                    ->keyBy('id');

                foreach ($poDetailIds as $poDetailId) {
                    if (isset($poDetails[$poDetailId])) {
                        $poDetail = $poDetails[$poDetailId];
                        $qtyOrdered = (float) $poDetail->qty_in_base;
                        $qtyReturned = (float) ($returnedQtys[$poDetailId] ?? 0);
                        $qtyAvailable = $qtyOrdered - $qtyReturned;

                        // Convert to unit
                        $qtyMaxInUnit = $poDetail->unit_konversi > 0
                            ? $qtyAvailable / $poDetail->unit_konversi
                            : $qtyAvailable;

                        $qtyMaxMap[$poDetailId] = $qtyMaxInUnit;
                    }
                }
            }
        }

        foreach ($retur->details as $detail) {
            $detail->makeVisible(['product_id', 'po_detail_id']);
            if ($detail->product) {
                $detail->product->makeVisible('id');
            }
            // Add qty_max for frontend validation
            if ($detail->po_detail_id && isset($qtyMaxMap[$detail->po_detail_id])) {
                $detail->qty_max = $qtyMaxMap[$detail->po_detail_id];
            }
        }

        return $this->success([
            'purchase_return' => $retur,
        ]);
    }

    /**
     * Update the specified purchase return.
     */
    public function update(Request $request, string $ulid, UpdatePurchaseReturnAction $action): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.update')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah retur pembelian.');
        }

        $retur = DocPurchaseReturn::where('ulid', $ulid)->first();

        if (!$retur) {
            return $this->notFound('Retur pembelian tidak ditemukan.');
        }

        if (!$retur->isDraft()) {
            return $this->error('Hanya retur dengan status draft yang dapat diedit.', 422);
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu retur.']],
                'Validasi gagal'
            );
        }

        if ($response = $this->validatePurchaseReturnMasterReferences($validated)) {
            return $response;
        }

        try {
            $retur = $action->execute($retur, $validated);

            return $this->success([
                'purchase_return' => $retur,
            ], 'Retur pembelian berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui retur pembelian: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified purchase return.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus retur pembelian.');
        }

        $retur = DocPurchaseReturn::where('ulid', $ulid)->first();

        if (!$retur) {
            return $this->notFound('Retur pembelian tidak ditemukan.');
        }

        if (!$retur->isDraft()) {
            return $this->error('Hanya retur dengan status draft yang dapat dihapus.', 422);
        }

        $retur->delete();

        return $this->success(null, 'Retur pembelian berhasil dihapus.');
    }

    /**
     * Lock the specified purchase return (stock out).
     */
    public function lock(string $ulid, LockPurchaseReturnAction $action): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.lock')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengunci retur pembelian.');
        }

        $retur = DocPurchaseReturn::where('ulid', $ulid)->first();

        if (!$retur) {
            return $this->notFound('Retur pembelian tidak ditemukan.');
        }

        if ($response = $this->validatePurchaseReturnDocumentState($retur)) {
            return $response;
        }

        try {
            $retur = $action->execute($retur);

            return $this->success([
                'purchase_return' => $retur,
            ], 'Retur pembelian berhasil dikunci. Stok telah dikurangi.');
        } catch (ValidationException $e) {
            // Build detailed error message
            $errors = $e->errors();
            $errorMessages = [];

            // Collect all error messages from different keys
            foreach ($errors as $key => $messages) {
                if (is_array($messages)) {
                    $errorMessages = array_merge($errorMessages, $messages);
                } else {
                    $errorMessages[] = $messages;
                }
            }

            $detailMessage = implode("\n", $errorMessages);

            return $this->validationError($errors, $detailMessage ?: 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal mengunci retur pembelian: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Approve the specified purchase return (create deposit).
     */
    public function approve(Request $request, string $ulid, ApprovePurchaseReturnAction $action): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.approve')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menyetujui retur pembelian.');
        }

        $retur = DocPurchaseReturn::where('ulid', $ulid)->first();

        if (!$retur) {
            return $this->notFound('Retur pembelian tidak ditemukan.');
        }

        $validated = $request->validate([
            'nilai_diakui' => 'required|numeric|min:0',
            'catatan_approval' => 'nullable|string|max:1000',
        ], [
            'nilai_diakui.required' => 'Nilai diakui wajib diisi.',
            'nilai_diakui.numeric' => 'Nilai diakui harus berupa angka.',
            'nilai_diakui.min' => 'Nilai diakui tidak boleh negatif.',
        ]);

        if ($response = $this->validatePurchaseReturnDocumentState($retur)) {
            return $response;
        }

        try {
            $retur = $action->execute($retur, $validated);

            return $this->success([
                'purchase_return' => $retur,
            ], 'Retur pembelian berhasil disetujui. Deposit supplier telah dibuat.');
        } catch (ValidationException $e) {
            // Build detailed error message
            $errors = $e->errors();
            $errorMessages = [];

            foreach ($errors as $key => $messages) {
                if (is_array($messages)) {
                    $errorMessages = array_merge($errorMessages, $messages);
                } else {
                    $errorMessages[] = $messages;
                }
            }

            $detailMessage = implode("\n", $errorMessages);

            return $this->validationError($errors, $detailMessage ?: 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal menyetujui retur pembelian: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get products for retur form.
     */
    public function getProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'search' => 'nullable|string|max:100',
        ]);

        $search = $request->search;

        $query = MasterProduk::active()
            ->select([
                'id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'avg_cost', 'is_serial',
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
                'is_serial' => (bool) $product->is_serial, // frontend: tampilkan pemilih unit serial
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
        if (!auth()->user()->can('retur-beli.create')) {
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
     * Calculate purchase return totals (preview before save).
     */
    public function calculate(Request $request): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        try {
            $calculated = PurchaseReturnCalculationService::calculateTotals($validated);

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

    /**
     * Get stock setting for frontend.
     */
    public function getStockSetting(): JsonResponse
    {
        return $this->success([
            'allow_negative' => SettingService::isNegativeStockAllowed(),
        ]);
    }

    /**
     * Get returnable details from a PO.
     * Returns PO details with calculated qty that can still be returned.
     */
    public function getReturnableDetails(string $poUlid): JsonResponse
    {
        if (!auth()->user()->can('retur-beli.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        // Get PO with details
        $po = DocPurchaseOrder::with([
            'details.product:id,ulid,kode_produk,nama_produk,barcode,unit_1,konversi_1,unit_2,konversi_2,unit_3,konversi_3,unit_4,konversi_4'
        ])->where('ulid', $poUlid)->first();

        if (!$po) {
            return $this->notFound('Purchase Order tidak ditemukan.');
        }

        // Only approved POs can be returned
        if ($po->status !== 'approved') {
            return $this->error('Hanya PO yang sudah disetujui yang dapat diretur.', 422);
        }

        // Get qty already returned for each PO detail (only lock/approved returns count)
        $returnedQtys = DocPurchaseReturnDetail::whereIn('po_detail_id', $po->details->pluck('id'))
            ->whereHas('purchaseReturn', function ($q) {
                $q->whereIn('status', ['lock', 'approved']);
            })
            ->selectRaw('po_detail_id, SUM(qty_in_base) as total_returned')
            ->groupBy('po_detail_id')
            ->pluck('total_returned', 'po_detail_id');

        // Build returnable details
        $returnableDetails = [];

        foreach ($po->details as $detail) {
            $qtyOrdered = (float) $detail->qty_in_base;
            $qtyReturned = (float) ($returnedQtys[$detail->id] ?? 0);
            $qtyAvailable = $qtyOrdered - $qtyReturned;

            // Skip if fully returned
            if ($qtyAvailable <= 0) {
                continue;
            }

            // Calculate qty_in_unit from qty_available
            $qtyAvailableInUnit = $detail->unit_konversi > 0
                ? $qtyAvailable / $detail->unit_konversi
                : $qtyAvailable;

            // Get product units for dropdown
            $product = $detail->product;
            $units = [];
            $seenUnits = [];

            if ($product) {
                for ($i = 1; $i <= 4; $i++) {
                    $unit = $product->{"unit_{$i}"};
                    if ($unit && !in_array($unit, $seenUnits)) {
                        $seenUnits[] = $unit;
                        $units[] = [
                            'unit' => $unit,
                            'konversi' => $product->{"konversi_{$i}"},
                        ];
                    }
                }
            }

            // Make IDs visible for binding
            $detail->makeVisible(['id', 'product_id']);
            if ($product) {
                $product->makeVisible('id');
            }

            $returnableDetails[] = [
                'po_detail_id' => $detail->id,
                'product_id' => $detail->product_id,
                'product' => $product,
                'units' => $units,
                'unit_used' => $detail->unit_used,
                'unit_konversi' => $detail->unit_konversi,
                'qty_ordered' => $detail->qty_in_unit,
                'qty_ordered_base' => $qtyOrdered,
                'qty_returned' => $qtyReturned,
                'qty_returned_unit' => $detail->unit_konversi > 0
                    ? $qtyReturned / $detail->unit_konversi
                    : $qtyReturned,
                'qty_available' => $qtyAvailable,
                'qty_available_unit' => $qtyAvailableInUnit,
                'harga_per_unit' => $detail->harga_per_unit,
                // Include discounts from PO
                'diskon_1_tipe' => $detail->diskon_1_tipe,
                'diskon_1_nilai' => $detail->diskon_1_nilai,
                'diskon_2_tipe' => $detail->diskon_2_tipe,
                'diskon_2_nilai' => $detail->diskon_2_nilai,
                'diskon_3_tipe' => $detail->diskon_3_tipe,
                'diskon_3_nilai' => $detail->diskon_3_nilai,
                'diskon_4_tipe' => $detail->diskon_4_tipe,
                'diskon_4_nilai' => $detail->diskon_4_nilai,
                'diskon_5_tipe' => $detail->diskon_5_tipe,
                'diskon_5_nilai' => $detail->diskon_5_nilai,
            ];
        }

        // Check if all items have been returned
        if (empty($returnableDetails)) {
            return $this->success([
                'po' => [
                    'nomor_dokumen' => $po->nomor_dokumen,
                    'tanggal_po' => $po->tanggal_po,
                ],
                'details' => [],
                'message' => 'Semua item dari PO ini sudah diretur.',
            ]);
        }

        return $this->success([
            'po' => [
                'nomor_dokumen' => $po->nomor_dokumen,
                'tanggal_po' => $po->tanggal_po,
            ],
            'details' => $returnableDetails,
        ]);
    }
}
