<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SalesReturn\ApproveSalesReturnAction;
use App\Actions\SalesReturn\CreateSalesReturnAction;
use App\Actions\SalesReturn\LockSalesReturnAction;
use App\Actions\SalesReturn\UpdateSalesReturnAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocSales;
use App\Models\DocSalesReturn;
use App\Models\MasterCustomer;
use App\Models\SerialUnit;
use App\Services\CustomerRules;
use App\Services\ReportHelperService;
use App\Services\SalesReturnCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BackofficeSalesReturnController extends BaseApiController
{
    private function rules(bool $requireSales = false): array
    {
        return [
            'tanggal' => ['required', 'date'],
            'sales_id' => [$requireSales ? 'required' : 'nullable', 'integer', 'exists:doc_sales,id'],
            'customer_id' => ['nullable', 'integer', 'exists:master_customer,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:master_warehouse,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'details' => ['required', 'array', 'min:1'],
            'details.*.sales_detail_id' => ['nullable', 'integer', 'exists:doc_sales_detail,id'],
            'details.*.product_id' => ['required', 'integer', 'exists:master_produk,id'],
            'details.*.qty_base' => ['required', 'numeric', 'min:0.01'],
            'details.*.harga_satuan' => ['nullable', 'numeric', 'min:0'],
            'details.*.unit' => ['nullable', 'string', 'max:50'],
            'details.*.serial_unit_ids' => ['nullable', 'array'],
            'details.*.serial_unit_ids.*' => ['string'],
        ];
    }

    private function validatePayload(array $validated): ?JsonResponse
    {
        $isLinked = ! empty($validated['sales_id']);
        if ($isLinked) {
            foreach ($validated['details'] as $i => $detail) {
                if (empty($detail['sales_detail_id'])) {
                    return $this->validationError([
                        "details.{$i}.sales_detail_id" => ['sales_detail_id wajib bila ada nota.'],
                    ]);
                }
            }
        } else {
            if (empty($validated['customer_id']) || empty($validated['warehouse_id'])) {
                return $this->validationError([
                    'customer_id' => ['Customer dan gudang wajib untuk retur bebas (tanpa nota).'],
                ]);
            }
            if ($message = CustomerRules::backofficeBlockMessage(MasterCustomer::find($validated['customer_id']))) {
                return $this->validationError(['customer_id' => [$message]]);
            }
            foreach ($validated['details'] as $i => $detail) {
                if (! array_key_exists('harga_satuan', $detail) || $detail['harga_satuan'] === null) {
                    return $this->validationError([
                        "details.{$i}.harga_satuan" => ['Harga wajib diisi pada retur bebas.'],
                    ]);
                }
            }
        }

        return null;
    }

    /**
     * Check for duplicate product+unit combinations in details.
     * Serial lines (serial_unit_ids present) are unique by product_id only —
     * satu produk serial hanya boleh satu baris per retur.
     */
    private function hasDuplicateProducts(array $details): bool
    {
        $keys = collect($details)->map(function ($detail) {
            if (! empty($detail['serial_unit_ids'])) {
                return $detail['product_id'].'-serial';
            }

            return $detail['product_id'].'-'.($detail['unit'] ?? '');
        });

        return $keys->count() !== $keys->unique()->count();
    }

    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->can('retur-jual.view')) {
            return $this->forbidden('Anda tidak memiliki akses melihat retur penjualan.');
        }

        $query = DocSalesReturn::manual()
            ->with([
                'sales:id,ulid,nomor_dokumen',
                'customer:id,ulid,kode_customer,nama',
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
                'createdBy:id,name',
            ])
            ->withCount('details');
        if ($request->filled('search')) {
            $query->search((string) $request->input('search'));
        }
        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }
        foreach (['customer_id', 'warehouse_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('date_from')) {
            $query->where('tanggal', '>=', $request->input('date_from').' 00:00:00');
        }
        if ($request->filled('date_to')) {
            $query->where('tanggal', '<=', $request->input('date_to').' 23:59:59');
        }

        $items = $query->orderByDesc('tanggal')->paginate($this->getPerPage($request, 15));

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

    public function show(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('retur-jual.view')) {
            return $this->forbidden('Anda tidak memiliki akses melihat retur penjualan.');
        }

        $return = DocSalesReturn::manual()->with([
            'sales:id,ulid,nomor_dokumen,status',
            'customer:id,ulid,kode_customer,nama',
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'details.product:id,ulid,kode_produk,nama_produk,is_serial',
            'deposit',
            'createdBy:id,name',
            'updatedBy:id,name',
            'lockedBy:id,name',
            'approvedBy:id,name',
        ])->where('ulid', $ulid)->first();

        return $return
            ? $this->success(['sales_return' => tap($return, function ($r) {
                $r->makeVisible(['sales_id', 'customer_id', 'warehouse_id']);
                $r->customer?->makeVisible('id');
                $r->warehouse?->makeVisible('id');
                $r->sales?->makeVisible('id');
                $r->details->each(fn ($d) => $d->makeVisible(['sales_detail_id', 'product_id']));
                if (! auth()->user()->can('stok.view_hpp')) {
                    $r->details->each->makeHidden(['hpp_at_time']);
                }
            })])
            : $this->notFound('Retur penjualan tidak ditemukan.');
    }

    public function store(Request $request, CreateSalesReturnAction $action): JsonResponse
    {
        if (! auth()->user()->can('retur-jual.create')) {
            return $this->forbidden('Anda tidak memiliki akses membuat retur penjualan.');
        }

        $validated = $request->validate($this->rules());
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError([
                'details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu retur.'],
            ]);
        }
        if ($response = $this->validatePayload($validated)) {
            return $response;
        }

        return $this->runAction(
            fn () => $this->created(['sales_return' => $action->execute($validated)], 'Retur penjualan berhasil dibuat.')
        );
    }

    public function update(Request $request, string $ulid, UpdateSalesReturnAction $action): JsonResponse
    {
        if (! auth()->user()->can('retur-jual.update')) {
            return $this->forbidden('Anda tidak memiliki akses mengubah retur penjualan.');
        }

        $return = DocSalesReturn::manual()->where('ulid', $ulid)->first();
        if (! $return) {
            return $this->notFound('Retur penjualan tidak ditemukan.');
        }
        $validated = $request->validate($this->rules());
        if ($this->hasDuplicateProducts($validated['details'])) {
            return $this->validationError([
                'details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu retur.'],
            ]);
        }
        if ($response = $this->validatePayload($validated)) {
            return $response;
        }
        // Mode (linked/free) dan sales_id tidak boleh diganti setelah create
        if ((int) ($validated['sales_id'] ?? 0) !== (int) ($return->sales_id ?? 0)) {
            return $this->validationError(['sales_id' => ['Penjualan asal / mode retur tidak dapat diubah.']]);
        }

        return $this->runAction(
            fn () => $this->success(['sales_return' => $action->execute($return, $validated)], 'Retur penjualan berhasil diperbarui.')
        );
    }

    public function destroy(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('retur-jual.delete')) {
            return $this->forbidden('Anda tidak memiliki akses menghapus retur penjualan.');
        }

        $deleted = DB::transaction(function () use ($ulid) {
            $return = DocSalesReturn::manual()->where('ulid', $ulid)->lockForUpdate()->first();
            if (! $return) {
                return null;
            }
            if (! $return->isDraft()) {
                throw ValidationException::withMessages(['status' => ['Hanya retur draft yang dapat dihapus.']]);
            }
            $return->delete();

            return true;
        });

        return $deleted
            ? $this->success(null, 'Retur penjualan berhasil dihapus.')
            : $this->notFound('Retur penjualan tidak ditemukan.');
    }

    public function lock(string $ulid, LockSalesReturnAction $action): JsonResponse
    {
        if (! auth()->user()->can('retur-jual.lock')) {
            return $this->forbidden('Anda tidak memiliki akses mengunci retur penjualan.');
        }

        $return = DocSalesReturn::manual()->where('ulid', $ulid)->first();
        if (! $return) {
            return $this->notFound('Retur penjualan tidak ditemukan.');
        }

        return $this->runAction(
            fn () => $this->success(['sales_return' => $action->execute($return)], 'Retur dikunci dan stok dikembalikan.')
        );
    }

    public function approve(Request $request, string $ulid, ApproveSalesReturnAction $action): JsonResponse
    {
        if (! auth()->user()->can('retur-jual.approve')) {
            return $this->forbidden('Anda tidak memiliki akses menyetujui retur penjualan.');
        }

        $return = DocSalesReturn::manual()->where('ulid', $ulid)->first();
        if (! $return) {
            return $this->notFound('Retur penjualan tidak ditemukan.');
        }
        $validated = $request->validate([
            'nilai_diakui' => ['required', 'numeric', 'min:0', 'max:'.$return->grand_total],
            'catatan_approval' => ['nullable', 'string', 'max:1000'],
        ]);

        return $this->runAction(
            fn () => $this->success(['sales_return' => $action->execute($return, $validated)], 'Retur penjualan berhasil disetujui.')
        );
    }

    public function returnableSales(Request $request): JsonResponse
    {
        if (! auth()->user()->can('retur-jual.create') && ! auth()->user()->can('retur-jual.update')) {
            return $this->forbidden('Anda tidak memiliki akses membuat retur penjualan.');
        }

        $bought = ReportHelperService::sqlSalesBoughtBase('doc_sales.id');
        $returned = ReportHelperService::sqlSalesReturnedBase('doc_sales.id');
        $query = DocSales::manual()->completed()
            ->with([
                'customer:id,ulid,kode_customer,nama',
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            ])
            ->whereRaw("{$bought} > {$returned}");
        if ($request->filled('search')) {
            $query->search((string) $request->input('search'));
        }
        if ($request->filled('customer_id')) {
            $query->where('customer_id', (int) $request->input('customer_id'));
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', (int) $request->input('warehouse_id'));
        }

        $sales = $query->orderByDesc('tanggal')->limit(50)->get();
        $sales->each->makeVisible(['id', 'warehouse_id', 'customer_id']);

        return $this->success(['items' => $sales]);
    }

    public function returnableDetails(string $salesUlid): JsonResponse
    {
        if (! auth()->user()->can('retur-jual.create') && ! auth()->user()->can('retur-jual.update')) {
            return $this->forbidden('Anda tidak memiliki akses membuat retur penjualan.');
        }

        $sales = DocSales::manual()->completed()->with([
            'customer:id,ulid,kode_customer,nama',
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'details.product:id,ulid,kode_produk,nama_produk,unit_1,is_serial',
            'details.returnDetails',
        ])->where('ulid', $salesUlid)->first();
        if (! $sales) {
            return $this->notFound('Penjualan backoffice completed tidak ditemukan.');
        }

        $serials = SerialUnit::whereIn('sale_detail_id', $sales->details->pluck('id'))
            ->where('status', SerialUnit::STATUS_TERJUAL)
            ->orderBy('serial_number')
            ->get(['ulid', 'sale_detail_id', 'kode_internal', 'serial_number', 'grade'])
            ->groupBy('sale_detail_id');

        $unitPrices = SalesReturnCalculationService::unitPrices($sales);

        $details = $sales->details->map(function ($detail) use ($serials, $unitPrices) {
            $returned = (float) $detail->returnDetails->sum('qty_base');
            $detail->returned_base = $returned;
            $detail->returnable_base = (float) $detail->qty_base - $returned;
            $detail->returnable_units = $serials->get($detail->id, collect())->values();
            $detail->harga_efektif = $unitPrices[$detail->id] ?? 0;
            $detail->makeVisible(['id', 'product_id', 'harga_satuan', 'jumlah', 'qty_base']);
            $detail->product?->makeVisible('id');
            $detail->unsetRelation('returnDetails');

            return $detail;
        })->filter(fn ($detail) => $detail->returnable_base > 0)->values();

        $sales->makeVisible(['id', 'warehouse_id', 'customer_id', 'grand_total', 'subtotal']);
        $sales->setRelation('details', $details);

        if ($details->isEmpty()) {
            return $this->success([
                'sales' => $sales,
                'message' => 'Semua item dari nota ini sudah diretur / tidak ada qty returnable.',
            ]);
        }

        return $this->success(['sales' => $sales]);
    }

    private function runAction(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (ValidationException $exception) {
            return $this->validationError($exception->errors(), $exception->getMessage());
        }
    }
}
