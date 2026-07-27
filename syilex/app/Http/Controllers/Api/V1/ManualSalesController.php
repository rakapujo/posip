<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ApproveManualSalesAction;
use App\Actions\Sales\CreateManualSalesAction;
use App\Actions\Sales\UpdateManualSalesAction;
use App\Actions\Sales\VoidManualSalesAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Http\Controllers\Concerns\AttachesSerialUnits;
use App\Models\DocSales;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Services\CustomerRules;
use App\Services\ManualSalesCalculationService;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ManualSalesController extends BaseApiController
{
    use AttachesSerialUnits;

    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->can('sales.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $query = DocSales::manual()
            ->with([
                'customer:id,ulid,kode_customer,nama',
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
                'createdBy:id,name',
                'piutang',
            ])
            ->withCount('details');
        if ($request->filled('search')) {
            $query->search((string) $request->input('search'));
        }
        foreach (['status', 'customer_id', 'warehouse_id'] as $field) {
            if ($request->filled($field)) {
                $query->where($field, $request->input($field));
            }
        }
        if ($request->filled('date_from') || $request->filled('date_to')) {
            $query->byDateRange($request->date_from, $request->date_to);
        }
        $sort = in_array($request->input('sort_field'), ['nomor_dokumen', 'tanggal', 'grand_total', 'created_at'], true)
            ? $request->input('sort_field')
            : 'tanggal';
        $direction = $request->input('sort_order') === 'asc' ? 'asc' : 'desc';
        $page = $query->orderBy($sort, $direction)->paginate($this->getPerPage($request, 15));

        $canViewHarga = auth()->user()->can('sales.view_harga');
        $items = collect($page->items())->map(function ($item) use ($canViewHarga) {
            if (! $canViewHarga) {
                $item->makeHidden([
                    'subtotal', 'total_diskon', 'total_setelah_diskon',
                    'total_biaya_pembayaran', 'dpp', 'pajak_nominal', 'pembulatan', 'grand_total',
                    'diskon_nota_1_hasil', 'diskon_nota_2_hasil', 'diskon_nota_3_hasil',
                    'biaya_kirim_hasil', 'biaya_lain_hasil', 'total_bayar', 'kembalian',
                ]);
                if ($item->relationLoaded('piutang') && $item->piutang) {
                    $item->piutang->makeHidden(['nominal_awal', 'nominal_terbayar', 'sisa_piutang', 'nominal_retur']);
                }
            }

            return $item;
        });

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
            ],
        ]);
    }

    public function show(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('sales.view')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }
        $sales = $this->manualQuery()->where('ulid', $ulid)->first();
        if (! $sales) {
            return $this->notFound('Penjualan tidak ditemukan.');
        }

        $sales = $this->makeBindingIdsVisible($sales);
        if (! auth()->user()->can('stok.view_hpp')) {
            foreach ($sales->details ?? [] as $detail) {
                $detail->makeHidden(['hpp_at_time']);
            }
        }
        if (! auth()->user()->can('sales.view_harga')) {
            $sales->makeHidden([
                'subtotal', 'total_diskon', 'total_setelah_diskon',
                'total_biaya_pembayaran', 'dpp', 'pajak_nominal', 'pembulatan', 'grand_total',
                'diskon_nota_1_hasil', 'diskon_nota_2_hasil', 'diskon_nota_3_hasil',
                'biaya_kirim_hasil', 'biaya_lain_hasil', 'total_bayar', 'kembalian',
            ]);
            foreach ($sales->details ?? [] as $detail) {
                $detail->makeHidden([
                    'harga_satuan', 'harga_bruto', 'diskon_1_hasil', 'diskon_2_hasil',
                    'diskon_3_hasil', 'diskon_4_hasil', 'diskon_5_hasil', 'diskon_total', 'jumlah',
                ]);
            }
            if ($sales->relationLoaded('piutang') && $sales->piutang) {
                $sales->piutang->makeHidden(['nominal_awal', 'nominal_terbayar', 'sisa_piutang', 'nominal_retur']);
            }
        }

        return $this->success(['sales' => $sales]);
    }

    public function store(Request $request, CreateManualSalesAction $action): JsonResponse
    {
        if (! auth()->user()->can('sales.create')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }
        $data = $request->validate($this->rules());
        if ($this->hasDuplicateProducts($data['details'])) {
            return $this->validationError([
                'details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu penjualan.'],
            ]);
        }
        if ($response = $this->validateMasters($data)) {
            return $response;
        }
        $data['tempo_hari'] ??= (int) MasterCustomer::find($data['customer_id'])->tempo_default;
        $data = $this->normalizeTempoCash($data);

        try {
            return $this->created(['sales' => $action->execute($data)], 'Penjualan draft berhasil dibuat.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function update(Request $request, string $ulid, UpdateManualSalesAction $action): JsonResponse
    {
        if (! auth()->user()->can('sales.update')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }
        $sales = DocSales::manual()->where('ulid', $ulid)->first();
        if (! $sales) {
            return $this->notFound('Penjualan tidak ditemukan.');
        }
        $data = $request->validate($this->rules());
        if ($this->hasDuplicateProducts($data['details'])) {
            return $this->validationError([
                'details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu penjualan.'],
            ]);
        }
        if ($response = $this->validateMasters($data)) {
            return $response;
        }
        $data = $this->normalizeTempoCash($data);

        try {
            return $this->success(['sales' => $action->execute($sales, $data)], 'Penjualan berhasil diperbarui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function destroy(string $ulid): JsonResponse
    {
        if (! auth()->user()->can('sales.delete')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        try {
            DB::transaction(function () use ($ulid) {
                $sales = DocSales::manual()->where('ulid', $ulid)->lockForUpdate()->firstOrFail();
                if (! $sales->isDraft()) {
                    throw ValidationException::withMessages(['status' => ['Hanya draft yang dapat dihapus.']]);
                }
                $sales->delete();
            });

            return $this->success(null, 'Penjualan draft berhasil dihapus.');
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException) {
            return $this->notFound('Penjualan tidak ditemukan.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function calculate(Request $request): JsonResponse
    {
        if (! auth()->user()->can('sales.create') && ! auth()->user()->can('sales.update')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }
        $data = $request->validate(array_merge($this->rules(), [
            'rebuild_promos' => 'nullable|boolean',
        ]));
        $rebuild = (bool) ($data['rebuild_promos'] ?? false);
        unset($data['rebuild_promos']);
        $data = $this->normalizeTempoCash($data);
        if ($this->hasDuplicateProducts($data['details'])) {
            return $this->validationError([
                'details' => ['Tidak boleh ada produk dengan satuan yang sama dalam satu penjualan.'],
            ]);
        }

        try {
            return $this->success(['calculation' => ManualSalesCalculationService::calculate($data, $rebuild)]);
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function products(Request $request): JsonResponse
    {
        if (! auth()->user()->can('sales.create') && ! auth()->user()->can('sales.update')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }
        $request->validate(['search' => 'nullable|string|max:100']);
        $query = MasterProduk::active()->select([
            'id', 'ulid', 'kode_produk', 'nama_produk', 'barcode', 'is_serial',
            'unit_1', 'konversi_1', 'harga_1', 'unit_2', 'konversi_2', 'harga_2',
            'unit_3', 'konversi_3', 'harga_3', 'unit_4', 'konversi_4', 'harga_4',
        ]);
        if (! SettingService::isElektronikEnabled()) {
            $query->where('is_serial', false);
        }
        if ($request->filled('search')) {
            $query->search((string) $request->input('search'));
        }
        $items = $query->limit(20)->get()->makeVisible('id')->map(function ($product) {
            $units = [];
            for ($slot = 1; $slot <= 4; $slot++) {
                if ($product->{"unit_{$slot}"}) {
                    $units[] = [
                        'unit' => $product->{"unit_{$slot}"},
                        'konversi' => $product->{"konversi_{$slot}"},
                        'harga_jual' => $product->{"harga_{$slot}"},
                    ];
                }
            }
            $product->setAttribute('units', collect($units)->unique('unit')->values());
            if (! auth()->user()->can('sales.view_harga')) {
                $product->makeHidden(['harga_1', 'harga_2', 'harga_3', 'harga_4']);
                $product->units = collect($product->units)->map(function ($unit) {
                    unset($unit['harga_jual']);

                    return $unit;
                })->values();
            }

            return $product;
        });

        return $this->success(['items' => $items]);
    }

    public function taxSettings(): JsonResponse
    {
        if (! auth()->user()->can('sales.create') && ! auth()->user()->can('sales.update')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        return $this->success(['tax' => SettingService::getSalesTaxSettings()]);
    }

    public function approve(string $ulid, ApproveManualSalesAction $action): JsonResponse
    {
        if (! auth()->user()->can('sales.approve')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }
        $sales = DocSales::manual()->where('ulid', $ulid)->first();
        if (! $sales) {
            return $this->notFound('Penjualan tidak ditemukan.');
        }
        try {
            return $this->success(['sales' => $action->execute($sales)], 'Penjualan berhasil disetujui.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    public function void(Request $request, string $ulid, VoidManualSalesAction $action): JsonResponse
    {
        if (! auth()->user()->can('sales.void')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $sales = DocSales::manual()->where('ulid', $ulid)->first();
        if (! $sales) {
            return $this->notFound('Penjualan tidak ditemukan.');
        }
        try {
            return $this->success(['sales' => $action->execute($sales, $data['reason'])], 'Penjualan berhasil di-void.');
        } catch (ValidationException $e) {
            return $this->validationError($e->errors());
        }
    }

    private function rules(): array
    {
        $discounts = [];
        for ($slot = 1; $slot <= 5; $slot++) {
            $discounts["details.*.diskon_{$slot}_tipe"] = 'nullable|in:none,percent,nominal';
            $discounts["details.*.diskon_{$slot}_nilai"] = 'nullable|numeric|min:0';
        }

        return array_merge([
            'tanggal' => 'required|date',
            'customer_id' => 'required|integer|exists:master_customer,id',
            'warehouse_id' => 'required|integer|exists:master_warehouse,id',
            'tempo_hari' => 'nullable|integer|min:0',
            'cash_payment' => 'nullable|boolean',
            'cash_metode' => 'nullable|required_if:cash_payment,true,1|in:cash,transfer',
            'cash_no_referensi' => 'nullable|string|max:50',
            'cash_bank_nama' => 'nullable|string|max:50',
            'cash_bank_rekening' => 'nullable|string|max:30',
            'notes' => 'nullable|string|max:1000',
            'discounts' => 'nullable|array|max:3',
            'discounts.*.tipe' => 'nullable|in:none,percent,nominal',
            'discounts.*.nilai' => 'nullable|numeric|min:0',
            'biaya_kirim.tipe' => 'nullable|in:none,percent,nominal',
            'biaya_kirim.nilai' => 'nullable|numeric|min:0',
            'biaya_lain.tipe' => 'nullable|in:none,percent,nominal',
            'biaya_lain.nilai' => 'nullable|numeric|min:0',
            'biaya_lain_nama' => 'nullable|string|max:100',
            'details' => 'required|array|min:1',
            'details.*.product_id' => 'required|integer|exists:master_produk,id',
            'details.*.unit' => 'required|string|max:30',
            'details.*.konversi' => 'required|integer|min:1',
            'details.*.qty' => 'required|numeric|gt:0',
            'details.*.harga_satuan' => 'required|numeric|min:0',
            'details.*.serial_unit_ids' => 'nullable|array',
            'details.*.serial_unit_ids.*' => 'string|exists:serial_units,ulid',
        ], $discounts);
    }

    /**
     * Check for duplicate product+unit combinations in details.
     */
    private function hasDuplicateProducts(array $details): bool
    {
        $keys = collect($details)->map(function ($detail) {
            return $detail['product_id'].'-'.$detail['unit'];
        });

        return $keys->count() !== $keys->unique()->count();
    }

    private function validateMasters(array $data): ?JsonResponse
    {
        $customer = MasterCustomer::find($data['customer_id']);
        $warehouse = MasterWarehouse::find($data['warehouse_id']);
        $errors = [];
        if (! $customer?->isActive()) {
            $errors['customer_id'][] = 'Customer harus aktif.';
        }
        if ($message = CustomerRules::backofficeBlockMessage($customer)) {
            $errors['customer_id'][] = $message;
        }
        if (! $warehouse?->isActive() || ! $warehouse->isSaleable()) {
            $errors['warehouse_id'][] = 'Warehouse harus aktif dan saleable.';
        }
        $productIds = collect($data['details'])->pluck('product_id')->unique();
        if (MasterProduk::active()->whereIn('id', $productIds)->count() !== $productIds->count()) {
            $errors['details'][] = 'Semua produk harus aktif.';
        }

        return $errors ? $this->validationError($errors) : null;
    }

    private function manualQuery()
    {
        return DocSales::manual()->with([
            'customer:id,ulid,kode_customer,nama,tempo_default,alamat,telepon',
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'details.product:id,ulid,kode_produk,nama_produk,barcode,is_serial,unit_1,konversi_1,unit_2,konversi_2,unit_3,konversi_3,unit_4,konversi_4',
            'createdBy:id,name,email',
            'approvedBy:id,name,email',
            'piutang.paymentDetails.pembayaran',
        ]);
    }

    /**
     * Cash ↔ tempo bidirectional rules:
     * cash ON or tempo ≤ 0 → cash + tempo 0; else non-cash with tempo ≥ 1.
     */
    private function normalizeTempoCash(array $data): array
    {
        $cash = filter_var($data['cash_payment'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $tempo = (int) ($data['tempo_hari'] ?? 0);

        if ($cash || $tempo <= 0) {
            $data['cash_payment'] = true;
            $data['tempo_hari'] = 0;
            $data['cash_metode'] = $data['cash_metode'] ?? 'cash';
        } else {
            $data['cash_payment'] = false;
            $data['cash_metode'] = null;
            $data['cash_no_referensi'] = null;
            $data['cash_bank_nama'] = null;
            $data['cash_bank_rekening'] = null;
        }

        return $data;
    }

    private function makeBindingIdsVisible(DocSales $sales): DocSales
    {
        $this->attachSerialUnitsToSale($sales);

        $sales->makeVisible(['customer_id', 'warehouse_id']);
        $sales->customer?->makeVisible('id');
        $sales->warehouse?->makeVisible('id');
        foreach ($sales->details as $detail) {
            $detail->makeVisible('product_id');
            $detail->product?->makeVisible('id');
        }

        return $sales;
    }
}
