<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SerialChange\ApproveSerialChangeAction;
use App\Actions\SerialChange\CreateSerialChangeAction;
use App\Actions\SerialChange\UpdateSerialChangeAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocSerialChange;
use App\Models\MasterProduk;
use App\Models\SerialUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Perubahan Data Serial (modul serial A+) — koreksi data unit tersedia, alur draft → approved.
 */
class SerialChangeController extends BaseApiController
{
    public function __construct(
        private CreateSerialChangeAction $createAction,
        private UpdateSerialChangeAction $updateAction,
        private ApproveSerialChangeAction $approveAction
    ) {
    }

    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('serial-change.view')) {
            return $this->forbidden();
        }

        $query = DocSerialChange::with([
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

    public function show(DocSerialChange $serialChange): JsonResponse
    {
        if (!auth()->user()->can('serial-change.view')) {
            return $this->forbidden();
        }

        $serialChange->load([
            'product:id,ulid,kode_produk,nama_produk',
            'details' => fn ($q) => $q->orderBy('id'),
            'details.serialUnit:id,ulid,kode_internal',
            'createdBy:id,name',
            'updatedBy:id,name',
            'approvedBy:id,name',
        ]);

        return $this->success(['serial_change' => $serialChange]);
    }

    /**
     * Unit TERSEDIA suatu produk serial (untuk dimuat & dikoreksi di form).
     */
    public function units(Request $request): JsonResponse
    {
        if (!auth()->user()->canAny(['serial-change.create', 'serial-change.update'])) {
            return $this->forbidden();
        }

        $product = MasterProduk::where('ulid', $request->input('product_id'))->first();
        if (!$product) {
            return $this->notFound('Produk tidak ditemukan');
        }

        $units = SerialUnit::byProduct($product->id)->tersedia()
            ->orderBy('serial_number')
            ->get(['ulid', 'kode_internal', 'serial_number', 'harga_jual', 'grade', 'battery_condition', 'battery_health', 'account_status', 'catatan']);

        return $this->success(['units' => $units]);
    }

    public function store(Request $request): JsonResponse
    {
        if (!auth()->user()->can('serial-change.create')) {
            return $this->forbidden();
        }

        $request->validate($this->payloadRules());

        $change = $this->createAction->execute($this->resolveData($request));

        return $this->created(['serial_change' => $change], 'Perubahan data serial disimpan sebagai draft');
    }

    public function update(Request $request, DocSerialChange $serialChange): JsonResponse
    {
        if (!auth()->user()->can('serial-change.update')) {
            return $this->forbidden();
        }
        if (!$serialChange->isDraft()) {
            return $this->error('Hanya draft yang dapat diubah.', 422);
        }

        $request->validate($this->payloadRules());

        $change = $this->updateAction->execute($serialChange, $this->resolveData($request));

        return $this->success(['serial_change' => $change], 'Perubahan data serial diperbarui');
    }

    public function approve(DocSerialChange $serialChange): JsonResponse
    {
        if (!auth()->user()->can('serial-change.approve')) {
            return $this->forbidden();
        }

        $change = $this->approveAction->execute($serialChange);

        return $this->success(['serial_change' => $change], 'Perubahan data serial disetujui & diterapkan');
    }

    public function destroy(DocSerialChange $serialChange): JsonResponse
    {
        if (!auth()->user()->can('serial-change.delete')) {
            return $this->forbidden();
        }
        if (!$serialChange->isDraft()) {
            return $this->error('Hanya draft yang dapat dihapus.', 422);
        }

        $serialChange->details()->delete();
        $serialChange->delete();

        return $this->success(null, 'Perubahan data serial dihapus');
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
            'units.*.serial_number' => 'required|string|max:100',
            'units.*.harga_jual' => 'required|numeric|min:0',
            'units.*.grade' => 'required|string|in:A,B,C,D,E,F',
            'units.*.battery_condition' => 'required|string|max:30',
            'units.*.battery_health' => 'required|numeric|min:0|max:100',
            'units.*.account_status' => 'required|string|in:locked,unlocked',
            'units.*.catatan' => 'nullable|string|max:255',
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
