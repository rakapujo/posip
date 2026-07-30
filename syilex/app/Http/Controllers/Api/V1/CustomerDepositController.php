<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\CustomerDepositExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\CustomerDeposit;
use App\Services\CustomerRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class CustomerDepositController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->can('deposit-customer.view')) {
            return $this->forbidden();
        }
        $query = CustomerDeposit::with([
            'customer:id,ulid,kode_customer,nama',
            'salesReturn:id,ulid,nomor_dokumen,tanggal',
        ])->withExists('pembayaranUsages');
        if ($request->filled('search')) {
            $query->search($request->search);
        }
        if ($request->filled('customer_id')) {
            $query->byCustomer((int) $request->customer_id);
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
        $sort = in_array($request->input('sort_field'), ['tanggal', 'nominal_awal', 'sisa_deposit', 'created_at'], true)
            ? $request->input('sort_field') : 'tanggal';
        $items = $query->orderBy($sort, $request->input('sort_order') === 'asc' ? 'asc' : 'desc')
            ->paginate($this->getPerPage($request, 15));

        $canViewNominal = auth()->user()->can('piutang.view_nominal');
        $transformed = collect($items->items())->map(function ($item) use ($canViewNominal) {
            $item->can_edit = $item->canBeEdited();
            $item->can_delete = $item->canBeDeleted();
            if (! $canViewNominal) {
                $item->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
            }

            return $item;
        });

        return $this->success(['items' => $transformed, 'pagination' => [
            'current_page' => $items->currentPage(), 'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(), 'total' => $items->total(),
        ]]);
    }

    public function show(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('deposit-customer.view')) {
            return $this->forbidden();
        }
        $deposit = CustomerDeposit::with([
            'customer:id,ulid,kode_customer,nama,telepon,email',
            'salesReturn:id,ulid,nomor_dokumen,tanggal',
            'createdBy:id,name', 'updatedBy:id,name',
        ])->where('ulid', $ulid)->first();
        if (! $deposit) {
            return $this->notFound('Deposit tidak ditemukan.');
        }
        $deposit->customer?->makeVisible('id');
        $deposit->makeVisible('customer_id');
        $deposit->is_manual = $deposit->isManual();
        $deposit->can_edit = $deposit->canBeEdited();
        $deposit->can_delete = $deposit->canBeDeleted();

        if (! auth()->user()->can('piutang.view_nominal')) {
            $deposit->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
        }

        return $this->success(['deposit' => $deposit]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! auth()->user()->can('deposit-customer.create')) {
            return $this->forbidden();
        }
        $data = $request->validate($this->rules());
        $deposit = CustomerDeposit::create([
            ...$data,
            'retur_id' => null,
            'nominal_terpakai' => 0,
            'sisa_deposit' => $data['nominal_awal'],
            'status' => 'available',
            'created_by' => auth()->id(),
        ])->load(['customer:id,ulid,kode_customer,nama', 'createdBy:id,name']);

        if (! auth()->user()->can('piutang.view_nominal')) {
            $deposit->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
        }

        return $this->created(['deposit' => $deposit], 'Deposit customer berhasil dibuat.');
    }

    public function update(Request $request, string $ulid): JsonResponse
    {
        if (! auth()->user()->can('deposit-customer.update')) {
            return $this->forbidden();
        }
        $data = $request->validate($this->rules());
        try {
            $deposit = DB::transaction(function () use ($ulid, $data) {
                $deposit = CustomerDeposit::where('ulid', $ulid)->lockForUpdate()->firstOrFail();
                if (! $deposit->canBeEdited()) {
                    throw new \DomainException('Hanya deposit manual yang belum terpakai dapat diubah.');
                }
                $deposit->update([
                    ...$data,
                    'sisa_deposit' => $data['nominal_awal'],
                    'status' => 'available',
                    'updated_by' => auth()->id(),
                ]);

                return $deposit->load(['customer:id,ulid,kode_customer,nama', 'createdBy:id,name', 'updatedBy:id,name']);
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('Deposit tidak ditemukan.');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        if (! auth()->user()->can('piutang.view_nominal')) {
            $deposit->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
        }

        return $this->success(['deposit' => $deposit], 'Deposit customer berhasil diperbarui.');
    }

    public function destroy(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('deposit-customer.delete')) {
            return $this->forbidden();
        }
        try {
            DB::transaction(function () use ($ulid) {
                $deposit = CustomerDeposit::where('ulid', $ulid)->lockForUpdate()->firstOrFail();
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

        return $this->deleted('Deposit customer berhasil dihapus.');
    }

    public function export(Request $request)
    {
        if (! auth()->user()->can('deposit-customer.view') || ! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export deposit customer.');
        }
        if (! auth()->user()->can('piutang.view_nominal')) {
            return $this->forbidden('Export deposit membutuhkan izin melihat nominal.');
        }

        $filename = 'deposit_customer_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new CustomerDepositExport(
            customerId: $request->filled('customer_id') ? (int) $request->customer_id : null,
            status: $request->input('status'),
            hasBalanceOnly: $request->boolean('has_balance_only'),
            dateFrom: $request->input('date_from'),
            dateTo: $request->input('date_to'),
            search: $request->input('search'),
        ), $filename);
    }

    public function usage(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('deposit-customer.view')) {
            return $this->forbidden();
        }
        $deposit = CustomerDeposit::where('ulid', $ulid)->first();
        if (! $deposit) {
            return $this->notFound('Deposit tidak ditemukan.');
        }
        $rows = DB::table('doc_pembayaran_piutang_deposit as d')
            ->join('doc_pembayaran_piutang as p', 'p.id', '=', 'd.pembayaran_id')
            ->leftJoin('master_customer as c', 'c.id', '=', 'p.customer_id')
            ->where('d.deposit_id', $deposit->id)
            ->where('p.status', 'completed')
            ->select('d.id', 'd.nominal_digunakan', 'p.ulid as pembayaran_ulid',
                'p.nomor_dokumen', 'p.tanggal', 'p.status', 'c.kode_customer', 'c.nama')
            ->orderByDesc('p.tanggal')->get();

        $canViewNominal = auth()->user()->can('piutang.view_nominal');
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
                'pembayaran_ulid' => $r->pembayaran_ulid,
                'nomor_dokumen' => $r->nomor_dokumen,
                'tanggal' => $r->tanggal,
                'status' => $r->status,
                'customer' => $r->kode_customer ? [
                    'kode' => $r->kode_customer,
                    'nama' => $r->nama,
                ] : null,
                'nominal_digunakan' => $canViewNominal ? (float) $r->nominal_digunakan : null,
            ])->values(),
        ]);
    }

    public function summary(Request $request): JsonResponse
    {
        if (! auth()->user()->can('deposit-customer.view')) {
            return $this->forbidden();
        }
        $query = CustomerDeposit::query();
        $this->applyListFilters($query, $request);

        $canViewNominal = auth()->user()->can('piutang.view_nominal');
        $depositCount = (clone $query)->count();
        $availableCount = (clone $query)->where('sisa_deposit', '>', 0)->count();

        return $this->success(['summary' => [
            'total_deposit' => $canViewNominal ? (float) (clone $query)->sum('nominal_awal') : null,
            'total_used' => $canViewNominal ? (float) (clone $query)->sum('nominal_terpakai') : null,
            'total_balance' => $canViewNominal ? (float) (clone $query)->sum('sisa_deposit') : null,
            'deposit_count' => $depositCount,
            'available_count' => $availableCount,
        ]]);
    }

    private function applyListFilters($query, Request $request): void
    {
        if ($request->filled('search')) {
            $query->search($request->search);
        }
        if ($request->filled('customer_id')) {
            $query->byCustomer((int) $request->customer_id);
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
    }

    private function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:master_customer,id', function ($attribute, $value, $fail) {
                CustomerRules::assertActiveBackofficeCustomer($value, $fail);
            }],
            'tanggal' => 'required|date',
            'nominal_awal' => 'required|numeric|min:0.01',
            'no_referensi' => 'nullable|string|max:50',
            'keterangan' => 'nullable|string|max:500',
        ];
    }
}
