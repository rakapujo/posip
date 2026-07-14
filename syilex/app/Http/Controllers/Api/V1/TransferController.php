<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Transfer\ApproveTransferAction;
use App\Actions\Transfer\CreateTransferAction;
use App\Actions\Transfer\UpdateTransferAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\AttachesSerialUnitsToDocDetails;
use App\Models\DocTransfer;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Services\InventoryMasterRules;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TransferController extends BaseApiController
{
    use AttachesSerialUnitsToDocDetails;

    /**
     * Get validation rules for transfer.
     */
    private function getValidationRules(): array
    {
        return [
            'warehouse_from_id' => 'required|exists:master_warehouse,id',
            'warehouse_to_id' => 'required|exists:master_warehouse,id|different:warehouse_from_id',
            'tanggal' => 'required|date',
            'notes' => 'nullable|string|max:1000',
            'biaya_kirim' => 'nullable|numeric|min:0',
            'biaya_lain' => 'nullable|numeric|min:0',
            'biaya_lain_nama' => 'nullable|string|max:100',
            'masuk_hpp' => 'nullable|boolean',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|exists:master_produk,id',
            'details.*.qty' => 'required|numeric|min:0.0001',
            'details.*.serial_unit_ids' => 'nullable|array',
            'details.*.serial_unit_ids.*' => 'string',
        ];
    }

    /**
     * Get validation messages for transfer.
     */
    private function getValidationMessages(): array
    {
        return [
            'warehouse_from_id.required' => 'Gudang asal wajib dipilih.',
            'warehouse_from_id.exists' => 'Gudang asal tidak valid.',
            'warehouse_to_id.required' => 'Gudang tujuan wajib dipilih.',
            'warehouse_to_id.exists' => 'Gudang tujuan tidak valid.',
            'warehouse_to_id.different' => 'Gudang tujuan harus berbeda dengan gudang asal.',
            'tanggal.required' => 'Tanggal wajib diisi.',
            'details.required' => 'Minimal harus ada 1 detail produk.',
            'details.min' => 'Minimal harus ada 1 detail produk.',
            'details.*.product_id.required' => 'Produk wajib dipilih.',
            'details.*.product_id.exists' => 'Produk tidak valid.',
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
     * Display a listing of transfers.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('transfer.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat transfer.');
        }

        $query = DocTransfer::with([
                'warehouseFrom:id,ulid,kode_warehouse,nama_warehouse',
                'warehouseTo:id,ulid,kode_warehouse,nama_warehouse',
                'createdBy:id,name',
            ])
            ->withCount('details');

        // Search
        if ($request->filled('search')) {
            $query->search($request->search);
        }

        // Filter by source warehouse
        if ($request->filled('warehouse_from_id')) {
            $query->byWarehouseFrom($request->warehouse_from_id);
        }

        // Filter by destination warehouse
        if ($request->filled('warehouse_to_id')) {
            $query->byWarehouseTo($request->warehouse_to_id);
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
        $transfers = $query->paginate($perPage);

        return $this->success([
            'items' => $transfers->items(),
            'pagination' => [
                'current_page' => $transfers->currentPage(),
                'last_page' => $transfers->lastPage(),
                'per_page' => $transfers->perPage(),
                'total' => $transfers->total(),
            ],
        ]);
    }

    /**
     * Store a newly created transfer.
     */
    public function store(Request $request, CreateTransferAction $action): JsonResponse
    {
        if (!auth()->user()->can('transfer.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat transfer.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu transfer.']],
                'Validasi gagal'
            );
        }

        if ($errors = InventoryMasterRules::transferPayloadErrors($validated)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $transfer = $action->execute($validated);

            return $this->created([
                'transfer' => $transfer,
            ], 'Transfer berhasil dibuat.');
        } catch (ValidationException $e) {
            throw $e; // render 422 (mis. validasi unit serial)
        } catch (\Exception $e) {
            return $this->error('Gagal membuat transfer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified transfer.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('transfer.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat transfer.');
        }

        $transfer = DocTransfer::with([
            'warehouseFrom:id,ulid,kode_warehouse,nama_warehouse',
            'warehouseTo:id,ulid,kode_warehouse,nama_warehouse',
            'details.product:id,ulid,kode_produk,nama_produk,barcode,is_serial',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'approvedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (!$transfer) {
            return $this->notFound('Transfer tidak ditemukan.');
        }

        // Make hidden IDs visible for form binding
        $transfer->makeVisible(['warehouse_from_id', 'warehouse_to_id']);
        if ($transfer->warehouseFrom) {
            $transfer->warehouseFrom->makeVisible('id');
        }
        if ($transfer->warehouseTo) {
            $transfer->warehouseTo->makeVisible('id');
        }
        foreach ($transfer->details as $detail) {
            $detail->makeVisible('product_id');
            if ($detail->product) {
                $detail->product->makeVisible('id');
            }
        }

        // Tempelkan rincian unit serial (kode_internal/SN/atribut) untuk tampil di detail + PDF
        $this->attachDocSerialUnits($transfer->details);

        return $this->success([
            'transfer' => $transfer,
        ]);
    }

    /**
     * Update the specified transfer.
     */
    public function update(Request $request, string $ulid, UpdateTransferAction $action): JsonResponse
    {
        if (!auth()->user()->can('transfer.update')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah transfer.');
        }

        $transfer = DocTransfer::where('ulid', $ulid)->first();

        if (!$transfer) {
            return $this->notFound('Transfer tidak ditemukan.');
        }

        if (!$transfer->isDraft()) {
            return $this->error('Hanya transfer dengan status draft yang dapat diedit.', 422);
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        // Check for duplicate products
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError(
                ['details' => ['Tidak boleh ada produk yang sama dalam satu transfer.']],
                'Validasi gagal'
            );
        }

        if ($errors = InventoryMasterRules::transferPayloadErrors($validated)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $transfer = $action->execute($transfer, $validated);

            return $this->success([
                'transfer' => $transfer,
            ], 'Transfer berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), $e->getMessage());
        } catch (ValidationException $e) {
            throw $e; // render 422 (mis. validasi unit serial)
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui transfer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified transfer.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('transfer.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus transfer.');
        }

        $transfer = DocTransfer::where('ulid', $ulid)->first();

        if (!$transfer) {
            return $this->notFound('Transfer tidak ditemukan.');
        }

        if (!$transfer->isDraft()) {
            return $this->error('Hanya transfer dengan status draft yang dapat dihapus.', 422);
        }

        $transfer->delete();

        return $this->success(null, 'Transfer berhasil dihapus.');
    }

    /**
     * Approve the specified transfer.
     */
    public function approve(string $ulid, ApproveTransferAction $action): JsonResponse
    {
        if (!auth()->user()->can('transfer.approve')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menyetujui transfer.');
        }

        $transfer = DocTransfer::where('ulid', $ulid)->first();

        if (!$transfer) {
            return $this->notFound('Transfer tidak ditemukan.');
        }

        if ($errors = InventoryMasterRules::transferDocumentErrors($transfer)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        try {
            $transfer = $action->execute($transfer);

            return $this->success([
                'transfer' => $transfer,
            ], 'Transfer berhasil disetujui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (ValidationException $e) {
            throw $e; // render 422 (mis. validasi unit serial)
        } catch (\Exception $e) {
            return $this->error('Gagal menyetujui transfer: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get products with stock for a specific source warehouse.
     * Used for product autocomplete in transfer form.
     */
    public function getProducts(Request $request): JsonResponse
    {
        if (!auth()->user()->can('transfer.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'warehouse_from_id' => 'required|exists:master_warehouse,id',
            'search' => 'nullable|string|max:100',
        ]);

        $warehouseId = $request->warehouse_from_id;
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
                'is_serial' => (bool) $product->is_serial, // frontend tampilkan pemilih unit serial
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

    /**
     * Pattern summary: agregasi transfer approved per (warehouse_from, warehouse_to).
     * Metric: frekuensi, qty total, value total (qty × avg_cost snapshot saat transfer — approx via master_produk.avg_cost current).
     *
     * Permission: transfer.view.
     */
    public function patternSummary(Request $request): JsonResponse
    {
        if (!auth()->user()->can('transfer.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
        ]);

        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());
        $canViewHpp = auth()->user()->can('stok.view_hpp');

        $qtyRows = \DB::table('doc_transfer_detail as d')
            ->join('doc_transfer as t', 't.id', '=', 'd.transfer_id')
            ->join('master_warehouse as wf', 'wf.id', '=', 't.warehouse_from_id')
            ->join('master_warehouse as wt', 'wt.id', '=', 't.warehouse_to_id')
            ->leftJoin('master_produk as p', 'p.id', '=', 'd.product_id')
            ->where('t.status', 'approved')
            ->whereBetween('t.tanggal', [$from, $to])
            ->select(
                't.warehouse_from_id',
                't.warehouse_to_id',
                'wf.kode_warehouse as from_kode',
                'wf.nama_warehouse as from_nama',
                'wt.kode_warehouse as to_kode',
                'wt.nama_warehouse as to_nama',
                \DB::raw('COUNT(DISTINCT t.id) as frekuensi'),
                \DB::raw('COALESCE(SUM(d.qty), 0) as qty_total'),
                \DB::raw('COALESCE(SUM(d.qty * p.avg_cost), 0) as value_total')
            )
            ->groupBy(
                't.warehouse_from_id', 't.warehouse_to_id',
                'wf.kode_warehouse', 'wf.nama_warehouse',
                'wt.kode_warehouse', 'wt.nama_warehouse'
            )
            ->orderByDesc(\DB::raw('COUNT(DISTINCT t.id)'))
            ->get();

        // Insight sederhana: warehouse mana paling sering kirim / terima
        $outByWarehouse = [];
        $inByWarehouse = [];
        foreach ($qtyRows as $r) {
            $outByWarehouse[$r->from_kode] = ($outByWarehouse[$r->from_kode] ?? 0) + (int) $r->frekuensi;
            $inByWarehouse[$r->to_kode] = ($inByWarehouse[$r->to_kode] ?? 0) + (int) $r->frekuensi;
        }
        arsort($outByWarehouse);
        arsort($inByWarehouse);

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'items' => $qtyRows->map(fn ($r) => [
                'from_warehouse_id' => $r->warehouse_from_id,
                'from_kode' => $r->from_kode,
                'from_nama' => $r->from_nama,
                'to_warehouse_id' => $r->warehouse_to_id,
                'to_kode' => $r->to_kode,
                'to_nama' => $r->to_nama,
                'frekuensi' => (int) $r->frekuensi,
                'qty_total' => (float) $r->qty_total,
                'value_total' => $canViewHpp ? (float) $r->value_total : null,
            ])->values(),
            'top_sender' => collect($outByWarehouse)->keys()->first(),
            'top_receiver' => collect($inByWarehouse)->keys()->first(),
        ]);
    }
}
