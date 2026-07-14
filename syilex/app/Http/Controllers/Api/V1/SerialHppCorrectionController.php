<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SerialHppCorrection\ApproveSerialHppCorrectionAction;
use App\Actions\SerialHppCorrection\CreateSerialHppCorrectionAction;
use App\Actions\SerialHppCorrection\UpdateSerialHppCorrectionAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocSerialHppCorrection;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Koreksi HPP Serial (modul serial A+) — koreksi harga_modal & cost_per_unit unit
 * tersedia per-unit, alur draft → approved. Default tidak mengubah avg_cost agregat.
 */
class SerialHppCorrectionController extends BaseApiController
{
    public function __construct(
        private CreateSerialHppCorrectionAction $createAction,
        private UpdateSerialHppCorrectionAction $updateAction,
        private ApproveSerialHppCorrectionAction $approveAction
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('serial-hpp.view')) {
            return $this->forbidden();
        }

        $query = DocSerialHppCorrection::with([
                'product:id,ulid,kode_produk,nama_produk',
                'createdBy:id,name',
            ])
            ->withCount('details');

        if ($request->filled('search')) {
            $query->search($request->search);
        }
        if ($request->filled('product_id')) {
            $query->where('product_id', $request->product_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }

        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');
        $sortableFields = ['nomor_dokumen', 'tanggal', 'total_unit', 'created_at'];
        if (!in_array($sortField, $sortableFields, true)) {
            $sortField = 'tanggal';
        }
        $query->orderBy($sortField, $sortOrder);

        $items = $query->paginate($this->getPerPage($request, 15));

        return $this->success([
            'items' => $items->items(),
            'pagination' => [
                'current_page' => $items->currentPage(),
                'last_page' => $items->lastPage(),
                'per_page' => $items->perPage(),
                'total' => $items->total(),
                'from' => $items->firstItem(),
                'to' => $items->lastItem(),
            ],
        ]);
    }

    public function show(DocSerialHppCorrection $serialHppCorrection): JsonResponse
    {
        if (!auth()->user()->can('serial-hpp.view')) {
            return $this->forbidden();
        }

        $serialHppCorrection->load([
            'product:id,ulid,kode_produk,nama_produk',
            'details' => fn ($q) => $q->orderBy('id'),
            'details.serialUnit:id,ulid,kode_internal,serial_number',
            'createdBy:id,name',
            'updatedBy:id,name',
            'approvedBy:id,name',
        ]);

        return $this->success(['serial_hpp_correction' => $serialHppCorrection]);
    }

    /**
     * Unit TERSEDIA suatu produk serial + harga_modal & cost_per_unit terkini (untuk form).
     */
    public function units(Request $request): JsonResponse
    {
        if (!auth()->user()->canAny(['serial-hpp.create', 'serial-hpp.update'])) {
            return $this->forbidden();
        }

        $product = MasterProduk::where('ulid', $request->input('product_id'))->first();
        if (!$product) {
            return $this->notFound('Produk tidak ditemukan');
        }

        $units = SerialUnit::byProduct($product->id)->tersedia()
            ->orderBy('serial_number')
            ->get(['ulid', 'kode_internal', 'serial_number', 'harga_modal', 'cost_per_unit', 'harga_jual', 'grade']);

        // Setting pajak pembelian → frontend hitung pajak & landed live (otomatis)
        return $this->success([
            'units' => $units,
            'tax' => SettingService::getPurchaseTaxSettings(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!auth()->user()->can('serial-hpp.create')) {
            return $this->forbidden();
        }

        $request->validate($this->payloadRules());

        $correction = $this->createAction->execute($this->resolveData($request));

        return $this->created(['serial_hpp_correction' => $correction], 'Koreksi HPP serial disimpan sebagai draft');
    }

    public function update(Request $request, DocSerialHppCorrection $serialHppCorrection): JsonResponse
    {
        if (!auth()->user()->can('serial-hpp.update')) {
            return $this->forbidden();
        }
        if (!$serialHppCorrection->isDraft()) {
            return $this->error('Hanya draft yang dapat diubah.', 422);
        }

        $request->validate($this->payloadRules());

        $correction = $this->updateAction->execute($serialHppCorrection, $this->resolveData($request));

        return $this->success(['serial_hpp_correction' => $correction], 'Koreksi HPP serial diperbarui');
    }

    public function approve(DocSerialHppCorrection $serialHppCorrection): JsonResponse
    {
        if (!auth()->user()->can('serial-hpp.approve')) {
            return $this->forbidden();
        }

        $correction = $this->approveAction->execute($serialHppCorrection);

        return $this->success(['serial_hpp_correction' => $correction], 'Koreksi HPP serial disetujui & diterapkan');
    }

    public function destroy(DocSerialHppCorrection $serialHppCorrection): JsonResponse
    {
        if (!auth()->user()->can('serial-hpp.delete')) {
            return $this->forbidden();
        }
        if (!$serialHppCorrection->isDraft()) {
            return $this->error('Hanya draft yang dapat dihapus.', 422);
        }

        $serialHppCorrection->details()->delete();
        $serialHppCorrection->delete();

        return $this->success(null, 'Koreksi HPP serial dihapus');
    }

    // ==================== HELPERS ====================

    private function payloadRules(): array
    {
        return [
            'product_id' => 'required|string',
            'tanggal' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'units' => 'required|array|min:1',
            'units.*.serial_unit_id' => 'required|string',
            'units.*.harga_modal_baru' => 'required|numeric|min:0',
            'units.*.biaya_kirim_baru' => 'nullable|numeric|min:0',
            'units.*.biaya_lain_baru' => 'nullable|numeric|min:0',
            // cost_per_unit (landed) & pajak dihitung server dari komponen + setting pajak
        ];
    }

    /**
     * @throws ValidationException
     */
    private function resolveData(Request $request): array
    {
        $product = MasterProduk::where('ulid', $request->input('product_id'))->first();
        if (!$product) {
            throw ValidationException::withMessages(['product_id' => ['Produk tidak ditemukan.']]);
        }
        if (!$product->is_serial) {
            throw ValidationException::withMessages(['product_id' => ['Produk ini bukan produk serial.']]);
        }

        return [
            'product_id' => $product->id,
            'tanggal' => $request->input('tanggal') ?: now(),
            'notes' => $request->input('notes'),
            'units' => $request->input('units'),
        ];
    }
}
