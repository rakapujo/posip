<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SerialIntake\ApproveSerialIntakeAction;
use App\Actions\SerialIntake\CreateSerialIntakeAction;
use App\Actions\SerialIntake\UpdateSerialIntakeAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocSerialIntake;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Services\PurchaseMasterRules;
use App\Services\PurchaseOrderCalculationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pembelian Serial (modul serial A+) — alur draft → approved (konsisten dgn Purchase Order).
 * Create/Update = draft (belum sentuh stok). Approve = komit stok + HPP.
 */
class SerialIntakeController extends BaseApiController
{
    /** Field harga/finansial header yang disembunyikan dari user tanpa izin lihat harga. */
    private const HARGA_HEADER_FIELDS = [
        'subtotal', 'total_modal', 'total_diskon_header', 'total_setelah_diskon',
        'diskon_1_hasil', 'diskon_2_hasil', 'diskon_3_hasil',
        'biaya_kirim_hasil', 'biaya_lain_hasil', 'total_biaya_tambahan',
        'dpp', 'pajak_nominal', 'pembulatan', 'grand_total',
    ];

    public function __construct(
        private CreateSerialIntakeAction $createAction,
        private UpdateSerialIntakeAction $updateAction,
        private ApproveSerialIntakeAction $approveAction
    ) {
    }

    /**
     * Boleh lihat harga di DETAIL: punya serial-intake.view_harga ATAU bisa update
     * (editor butuh harga saat memuat form edit, yang juga lewat endpoint show).
     */
    private function canSeeHargaDetail(): bool
    {
        return auth()->user()->canAny(['serial-intake.view_harga', 'serial-intake.update']);
    }

    /**
     * Sembunyikan field harga BELI (modal/cost + total header) dari dokumen yang sudah dimuat.
     * `harga_jual` (harga jual unit) BUKAN rahasia → tetap tampil walau tanpa view_harga.
     */
    private function hideHargaFromIntake(DocSerialIntake $intake): void
    {
        $intake->makeHidden(self::HARGA_HEADER_FIELDS);
        if ($intake->relationLoaded('units')) {
            $intake->units->each(fn ($u) => $u->makeHidden(['harga_modal', 'cost_per_unit']));
        }
    }

    /**
     * List dokumen pembelian serial (paginated).
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('serial-intake.view')) {
            return $this->forbidden();
        }

        $query = DocSerialIntake::with([
                'product:id,ulid,kode_produk,nama_produk',
                'warehouse:id,ulid,kode_warehouse,nama_warehouse',
                'supplier:id,ulid,kode_supplier,nama_supplier',
                'createdBy:id,name',
            ])
            ->withCount('units');

        if ($request->filled('search')) {
            $query->search($request->search);
        }
        if ($request->filled('supplier_id')) {
            $query->where('supplier_id', $request->supplier_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('warehouse_id', $request->warehouse_id);
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
        $sortableFields = ['nomor_dokumen', 'tanggal', 'total_unit', 'total_modal', 'grand_total', 'created_at'];
        if (in_array($sortField, $sortableFields, true)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('tanggal', 'desc');
        }

        $perPage = $this->getPerPage($request, 15);
        $items = $query->paginate($perPage);

        // List (read-only): harga/total digate murni serial-intake.view_harga (editor pun tak lihat di list)
        if (!auth()->user()->can('serial-intake.view_harga')) {
            foreach ($items->items() as $it) {
                $it->makeHidden(self::HARGA_HEADER_FIELDS);
            }
        }

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

    /**
     * Detail dokumen + daftar unit.
     */
    public function show(DocSerialIntake $serialIntake): JsonResponse
    {
        if (!auth()->user()->can('serial-intake.view')) {
            return $this->forbidden();
        }

        $serialIntake->load([
            'product:id,ulid,kode_produk,nama_produk',
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'supplier:id,ulid,kode_supplier,nama_supplier',
            'units' => fn ($q) => $q->orderBy('id'),
            'createdBy:id,name',
            'updatedBy:id,name',
            'approvedBy:id,name',
        ]);

        // Sembunyikan harga untuk view-only (tanpa view_harga & tanpa update). Editor (punya update)
        // tetap dapat harga karena form edit memuat lewat endpoint ini; tampilan read-only digate FE.
        if (!$this->canSeeHargaDetail()) {
            $this->hideHargaFromIntake($serialIntake);
        }

        return $this->success(['serial_intake' => $serialIntake]);
    }

    /**
     * Simpan pembelian serial baru (DRAFT).
     */
    public function store(Request $request): JsonResponse
    {
        if (!auth()->user()->can('serial-intake.create')) {
            return $this->forbidden();
        }

        $request->validate($this->payloadRules());

        $intake = $this->createAction->execute($this->resolveRefs($request));

        return $this->created(['serial_intake' => $intake], 'Pembelian serial disimpan sebagai draft');
    }

    /**
     * Ubah pembelian serial (hanya draft).
     */
    public function update(Request $request, DocSerialIntake $serialIntake): JsonResponse
    {
        if (!auth()->user()->can('serial-intake.update')) {
            return $this->forbidden();
        }
        if (!$serialIntake->isDraft()) {
            return $this->error('Hanya draft yang dapat diubah.', 422);
        }

        $request->validate($this->payloadRules());

        $intake = $this->updateAction->execute($serialIntake, $this->resolveRefs($request));

        return $this->success(['serial_intake' => $intake], 'Pembelian serial diperbarui');
    }

    /**
     * Approve pembelian serial (draft → approved, komit stok + HPP).
     */
    public function approve(DocSerialIntake $serialIntake): JsonResponse
    {
        if (!auth()->user()->can('serial-intake.approve')) {
            return $this->forbidden();
        }

        $intake = $this->approveAction->execute($serialIntake);

        return $this->success(['serial_intake' => $intake], 'Pembelian serial disetujui & stok diperbarui');
    }

    /**
     * Hapus pembelian serial (hanya draft).
     */
    public function destroy(DocSerialIntake $serialIntake): JsonResponse
    {
        if (!auth()->user()->can('serial-intake.delete')) {
            return $this->forbidden();
        }
        if (!$serialIntake->isDraft()) {
            return $this->error('Hanya draft yang dapat dihapus.', 422);
        }

        $serialIntake->units()->forceDelete();
        $serialIntake->delete();

        return $this->success(null, 'Pembelian serial dihapus');
    }

    /**
     * Preview kalkulasi finansial (tanpa simpan) untuk Ringkasan di form.
     */
    public function calculate(Request $request): JsonResponse
    {
        if (!auth()->user()->canAny(['serial-intake.create', 'serial-intake.update'])) {
            return $this->forbidden();
        }

        $units = $request->input('units', []);
        $details = array_map(fn ($u) => [
            'product_id' => 0,
            'unit_used' => 'UNIT',
            'unit_konversi' => 1,
            'qty_in_unit' => 1,
            'harga_per_unit' => (float) ($u['harga_modal'] ?? 0),
        ], is_array($units) ? $units : []);

        $calc = PurchaseOrderCalculationService::calculateTotals([
            'details' => $details,
            'diskon_1_tipe' => $request->input('diskon_1_tipe', 'none'),
            'diskon_1_nilai' => $request->input('diskon_1_nilai', 0),
            'diskon_2_tipe' => $request->input('diskon_2_tipe', 'none'),
            'diskon_2_nilai' => $request->input('diskon_2_nilai', 0),
            'diskon_3_tipe' => $request->input('diskon_3_tipe', 'none'),
            'diskon_3_nilai' => $request->input('diskon_3_nilai', 0),
            'biaya_kirim_tipe' => $request->input('biaya_kirim_tipe', 'none'),
            'biaya_kirim_nilai' => $request->input('biaya_kirim_nilai', 0),
            'biaya_lain_nama' => $request->input('biaya_lain_nama'),
            'biaya_lain_tipe' => $request->input('biaya_lain_tipe', 'none'),
            'biaya_lain_nilai' => $request->input('biaya_lain_nilai', 0),
        ]);

        unset($calc['details']);

        return $this->success(['calculation' => $calc]);
    }

    // ==================== HELPERS ====================

    /**
     * Aturan validasi payload (dipakai store & update).
     */
    private function payloadRules(): array
    {
        return [
            'product_id' => 'required|string',
            'warehouse_id' => 'required|string',
            'supplier_id' => 'required|string',
            'tanggal' => 'nullable|date',
            'no_doc_referensi' => 'nullable|string|max:100',
            'notes' => 'nullable|string|max:1000',
            // Cash / lunas langsung — hutang dibuat lalu auto-lunas saat approve.
            // Panjang max disamakan dgn kolom doc_pembayaran_hutang (no_referensi/bank_nama 50, bank_rekening 30)
            // agar tak rollback saat settle. Metode wajib bila cash dicentang (cegah fallback diam ke 'cash').
            'cash_payment' => 'nullable|boolean',
            'cash_metode' => 'nullable|required_if:cash_payment,true,1|in:cash,transfer',
            'cash_no_referensi' => 'nullable|string|max:50',
            'cash_bank_nama' => 'nullable|string|max:50',
            'cash_bank_rekening' => 'nullable|string|max:30',
            // Diskon header (3 line) + biaya tambahan + tempo (seperti PO)
            'diskon_1_tipe' => 'nullable|in:percent,nominal,none',
            'diskon_1_nilai' => 'nullable|numeric|min:0',
            'diskon_2_tipe' => 'nullable|in:percent,nominal,none',
            'diskon_2_nilai' => 'nullable|numeric|min:0',
            'diskon_3_tipe' => 'nullable|in:percent,nominal,none',
            'diskon_3_nilai' => 'nullable|numeric|min:0',
            'biaya_kirim_tipe' => 'nullable|in:percent,nominal,none',
            'biaya_kirim_nilai' => 'nullable|numeric|min:0',
            'biaya_lain_nama' => 'nullable|string|max:100',
            'biaya_lain_tipe' => 'nullable|in:percent,nominal,none',
            'biaya_lain_nilai' => 'nullable|numeric|min:0',
            'tempo_hari' => 'nullable|integer|min:0',
            'units' => 'required|array|min:1',
            'units.*.serial_number' => 'required|string|max:100',
            'units.*.kode_internal' => 'nullable|string|max:40', // kosong = auto KI-{id}; diisi = override unik
            'units.*.harga_modal' => 'required|numeric|min:0',
            'units.*.harga_jual' => 'required|numeric|min:0',
            'units.*.grade' => 'required|string|in:A,B,C,D,E,F',
            'units.*.battery_condition' => 'required|string|max:30',
            'units.*.battery_health' => 'required|numeric|min:0|max:100',
            'units.*.account_status' => 'required|string|in:locked,unlocked',
            'units.*.catatan' => 'nullable|string|max:255',
        ];
    }

    /**
     * Resolve ULID publik (product/warehouse/supplier) → id internal.
     *
     * @throws ValidationException
     */
    private function resolveRefs(Request $request): array
    {
        $product = MasterProduk::where('ulid', $request->input('product_id'))->first();
        if (!$product) {
            throw ValidationException::withMessages(['product_id' => ['Produk tidak ditemukan.']]);
        }
        if (!$product->is_serial) {
            throw ValidationException::withMessages(['product_id' => ['Produk ini bukan produk serial.']]);
        }

        $warehouse = MasterWarehouse::where('ulid', $request->input('warehouse_id'))->first();
        if (!$warehouse) {
            throw ValidationException::withMessages(['warehouse_id' => ['Gudang tidak ditemukan.']]);
        }
        if ($warehouseErrors = PurchaseMasterRules::warehouseErrors($warehouse->id)) {
            throw ValidationException::withMessages($warehouseErrors);
        }

        $supplierId = null;
        if ($request->filled('supplier_id')) {
            $supplier = MasterSupplier::where('ulid', $request->input('supplier_id'))->first();
            if (!$supplier) {
                throw ValidationException::withMessages(['supplier_id' => ['Supplier tidak ditemukan.']]);
            }
            if ($supplierErrors = PurchaseMasterRules::supplierErrors($supplier->id)) {
                throw ValidationException::withMessages($supplierErrors);
            }
            $supplierId = $supplier->id;
        }

        return [
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'supplier_id' => $supplierId,
            'tanggal' => $request->input('tanggal') ?: now(),
            'no_doc_referensi' => $request->input('no_doc_referensi'),
            'notes' => $request->input('notes'),
            // Cash / lunas langsung
            'cash_payment' => $request->boolean('cash_payment'),
            'cash_metode' => $request->input('cash_metode'),
            'cash_no_referensi' => $request->input('cash_no_referensi'),
            'cash_bank_nama' => $request->input('cash_bank_nama'),
            'cash_bank_rekening' => $request->input('cash_bank_rekening'),
            // Finansial header (diskon/biaya/tempo) — dihitung di action via calc service
            'diskon_1_tipe' => $request->input('diskon_1_tipe', 'none'),
            'diskon_1_nilai' => $request->input('diskon_1_nilai', 0),
            'diskon_2_tipe' => $request->input('diskon_2_tipe', 'none'),
            'diskon_2_nilai' => $request->input('diskon_2_nilai', 0),
            'diskon_3_tipe' => $request->input('diskon_3_tipe', 'none'),
            'diskon_3_nilai' => $request->input('diskon_3_nilai', 0),
            'biaya_kirim_tipe' => $request->input('biaya_kirim_tipe', 'none'),
            'biaya_kirim_nilai' => $request->input('biaya_kirim_nilai', 0),
            'biaya_lain_nama' => $request->input('biaya_lain_nama'),
            'biaya_lain_tipe' => $request->input('biaya_lain_tipe', 'none'),
            'biaya_lain_nilai' => $request->input('biaya_lain_nilai', 0),
            'tempo_hari' => (int) $request->input('tempo_hari', 0),
            'units' => $request->input('units'),
        ];
    }
}
