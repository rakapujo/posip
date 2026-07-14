<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SerialUnitExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\SerialUnit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Register Unit Serial (read-only) — telusuri tiap unit fisik per nomor seri:
 * SN, modal, harga jual, atribut, status (tersedia/terjual), dan asal dokumen intake.
 *
 * Menjawab kebutuhan audit unit-level (modul serial A+) TANPA mengubah model stok
 * agregat: stok produk tetap di inventory_stock, HPP produk tetap weighted-avg.
 * Pakai permission baca yang sama dgn pembelian serial (serial-intake.view).
 */
class SerialUnitController extends BaseApiController
{
    /**
     * List unit serial (paginated) + ringkasan status.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('serial-intake.view')) {
            return $this->forbidden();
        }

        // Base query (filter selain status) — dipakai untuk ringkasan & list
        $base = SerialUnit::query();

        if ($request->filled('product_id')) {
            $pid = $request->product_id;
            $base->whereHas('product', function ($q) use ($pid) {
                is_numeric($pid) ? $q->where('id', $pid) : $q->where('ulid', $pid);
            });
        }
        if ($request->filled('warehouse_id')) {
            $wid = $request->warehouse_id;
            $base->whereHas('warehouse', function ($q) use ($wid) {
                is_numeric($wid) ? $q->where('id', $wid) : $q->where('ulid', $wid);
            });
        }
        if ($request->filled('intake_id')) {
            $iid = $request->intake_id;
            $base->whereHas('intake', function ($q) use ($iid) {
                is_numeric($iid) ? $q->where('id', $iid) : $q->where('ulid', $iid);
            });
        }
        if ($request->filled('search')) {
            $base->search($request->search);
        }

        // Ringkasan status (hormati filter, sebelum filter status diterapkan)
        $summary = [
            'total' => (clone $base)->count(),
            'tersedia' => (clone $base)->where('status', 'tersedia')->count(),
            'terjual' => (clone $base)->where('status', 'terjual')->count(),
        ];

        // Filter status hanya untuk daftar
        if ($request->filled('status')) {
            $base->where('status', $request->status);
        }

        $query = $base->with([
            'product:id,ulid,kode_produk,nama_produk',
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'intake:id,ulid,nomor_dokumen,tanggal',
        ]);

        $sortField = $request->input('sort_field', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc') === 'asc' ? 'asc' : 'desc';
        $sortable = ['kode_internal', 'serial_number', 'harga_modal', 'harga_jual', 'status', 'created_at', 'sold_at'];
        if (in_array($sortField, $sortable, true)) {
            $query->orderBy($sortField, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        $perPage = $this->getPerPage($request, 15);
        $items = $query->paginate($perPage);

        // Cost (modal + HPP landed) = sensitif → hanya untuk yang berizin lihat HPP.
        if (!auth()->user()->can('stok.view_hpp')) {
            $items->getCollection()->each->makeHidden(['harga_modal', 'cost_per_unit']);
        }

        return $this->success([
            'items' => $items->items(),
            'summary' => $summary,
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
     * Unit serial TERSEDIA untuk dipilih di dokumen (Transfer/Adjustment-keluar/Retur Beli).
     * Param: product_id (ulid/id, wajib), warehouse_id (ulid/id, opsional = filter gudang sumber).
     * Mengembalikan field yang dibutuhkan SerialUnitPicker (SN, atribut, modal, cost_per_unit).
     */
    public function available(Request $request): JsonResponse
    {
        $allowed = collect(['pos.access', 'serial-intake.view', 'transfer.create', 'adjustment.create', 'retur-beli.create', 'opname.create'])
            ->contains(fn ($p) => auth()->user()->can($p));
        if (!$allowed) {
            return $this->forbidden();
        }

        $request->validate([
            'product_id' => 'required|string',
            'warehouse_id' => 'nullable|string',
        ]);

        $query = SerialUnit::query()
            ->where('status', SerialUnit::STATUS_TERSEDIA)
            ->with(['product:id,ulid,kode_produk,nama_produk', 'warehouse:id,ulid,nama_warehouse']);

        $pid = $request->product_id;
        $query->whereHas('product', fn ($q) => is_numeric($pid) ? $q->where('id', $pid) : $q->where('ulid', $pid));

        if ($request->filled('warehouse_id')) {
            $wid = $request->warehouse_id;
            $query->whereHas('warehouse', fn ($q) => is_numeric($wid) ? $q->where('id', $wid) : $q->where('ulid', $wid));
        }

        $units = $query->orderBy('serial_number')->get([
            'ulid', 'product_id', 'warehouse_id', 'serial_number', 'kode_internal',
            'harga_modal', 'cost_per_unit', 'harga_jual',
            'grade', 'battery_condition', 'battery_health', 'account_status', 'status',
        ]);

        // Cost (modal + HPP landed) = sensitif → hanya untuk yang berizin lihat HPP.
        // harga_jual tetap tampil (bukan rahasia).
        if (!auth()->user()->can('stok.view_hpp')) {
            $units->makeHidden(['harga_modal', 'cost_per_unit']);
        }

        return $this->success(['items' => $units]);
    }

    /**
     * Saran kode_internal berikutnya (KI-#######) untuk tombol Generate di form intake.
     * Ambil nomor tertinggi berpola KI-{digit} (withTrashed, sejajar UNIQUE index) lalu +1.
     * Hanya SARAN — keunikan final tetap dijamin UNIQUE index + validasi saat simpan.
     */
    public function peekKode(Request $request): JsonResponse
    {
        if (!auth()->user()->canAny(['serial-intake.create', 'serial-intake.update'])) {
            return $this->forbidden();
        }

        $prefix = SerialUnit::KODE_INTERNAL_PREFIX; // 'KI-'
        $highest = SerialUnit::withTrashed()
            ->where('kode_internal', 'like', $prefix . '%')
            ->pluck('kode_internal')
            ->reduce(function ($carry, $code) use ($prefix) {
                return preg_match('/^' . preg_quote($prefix, '/') . '(\d+)$/', (string) $code, $m)
                    ? max($carry, (int) $m[1])
                    : $carry;
            }, 0);

        return $this->success([
            'prefix' => $prefix,
            'pad' => 7,
            'highest' => $highest,
            'next' => $prefix . str_pad((string) ($highest + 1), 7, '0', STR_PAD_LEFT),
        ]);
    }

    /**
     * Scan pintar 1 unit (POS / form retur): param `code` (boleh nomor seri ATAU kode internal).
     *
     * Karena SN TIDAK unik (boleh kembar), identitas pasti = kode_internal:
     *   1) cocokkan kode_internal (UNIK) → langsung 1 unit;
     *   2) kalau tidak ada, fallback nomor seri → kalau >1 unit sellable di gudang ini = AMBIGU
     *      (balikkan daftar kandidat → frontend tampilkan picker untuk dipilih kasir).
     * HPP/modal hanya untuk yang berizin stok.view_hpp. `serial_number` diterima sbg alias `code`.
     */
    public function lookup(Request $request): JsonResponse
    {
        $allowed = collect(['pos.access', 'serial-intake.view'])
            ->contains(fn ($p) => auth()->user()->can($p));
        if (!$allowed) {
            return $this->forbidden();
        }

        $request->validate([
            'code' => 'required_without:serial_number|string|max:100',
            'serial_number' => 'required_without:code|string|max:100',
            'warehouse_id' => 'required|integer|exists:master_warehouse,id',
        ]);

        $code = trim((string) ($request->input('code') ?? $request->input('serial_number')));
        $warehouseId = (int) $request->warehouse_id;
        $canHpp = auth()->user()->can('stok.view_hpp');
        $with = [
            'product:id,ulid,kode_produk,nama_produk,is_serial',
            'warehouse:id,ulid,nama_warehouse',
        ];

        // 1) Kode internal (UNIK global) → identitas pasti.
        $byKode = SerialUnit::where('kode_internal', $code)->with($with)->get();
        if ($byKode->count() === 1) {
            return $this->success($this->buildLookupSingle($byKode->first(), $warehouseId, $canHpp, 'kode_internal'));
        }

        // 2) Fallback nomor seri (boleh kembar).
        $bySn = SerialUnit::where('serial_number', $code)->with($with)->get();
        if ($bySn->isEmpty()) {
            return $this->notFound("Kode \"{$code}\" tidak terdaftar (cek nomor seri / kode internal).");
        }

        // Kandidat yang benar-benar bisa dijual dari gudang ini
        $sellableHere = $bySn->filter(
            fn ($u) => $u->status === SerialUnit::STATUS_TERSEDIA && (int) $u->warehouse_id === $warehouseId
        )->values();

        // >1 kandidat sellable → ambigu: minta kasir pilih lewat picker
        if ($sellableHere->count() > 1) {
            return $this->success([
                'ambiguous' => true,
                'matched_by' => 'serial_number',
                'candidates' => $sellableHere->map(fn ($u) => $this->serialUnitSummary($u, true, null, $canHpp))->all(),
            ]);
        }

        // Tepat 1 sellable → langsung; 0 sellable → unit terbaik + alasan tak bisa jual
        $unit = $sellableHere->first()
            ?? $bySn->first(fn ($u) => (int) $u->warehouse_id === $warehouseId)
            ?? $bySn->first();

        return $this->success($this->buildLookupSingle($unit, $warehouseId, $canHpp, 'serial_number'));
    }

    /** Bentuk respons lookup unit tunggal (unit + sellable + reason + matched_by). */
    private function buildLookupSingle(SerialUnit $unit, int $warehouseId, bool $canHpp, string $matchedBy): array
    {
        $sellable = $unit->status === SerialUnit::STATUS_TERSEDIA && (int) $unit->warehouse_id === $warehouseId;
        $reason = null;
        if ($unit->status !== SerialUnit::STATUS_TERSEDIA) {
            $reason = "Unit berstatus '{$unit->status}', tidak bisa dijual.";
        } elseif ((int) $unit->warehouse_id !== $warehouseId) {
            $reason = 'Unit berada di gudang lain (' . ($unit->warehouse->nama_warehouse ?? '-') . ').';
        }

        return [
            'unit' => $this->serialUnitSummary($unit, $sellable, $reason, $canHpp),
            'sellable' => $sellable,
            'reason' => $reason,
            'matched_by' => $matchedBy,
        ];
    }

    /** Ringkasan satu unit serial untuk scan/picker (HPP/modal hanya bila berizin). */
    private function serialUnitSummary(SerialUnit $unit, bool $sellable, ?string $reason, bool $canHpp): array
    {
        $data = [
            'ulid' => $unit->ulid,
            'serial_number' => $unit->serial_number,
            'kode_internal' => $unit->kode_internal,
            'status' => $unit->status,
            'grade' => $unit->grade,
            'battery_condition' => $unit->battery_condition,
            'battery_health' => $unit->battery_health,
            'account_status' => $unit->account_status,
            'catatan' => $unit->catatan,
            'harga_jual' => $unit->harga_jual,
            // product.id WAJIB tampil — frontend pakai sebagai product_id baris keranjang
            // (tanpa ini addSerialUnit gagal diam: unit tak masuk keranjang).
            'product' => $unit->product?->makeVisible('id'),
            'warehouse' => $unit->warehouse,
            'sellable' => $sellable,
            'reason' => $reason,
        ];
        if ($canHpp) {
            $data['harga_modal'] = $unit->harga_modal;
            $data['cost_per_unit'] = $unit->cost_per_unit;
        }

        return $data;
    }

    /**
     * Export Excel register unit serial (hormati filter aktif).
     */
    public function export(Request $request)
    {
        if (!auth()->user()->can('serial-intake.view')) {
            return $this->forbidden();
        }

        $filename = 'register_unit_serial_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new SerialUnitExport(
            $request->input('search'),
            $request->input('product_id'),
            $request->input('warehouse_id'),
            $request->input('status'),
            auth()->user()->can('stok.view_hpp'), // cost (modal + HPP) hanya bila berizin
        ), $filename);
    }
}
