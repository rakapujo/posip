<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SupplierDepositExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\MasterSupplier;
use App\Models\SupplierDeposit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

        $canViewNominal = auth()->user()->can('hutang.view_nominal');
        $transformed = collect($items->items())->map(function ($item) use ($canViewNominal) {
            if (! $canViewNominal) {
                $item->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
            }

            return $item;
        });

        return $this->success([
            'items' => $transformed,
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

        if (! auth()->user()->can('hutang.view_nominal')) {
            $deposit->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
            if ($deposit->relationLoaded('purchaseReturn') && $deposit->purchaseReturn) {
                $deposit->purchaseReturn->makeHidden(['nilai_kalkulasi', 'nilai_diakui', 'selisih']);
            }
        }

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

        $validated = $request->validate($this->rules());

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

        if (! auth()->user()->can('hutang.view_nominal')) {
            $deposit->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
        }

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

        $validated = $request->validate($this->rules());

        try {
            $deposit = DB::transaction(function () use ($ulid, $validated) {
                $deposit = SupplierDeposit::where('ulid', $ulid)->lockForUpdate()->firstOrFail();
                if (! $deposit->canBeEdited()) {
                    throw new \DomainException('Deposit dari retur pembelian tidak dapat diubah.');
                }

                if ($validated['nominal_awal'] < $deposit->nominal_terpakai) {
                    throw new \DomainException(
                        'Nominal awal tidak boleh lebih kecil dari nominal terpakai (' . number_format($deposit->nominal_terpakai, 2) . ').'
                    );
                }

                $newSisaDeposit = $validated['nominal_awal'] - $deposit->nominal_terpakai;
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

                return $deposit->load([
                    'supplier:id,ulid,kode_supplier,nama_supplier',
                    'createdBy:id,name',
                    'updatedBy:id,name',
                ]);
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('Deposit tidak ditemukan.');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        if (! auth()->user()->can('hutang.view_nominal')) {
            $deposit->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
        }

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

        try {
            DB::transaction(function () use ($ulid) {
                $deposit = SupplierDeposit::where('ulid', $ulid)->lockForUpdate()->firstOrFail();
                if (! $deposit->canBeDeleted()) {
                    throw new \DomainException('Hanya deposit manual yang belum terpakai dapat dihapus.');
                }
                $deposit->delete();
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('Deposit tidak ditemukan.');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'Deposit supplier berhasil dihapus.');
    }

    /**
     * Export supplier deposits to Excel.
     */
    public function export(Request $request)
    {
        if (! auth()->user()->can('deposit-supplier.view') || ! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export deposit supplier.');
        }
        if (! auth()->user()->can('hutang.view_nominal')) {
            return $this->forbidden('Export deposit membutuhkan izin melihat nominal.');
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

        $canViewNominal = auth()->user()->can('hutang.view_nominal');
        $totalUsed = $rows->sum('nominal_digunakan');

        return $this->success([
            'deposit' => [
                'ulid' => $deposit->ulid,
                'nominal_awal' => $canViewNominal ? (float) $deposit->nominal_awal : null,
                'nominal_terpakai' => $canViewNominal ? (float) $deposit->nominal_terpakai : null,
                'sisa_deposit' => $canViewNominal ? (float) $deposit->sisa_deposit : null,
            ],
            'usage_count' => $rows->count(),
            'total_used_from_history' => $canViewNominal ? (float) $totalUsed : null,
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
                'nominal_digunakan' => $canViewNominal ? (float) $r->nominal_digunakan : null,
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

        if ($request->filled('supplier_id')) {
            $query->bySupplier($request->supplier_id);
        }

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->boolean('has_balance_only')) {
            $query->hasBalance();
        }

        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }

        $canViewNominal = auth()->user()->can('hutang.view_nominal');
        $depositCount = (clone $query)->count();
        $availableCount = (clone $query)->where('sisa_deposit', '>', 0)->count();

        return $this->success([
            'summary' => [
                'total_deposit' => $canViewNominal ? (float) (clone $query)->sum('nominal_awal') : null,
                'total_used' => $canViewNominal ? (float) (clone $query)->sum('nominal_terpakai') : null,
                'total_balance' => $canViewNominal ? (float) (clone $query)->sum('sisa_deposit') : null,
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
            ->orderBy('tanggal', 'asc')
            ->get();

        $canViewNominal = auth()->user()->can('hutang.view_nominal');
        if (! $canViewNominal) {
            $deposits->each(fn ($d) => $d->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']));
        }

        $totalAvailable = $canViewNominal ? (float) $deposits->sum('sisa_deposit') : null;

        return $this->success([
            'deposits' => $deposits,
            'total_available' => $totalAvailable,
        ]);
    }

    private function rules(): array
    {
        return [
            'supplier_id' => ['required', 'exists:master_supplier,id', function ($attribute, $value, $fail) {
                if (! MasterSupplier::whereKey($value)->active()->exists()) {
                    $fail('Supplier harus aktif.');
                }
            }],
            'tanggal' => 'required|date',
            'nominal_awal' => 'required|numeric|min:0.01',
            'no_referensi' => 'nullable|string|max:50',
            'keterangan' => 'nullable|string|max:500',
        ];
    }
}
