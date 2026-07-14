<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SupplierDepositExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\SupplierDeposit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class SupplierDepositController extends BaseApiController
{
    /**
     * Display a listing of supplier deposits.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('deposit-supplier.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat deposit supplier.');
        }

        $query = SupplierDeposit::with([
            'supplier:id,ulid,kode_supplier,nama_supplier',
            'purchaseReturn:id,ulid,nomor_dokumen,tanggal',
        ]);

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

        // Filter by has balance only
        if ($request->boolean('has_balance_only')) {
            $query->hasBalance();
        }

        // Filter by date range
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');

        $sortableFields = ['tanggal', 'nominal_awal', 'sisa_deposit', 'created_at'];
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
     * Display the specified supplier deposit.
     */
    public function show(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('deposit-supplier.view')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat deposit supplier.');
        }

        $deposit = SupplierDeposit::with([
            'supplier:id,ulid,kode_supplier,nama_supplier,telepon,email',
            'purchaseReturn:id,ulid,nomor_dokumen,tanggal,nilai_kalkulasi,nilai_diakui,selisih',
            'createdBy:id,name',
            'updatedBy:id,name',
        ])->where('ulid', $ulid)->first();

        if (!$deposit) {
            return $this->notFound('Deposit tidak ditemukan.');
        }

        // Add helper flags
        $deposit->is_manual = $deposit->isManual();
        $deposit->can_edit = $deposit->canBeEdited();
        $deposit->can_delete = $deposit->canBeDeleted();

        return $this->success([
            'deposit' => $deposit,
        ]);
    }

    /**
     * Store a new manual deposit.
     */
    public function store(Request $request): JsonResponse
    {
        if (!auth()->user()->can('deposit-supplier.create')) {
            return $this->forbidden('Anda tidak memiliki akses untuk membuat deposit supplier.');
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:master_supplier,id',
            'tanggal' => 'required|date',
            'nominal_awal' => 'required|numeric|min:0.01',
            'no_referensi' => 'nullable|string|max:50',
            'keterangan' => 'nullable|string|max:500',
        ]);

        $deposit = SupplierDeposit::create([
            'supplier_id' => $validated['supplier_id'],
            'retur_id' => null, // Manual deposit, no retur
            'no_referensi' => $validated['no_referensi'] ?? null,
            'keterangan' => $validated['keterangan'] ?? null,
            'tanggal' => $validated['tanggal'],
            'nominal_awal' => $validated['nominal_awal'],
            'nominal_terpakai' => 0,
            'sisa_deposit' => $validated['nominal_awal'],
            'status' => 'available',
            'created_by' => auth()->id(),
            'created_at' => now(),
        ]);

        $deposit->load([
            'supplier:id,ulid,kode_supplier,nama_supplier',
            'createdBy:id,name',
        ]);

        return $this->created([
            'deposit' => $deposit,
            'message' => 'Deposit supplier berhasil dibuat.',
        ]);
    }

    /**
     * Update a manual deposit.
     */
    public function update(Request $request, string $ulid): JsonResponse
    {
        if (!auth()->user()->can('deposit-supplier.update')) {
            return $this->forbidden('Anda tidak memiliki akses untuk mengubah deposit supplier.');
        }

        $deposit = SupplierDeposit::where('ulid', $ulid)->first();

        if (!$deposit) {
            return $this->notFound('Deposit tidak ditemukan.');
        }

        // Only manual deposits can be edited
        if (!$deposit->canBeEdited()) {
            return $this->error('Deposit dari retur pembelian tidak dapat diubah.', 422);
        }

        $validated = $request->validate([
            'supplier_id' => 'required|exists:master_supplier,id',
            'tanggal' => 'required|date',
            'nominal_awal' => 'required|numeric|min:0.01',
            'no_referensi' => 'nullable|string|max:50',
            'keterangan' => 'nullable|string|max:500',
        ]);

        // Validate nominal_awal cannot be less than nominal_terpakai
        if ($validated['nominal_awal'] < $deposit->nominal_terpakai) {
            return $this->error(
                'Nominal awal tidak boleh lebih kecil dari nominal terpakai (' . number_format($deposit->nominal_terpakai, 2) . ').',
                422
            );
        }

        // Calculate new sisa_deposit
        $newSisaDeposit = $validated['nominal_awal'] - $deposit->nominal_terpakai;

        // Update status based on new values
        $newStatus = 'available';
        if ($newSisaDeposit <= 0) {
            $newStatus = 'used_all';
        } elseif ($deposit->nominal_terpakai > 0) {
            $newStatus = 'used_partial';
        }

        $deposit->update([
            'supplier_id' => $validated['supplier_id'],
            'tanggal' => $validated['tanggal'],
            'nominal_awal' => $validated['nominal_awal'],
            'sisa_deposit' => $newSisaDeposit,
            'status' => $newStatus,
            'no_referensi' => $validated['no_referensi'] ?? null,
            'keterangan' => $validated['keterangan'] ?? null,
            'updated_by' => auth()->id(),
            'updated_at' => now(),
        ]);

        $deposit->load([
            'supplier:id,ulid,kode_supplier,nama_supplier',
            'createdBy:id,name',
            'updatedBy:id,name',
        ]);

        return $this->success([
            'deposit' => $deposit,
            'message' => 'Deposit supplier berhasil diperbarui.',
        ]);
    }

    /**
     * Delete a manual deposit.
     */
    public function destroy(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('deposit-supplier.delete')) {
            return $this->forbidden('Anda tidak memiliki akses untuk menghapus deposit supplier.');
        }

        $deposit = SupplierDeposit::where('ulid', $ulid)->first();

        if (!$deposit) {
            return $this->notFound('Deposit tidak ditemukan.');
        }

        // Only manual deposits can be deleted
        if (!$deposit->isManual()) {
            return $this->error('Deposit dari retur pembelian tidak dapat dihapus.', 422);
        }

        // Cannot delete if already used
        if ($deposit->nominal_terpakai > 0) {
            return $this->error('Deposit yang sudah terpakai tidak dapat dihapus.', 422);
        }

        $deposit->delete();

        return $this->deleted('Deposit supplier berhasil dihapus.');
    }

    /**
     * Export supplier deposits to Excel.
     */
    public function export(Request $request)
    {
        if (!auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $filename = 'deposit_supplier_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new SupplierDepositExport(
            supplierId: $request->filled('supplier_id') ? (int) $request->supplier_id : null,
            status: $request->input('status'),
            hasBalanceOnly: $request->boolean('has_balance_only'),
            dateFrom: $request->input('date_from'),
            dateTo: $request->input('date_to'),
            search: $request->input('search'),
        ), $filename);
    }

    /**
     * Get usage history — pemakaian deposit ini ke pembayaran hutang mana saja.
     * Permission: deposit-supplier.view.
     */
    public function usage(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('deposit-supplier.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $deposit = \App\Models\SupplierDeposit::where('ulid', $ulid)->first();
        if (!$deposit) {
            return $this->notFound('Deposit tidak ditemukan.');
        }

        $rows = \DB::table('doc_pembayaran_hutang_deposit as d')
            ->join('doc_pembayaran_hutang as p', 'p.id', '=', 'd.pembayaran_id')
            ->leftJoin('master_supplier as s', 's.id', '=', 'p.supplier_id')
            ->where('d.deposit_id', $deposit->id)
            ->select(
                'd.id',
                'd.nominal_digunakan',
                'p.id as pembayaran_id',
                'p.ulid as pembayaran_ulid',
                'p.nomor_dokumen',
                'p.tanggal',
                'p.status',
                's.kode_supplier',
                's.nama_supplier'
            )
            ->orderByDesc('p.tanggal')
            ->get();

        $totalUsed = $rows->sum('nominal_digunakan');

        return $this->success([
            'deposit' => [
                'ulid' => $deposit->ulid,
                'nominal_awal' => (float) $deposit->nominal_awal,
                'nominal_terpakai' => (float) $deposit->nominal_terpakai,
                'sisa_deposit' => (float) $deposit->sisa_deposit,
            ],
            'usage_count' => $rows->count(),
            'total_used_from_history' => (float) $totalUsed,
            'items' => $rows->map(fn ($r) => [
                'id' => $r->id,
                'pembayaran_id' => $r->pembayaran_id,
                'pembayaran_ulid' => $r->pembayaran_ulid,
                'nomor_dokumen' => $r->nomor_dokumen,
                'tanggal' => $r->tanggal,
                'status' => $r->status,
                'supplier' => $r->kode_supplier ? [
                    'kode' => $r->kode_supplier,
                    'nama' => $r->nama_supplier,
                ] : null,
                'nominal_digunakan' => (float) $r->nominal_digunakan,
            ])->values(),
        ]);
    }

    /**
     * Get summary of supplier deposits.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!auth()->user()->can('deposit-supplier.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $query = SupplierDeposit::query();

        // Filter by supplier
        if ($request->filled('supplier_id')) {
            $query->bySupplier($request->supplier_id);
        }

        $totalDeposit = (clone $query)->sum('nominal_awal');
        $totalUsed = (clone $query)->sum('nominal_terpakai');
        $totalBalance = (clone $query)->sum('sisa_deposit');
        $depositCount = (clone $query)->count();
        $availableCount = (clone $query)->where('status', 'available')->count();

        return $this->success([
            'summary' => [
                'total_deposit' => (float) $totalDeposit,
                'total_used' => (float) $totalUsed,
                'total_balance' => (float) $totalBalance,
                'deposit_count' => $depositCount,
                'available_count' => $availableCount,
            ],
        ]);
    }

    /**
     * Get deposits by supplier (for payment form).
     */
    public function bySupplier(Request $request): JsonResponse
    {
        if (!auth()->user()->can('deposit-supplier.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $request->validate([
            'supplier_id' => 'required|exists:master_supplier,id',
        ]);

        $deposits = SupplierDeposit::with([
            'purchaseReturn:id,ulid,nomor_dokumen,tanggal',
        ])
            ->bySupplier($request->supplier_id)
            ->hasBalance()
            ->available()
            ->orderBy('tanggal', 'asc')
            ->get();

        $totalAvailable = $deposits->sum('sisa_deposit');

        return $this->success([
            'deposits' => $deposits,
            'total_available' => (float) $totalAvailable,
        ]);
    }
}
