<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PembayaranPiutang\CompletePembayaranPiutangAction;
use App\Actions\PembayaranPiutang\CreatePembayaranPiutangAction;
use App\Actions\PembayaranPiutang\UpdatePembayaranPiutangAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\CustomerDeposit;
use App\Models\CustomerPiutang;
use App\Models\DocPembayaranPiutang;
use App\Services\CustomerRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PembayaranPiutangController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->can('pembayaran-piutang.view')) {
            return $this->forbidden();
        }
        $query = DocPembayaranPiutang::with(['customer:id,ulid,kode_customer,nama', 'createdBy:id,name'])
            ->withCount('details');
        if ($request->filled('search')) {
            $query->search($request->search);
        }
        if ($request->filled('customer_id')) {
            $query->byCustomer((int) $request->customer_id);
        }
        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }
        $sort = in_array($request->input('sort_field'), ['nomor_dokumen', 'tanggal', 'total_pembayaran', 'created_at'], true)
            ? $request->input('sort_field') : 'tanggal';
        $items = $query->orderBy($sort, $request->input('sort_order') === 'asc' ? 'asc' : 'desc')
            ->paginate($this->getPerPage($request, 15));

        $canViewNominal = auth()->user()->can('piutang.view_nominal');
        $rows = collect($items->items())->map(function ($item) use ($canViewNominal) {
            if (! $canViewNominal) {
                $item->makeHidden(['total_bayar_cash', 'total_bayar_deposit', 'total_pembayaran']);
            }

            return $item;
        });

        return $this->success(['items' => $rows, 'pagination' => [
            'current_page' => $items->currentPage(), 'last_page' => $items->lastPage(),
            'per_page' => $items->perPage(), 'total' => $items->total(),
        ]]);
    }

    public function store(Request $request, CreatePembayaranPiutangAction $action): JsonResponse
    {
        if (! auth()->user()->can('pembayaran-piutang.create')) {
            return $this->forbidden();
        }

        return $this->runAction(fn () => $action->execute($request->validate($this->rules())), true);
    }

    public function show(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('pembayaran-piutang.view')) {
            return $this->forbidden();
        }
        $payment = DocPembayaranPiutang::with([
            'customer:id,ulid,kode_customer,nama',
            'details.piutang.sales:id,ulid,nomor_dokumen',
            'depositUsages.deposit',
            'createdBy:id,name,email', 'updatedBy:id,name,email', 'completedBy:id,name,email',
        ])->where('ulid', $ulid)->first();
        if (! $payment) {
            return $this->notFound('Pembayaran piutang tidak ditemukan.');
        }
        $payment->makeVisible('customer_id');
        $payment->customer?->makeVisible('id');
        $payment->details->each(function ($detail) {
            $detail->makeVisible('piutang_id');
            $detail->piutang?->makeVisible('id');
        });
        $payment->depositUsages->each(function ($usage) {
            $usage->makeVisible('deposit_id');
            $usage->deposit?->makeVisible('id');
        });

        if (! auth()->user()->can('piutang.view_nominal')) {
            $payment->makeHidden(['total_bayar_cash', 'total_bayar_deposit', 'total_pembayaran']);
            $payment->details->each(function ($detail) {
                $detail->makeHidden(['nominal_dibayar']);
                $detail->piutang?->makeHidden(['nominal_awal', 'nominal_terbayar', 'sisa_piutang', 'nominal_retur']);
            });
            $payment->depositUsages->each(function ($usage) {
                $usage->makeHidden(['nominal_digunakan']);
                $usage->deposit?->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
            });
        }

        return $this->success(['pembayaran' => $payment]);
    }

    public function update(Request $request, string $ulid, UpdatePembayaranPiutangAction $action): JsonResponse
    {
        if (! auth()->user()->can('pembayaran-piutang.update')) {
            return $this->forbidden();
        }
        $payment = DocPembayaranPiutang::where('ulid', $ulid)->first();
        if (! $payment) {
            return $this->notFound('Pembayaran piutang tidak ditemukan.');
        }

        return $this->runAction(fn () => $action->execute($payment, $request->validate($this->rules())));
    }

    public function destroy(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('pembayaran-piutang.delete')) {
            return $this->forbidden();
        }
        try {
            DB::transaction(function () use ($ulid) {
                $payment = DocPembayaranPiutang::where('ulid', $ulid)->lockForUpdate()->firstOrFail();
                if (! $payment->isDraft()) {
                    throw new \DomainException('Pembayaran completed tidak dapat dihapus.');
                }
                $payment->delete();
            });
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('Pembayaran piutang tidak ditemukan.');
        } catch (\DomainException $e) {
            return $this->error($e->getMessage(), 422);
        }

        return $this->success(null, 'Pembayaran piutang berhasil dihapus.');
    }

    public function complete(string $ulid, CompletePembayaranPiutangAction $action): JsonResponse
    {
        if (! auth()->user()->can('pembayaran-piutang.complete')) {
            return $this->forbidden();
        }
        $payment = DocPembayaranPiutang::where('ulid', $ulid)->first();
        if (! $payment) {
            return $this->notFound('Pembayaran piutang tidak ditemukan.');
        }

        return $this->runAction(fn () => $action->execute($payment));
    }

    public function getOutstandingPiutangs(Request $request): JsonResponse
    {
        if (! auth()->user()->can('pembayaran-piutang.create') && ! auth()->user()->can('pembayaran-piutang.update')) {
            return $this->forbidden();
        }
        $customer = $request->validate(['customer_id' => 'required|exists:master_customer,id'])['customer_id'];
        $items = CustomerPiutang::with('sales:id,ulid,nomor_dokumen')
            ->byCustomer($customer)->outstanding()->orderBy('tanggal_jatuh_tempo')->orderBy('tanggal')->get();
        $canViewNominal = auth()->user()->can('piutang.view_nominal');
        $items->each(function ($item) use ($canViewNominal) {
            $item->makeVisible('id');
            $item->sales?->makeVisible('id');
            if (! $canViewNominal) {
                $item->makeHidden(['nominal_awal', 'nominal_terbayar', 'sisa_piutang', 'nominal_retur']);
            }
        });

        return $this->success(['items' => $items]);
    }

    public function getAvailableDeposits(Request $request): JsonResponse
    {
        if (! auth()->user()->can('pembayaran-piutang.create') && ! auth()->user()->can('pembayaran-piutang.update')) {
            return $this->forbidden();
        }
        $customer = $request->validate(['customer_id' => 'required|exists:master_customer,id'])['customer_id'];
        $items = CustomerDeposit::with('salesReturn:id,ulid,nomor_dokumen')
            ->byCustomer($customer)->hasBalance()->orderBy('tanggal')->get();
        $canViewNominal = auth()->user()->can('piutang.view_nominal');
        $items->each(function ($item) use ($canViewNominal) {
            $item->makeVisible('id');
            if (! $canViewNominal) {
                $item->makeHidden(['nominal_awal', 'nominal_terpakai', 'sisa_deposit']);
            }
        });

        return $this->success(['items' => $items]);
    }

    private function rules(): array
    {
        return [
            'tanggal' => 'required|date',
            'customer_id' => ['required', 'exists:master_customer,id', function ($attribute, $value, $fail) {
                CustomerRules::assertActiveBackofficeCustomer($value, $fail);
            }],
            'metode_pembayaran' => 'required|in:cash,transfer',
            'no_referensi' => 'nullable|string|max:50',
            'bank_nama' => 'nullable|string|max:50',
            'bank_rekening' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:1000',
            'details' => 'required|array|min:1',
            'details.*.piutang_id' => 'required|integer|exists:customer_piutang,id',
            'details.*.nominal_dibayar' => 'required|numeric|min:0.01',
            'details.*.sumber' => 'required|in:cash,deposit',
            'deposit_usages' => 'nullable|array',
            'deposit_usages.*.deposit_id' => 'required|integer|exists:customer_deposit,id',
            'deposit_usages.*.nominal_digunakan' => 'required|numeric|min:0.01',
        ];
    }

    private function runAction(\Closure $callback, bool $created = false): JsonResponse
    {
        try {
            $payment = $callback();

            return $created
                ? $this->created(['pembayaran' => $payment], 'Pembayaran piutang berhasil dibuat.')
                : $this->success(['pembayaran' => $payment], 'Pembayaran piutang berhasil diproses.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors(), 'Validasi gagal');
        } catch (\Throwable $e) {
            return $this->error('Gagal memproses pembayaran piutang: '.$e->getMessage(), 500);
        }
    }
}
