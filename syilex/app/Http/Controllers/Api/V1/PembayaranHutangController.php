<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PembayaranHutang\CompletePembayaranHutangAction;
use App\Actions\PembayaranHutang\CreatePembayaranHutangAction;
use App\Actions\PembayaranHutang\UpdatePembayaranHutangAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocPembayaranHutang;
use App\Models\SupplierDeposit;
use App\Models\SupplierHutang;
use App\Services\PurchaseMasterRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PembayaranHutangController extends BaseApiController
{
    /**
     * Get validation rules.
     */
    private function getValidationRules(): array
    {
        return [
            'tanggal' => 'required|date',
            'supplier_id' => 'required|exists:master_supplier,id',
            'metode_pembayaran' => 'required|in:cash,transfer',
            'no_referensi' => 'nullable|string|max:50',
            'bank_nama' => 'nullable|string|max:50',
            'bank_rekening' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:1000',

            // Details (payments per hutang)
            'details' => 'required|array|min:1',
            'details.*.hutang_id' => 'required|exists:supplier_hutang,id',
            'details.*.nominal_dibayar' => 'required|numeric|min:0.01',
            'details.*.sumber' => 'required|in:cash,deposit',

            // Deposit usages (which deposits are used)
            'deposit_usages' => 'nullable|array',
            'deposit_usages.*.deposit_id' => 'required|exists:supplier_deposit,id',
            'deposit_usages.*.nominal_digunakan' => 'required|numeric|min:0.01',
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
            'metode_pembayaran.required' => 'Metode pembayaran wajib dipilih.',
            'metode_pembayaran.in' => 'Metode pembayaran harus cash atau transfer.',
            'details.required' => 'Minimal harus ada 1 hutang yang dibayar.',
            'details.min' => 'Minimal harus ada 1 hutang yang dibayar.',
            'details.*.hutang_id.required' => 'Hutang wajib dipilih.',
            'details.*.hutang_id.exists' => 'Hutang tidak valid.',
            'details.*.nominal_dibayar.required' => 'Nominal pembayaran wajib diisi.',
            'details.*.nominal_dibayar.min' => 'Nominal pembayaran minimal 0.01.',
            'details.*.sumber.required' => 'Sumber pembayaran wajib dipilih.',
            'details.*.sumber.in' => 'Sumber pembayaran harus cash atau deposit.',
            'deposit_usages.*.deposit_id.required' => 'Deposit wajib dipilih.',
            'deposit_usages.*.deposit_id.exists' => 'Deposit tidak valid.',
            'deposit_usages.*.nominal_digunakan.required' => 'Nominal penggunaan deposit wajib diisi.',
            'deposit_usages.*.nominal_digunakan.min' => 'Nominal penggunaan deposit minimal 0.01.',
        ];
    }

    private function validateSupplierReference(array $validated): ?JsonResponse
    {
        $errors = PurchaseMasterRules::supplierErrors($validated['supplier_id']);

        if ($errors) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        return null;
    }

    private function validatePembayaranSupplierState(DocPembayaranHutang $pembayaran): ?JsonResponse
    {
        $errors = PurchaseMasterRules::supplierErrors($pembayaran->supplier_id);

        if ($errors) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        return null;
    }

    /**
     * Display a listing of pembayaran hutang.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pembayaran-hutang.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat pembayaran hutang.');
        }

        $query = DocPembayaranHutang::with([
                'supplier:id,ulid,kode_supplier,nama_supplier',
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

        $sortableFields = ['nomor_dokumen', 'tanggal', 'total_pembayaran', 'created_at'];
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
     * Store a newly created pembayaran hutang.
     */
    public function store(Request $request, CreatePembayaranHutangAction $action): JsonResponse
    {
        if (!auth()->user()->can('pembayaran-hutang.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat pembayaran hutang.');
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        if ($response = $this->validateSupplierReference($validated)) {
            return $response;
        }

        try {
            $pembayaran = $action->execute($validated);

            return $this->created([
                'pembayaran' => $pembayaran,
            ], 'Pembayaran hutang berhasil dibuat.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal membuat pembayaran hutang: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Display the specified pembayaran hutang.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('pembayaran-hutang.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat pembayaran hutang.');
        }

        $pembayaran = DocPembayaranHutang::with([
            'supplier:id,ulid,kode_supplier,nama_supplier',
            'details.hutang.purchaseOrder:id,ulid,nomor_dokumen',
            'depositUsages.deposit',
            'createdBy:id,name,email',
            'updatedBy:id,name,email',
            'completedBy:id,name,email',
        ])->where('ulid', $ulid)->first();

        if (!$pembayaran) {
            return $this->notFound('Pembayaran hutang tidak ditemukan.');
        }

        // Make hidden IDs visible for form binding
        $pembayaran->makeVisible(['supplier_id']);
        if ($pembayaran->supplier) {
            $pembayaran->supplier->makeVisible('id');
        }

        foreach ($pembayaran->details as $detail) {
            $detail->makeVisible(['hutang_id']);
            if ($detail->hutang) {
                $detail->hutang->makeVisible('id');
            }
        }

        foreach ($pembayaran->depositUsages as $usage) {
            $usage->makeVisible(['deposit_id']);
            if ($usage->deposit) {
                $usage->deposit->makeVisible('id');
            }
        }

        return $this->success([
            'pembayaran' => $pembayaran,
        ]);
    }

    /**
     * Update the specified pembayaran hutang.
     */
    public function update(Request $request, string $ulid, UpdatePembayaranHutangAction $action): JsonResponse
    {
        if (!auth()->user()->can('pembayaran-hutang.update')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah pembayaran hutang.');
        }

        $pembayaran = DocPembayaranHutang::where('ulid', $ulid)->first();

        if (!$pembayaran) {
            return $this->notFound('Pembayaran hutang tidak ditemukan.');
        }

        if (!$pembayaran->isDraft()) {
            return $this->error('Hanya pembayaran dengan status draft yang dapat diedit.', 422);
        }

        $validated = $request->validate($this->getValidationRules(), $this->getValidationMessages());

        if ($response = $this->validateSupplierReference($validated)) {
            return $response;
        }

        try {
            $pembayaran = $action->execute($pembayaran, $validated);

            return $this->success([
                'pembayaran' => $pembayaran,
            ], 'Pembayaran hutang berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal memperbarui pembayaran hutang: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Remove the specified pembayaran hutang.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('pembayaran-hutang.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus pembayaran hutang.');
        }

        $pembayaran = DocPembayaranHutang::where('ulid', $ulid)->first();

        if (!$pembayaran) {
            return $this->notFound('Pembayaran hutang tidak ditemukan.');
        }

        if (!$pembayaran->isDraft()) {
            return $this->error('Hanya pembayaran dengan status draft yang dapat dihapus.', 422);
        }

        $pembayaran->delete();

        return $this->success(null, 'Pembayaran hutang berhasil dihapus.');
    }

    /**
     * Complete the specified pembayaran hutang.
     */
    public function complete(string $ulid, CompletePembayaranHutangAction $action): JsonResponse
    {
        if (!auth()->user()->can('pembayaran-hutang.complete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menyelesaikan pembayaran hutang.');
        }

        $pembayaran = DocPembayaranHutang::where('ulid', $ulid)->first();

        if (!$pembayaran) {
            return $this->notFound('Pembayaran hutang tidak ditemukan.');
        }

        if ($response = $this->validatePembayaranSupplierState($pembayaran)) {
            return $response;
        }

        try {
            $pembayaran = $action->execute($pembayaran);

            return $this->success([
                'pembayaran' => $pembayaran,
            ], 'Pembayaran hutang berhasil diselesaikan.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Exception $e) {
            return $this->error('Gagal menyelesaikan pembayaran hutang: ' . $e->getMessage(), 500);
        }
    }

    /**
     * Get outstanding hutangs for a supplier.
     */
    public function getOutstandingHutangs(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pembayaran-hutang.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'supplier_id' => 'required|exists:master_supplier,id',
        ]);

        $hutangs = SupplierHutang::with(['purchaseOrder:id,ulid,nomor_dokumen'])
            ->where('supplier_id', $request->supplier_id)
            ->outstanding()
            ->orderBy('tanggal_jatuh_tempo', 'asc')
            ->orderBy('tanggal', 'asc')
            ->get();

        // Make IDs visible
        $hutangs->each(function ($hutang) {
            $hutang->makeVisible('id');
            if ($hutang->purchaseOrder) {
                $hutang->purchaseOrder->makeVisible('id');
            }
        });

        return $this->success([
            'items' => $hutangs,
        ]);
    }

    /**
     * Get available deposits for a supplier.
     */
    public function getAvailableDeposits(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pembayaran-hutang.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'supplier_id' => 'required|exists:master_supplier,id',
        ]);

        $deposits = SupplierDeposit::with(['purchaseReturn:id,ulid,nomor_dokumen'])
            ->where('supplier_id', $request->supplier_id)
            ->hasBalance()
            ->orderBy('tanggal', 'asc')
            ->get();

        // Make IDs visible
        $deposits->each(function ($deposit) {
            $deposit->makeVisible('id');
            if ($deposit->purchaseReturn) {
                $deposit->purchaseReturn->makeVisible('id');
            }
        });

        return $this->success([
            'items' => $deposits,
        ]);
    }
}
