<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Sales\ProcessSalesReturnAction;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocSales;
use App\Models\DocSalesReturn;
use App\Models\MasterPosTerminal;
use App\Models\PosTerminalShift;
use App\Services\InventoryMasterRules;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SalesReturnController extends BaseApiController
{
    /**
     * Search sales for return (current session or previous sessions).
     */
    public function searchSales(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.retur')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'shift_id' => 'required|integer',
            'terminal_id' => 'required|integer',
            'session_type' => 'required|in:current,previous',
            'search' => 'nullable|string',
            'include_voided' => 'nullable',
        ]);

        $query = DocSales::with([
            'customer:id,ulid,kode_customer,nama,jenis',
            'shift:id,terminal_id,user_id,started_at',
            'shift.user:id,name',
            'details.returnDetails',
            'returns',
        ])
        ->orderByDesc('tanggal');

        // Only filter completed if not including voided
        $includeVoided = filter_var($validated['include_voided'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if (!$includeVoided) {
            $query->completed();
        }

        if ($validated['session_type'] === 'current') {
            // Only sales from current shift
            $query->byShift($validated['shift_id']);
        } else {
            // Sales from previous shifts on this terminal (NOT current shift)
            $terminal = MasterPosTerminal::find($validated['terminal_id']);

            if (!$terminal) {
                return $this->error('Terminal tidak ditemukan', 404);
            }

            // Check durasi_retur setting
            if ($terminal->durasi_retur === 0) {
                return $this->error('Retur dari sesi sebelumnya tidak diizinkan untuk terminal ini', 422);
            }

            $query->byTerminal($validated['terminal_id'])
                  ->where('shift_id', '!=', $validated['shift_id']);

            // Apply durasi_retur filter (null = unlimited)
            if ($terminal->durasi_retur !== null && $terminal->durasi_retur > 0) {
                $cutoffDate = now()->subDays($terminal->durasi_retur);
                $query->where('tanggal', '>=', $cutoffDate);
            }
        }

        if (!empty($validated['search'])) {
            $query->search($validated['search']);
        }

        $sales = $query->limit(20)->get();

        // Add retur status and nominal for each sale
        $sales->each(function ($sale) {
            $totalQtyBase = $sale->details->sum('qty_base');
            $totalReturnedBase = $sale->details->sum(function ($detail) {
                return $detail->returnDetails->sum('qty_base');
            });

            // Calculate total nominal retur from returns
            $totalNominalRetur = $sale->returns->sum('grand_total');

            if ($totalReturnedBase == 0) {
                $sale->retur_status = 'none';
            } elseif ($totalReturnedBase >= $totalQtyBase) {
                $sale->retur_status = 'full';
            } else {
                $sale->retur_status = 'partial';
            }

            $sale->total_nominal_retur = $totalNominalRetur;

            // Unload relations to keep response clean
            $sale->unsetRelation('details');
            $sale->unsetRelation('returns');
        });

        return $this->success([
            'sales' => $sales,
        ]);
    }

    /**
     * Get sales detail with returnable quantities and prorated values.
     */
    public function salesDetail(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('pos.retur')) {
            return $this->error('Unauthorized', 403);
        }

        $sales = DocSales::with([
            'details.product:id,ulid,kode_produk,nama_produk,unit_1,is_serial',
            'details.returnDetails',
            'customer:id,ulid,kode_customer,nama,jenis',
        ])->where('ulid', $ulid)->first();

        if (!$sales) {
            return $this->error('Transaksi tidak ditemukan', 404);
        }

        if (!$sales->isCompleted()) {
            return $this->error('Transaksi sudah di-void, tidak dapat diretur', 422);
        }

        // Calculate returnable pool (excluding biaya_kirim, biaya_lain, and their tax)
        // Formula: pool = total_setelah_diskon × (1 + pajak_persen/100)
        $subtotal = (float) $sales->subtotal;
        $totalSetelahDiskon = (float) $sales->total_setelah_diskon;
        $pajakPersen = (float) $sales->pajak_persen;
        $pool = $totalSetelahDiskon * (1 + $pajakPersen / 100);

        // Calculate prorated values for each detail
        $details = $sales->details->map(function ($detail) use ($subtotal, $pool) {
            $totalReturnedBase = (float) $detail->returnDetails->sum('qty_base');
            $returnableBase = (float) $detail->qty_base - $totalReturnedBase;

            // Prorated calculation
            $lineJumlah = (float) $detail->jumlah;
            $proporsi = $subtotal > 0 ? $lineJumlah / $subtotal : 0;
            $totalPembelian = $proporsi * $pool;
            $qtyBase = (float) $detail->qty_base;
            $hargaPerBase = $qtyBase > 0 ? $totalPembelian / $qtyBase : 0;

            $detail->total_returned_base = $totalReturnedBase;
            $detail->returnable_base = $returnableBase;
            $detail->total_pembelian = round($totalPembelian, 2);
            $detail->harga_per_base = $hargaPerBase; // Keep full precision for accurate calculation
            $detail->makeVisible('id');

            // Make product id visible
            if ($detail->product) {
                $detail->product->makeVisible('id');
            }

            // Produk serial: unit yang MASIH terjual di baris ini (kandidat retur) untuk dipilih kasir
            if ($detail->product && $detail->product->is_serial) {
                $detail->returnable_units = \App\Models\SerialUnit::where('sale_detail_id', $detail->id)
                    ->where('status', \App\Models\SerialUnit::STATUS_TERJUAL)
                    ->orderBy('serial_number')
                    ->get(['ulid', 'kode_internal', 'serial_number', 'grade', 'battery_condition', 'battery_health', 'account_status']);
            }

            return $detail;
        });

        $sales->setRelation('details', $details);
        $sales->retur_pool = round($pool, 2);
        $sales->makeVisible('id');

        return $this->success([
            'sales' => $sales,
        ]);
    }

    /**
     * Process a sales return.
     */
    public function store(Request $request, ProcessSalesReturnAction $action): JsonResponse
    {
        if (!auth()->user()->can('pos.retur')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'sales_id' => 'required|exists:doc_sales,id',
            'terminal_id' => 'required|exists:master_pos_terminal,id',
            'shift_id' => 'required|exists:pos_terminal_shifts,id',
            'warehouse_id' => 'required|exists:master_warehouse,id',
            'refund_method' => 'required|in:cash', // Credit feature removed
            'notes' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.sales_detail_id' => 'required|exists:doc_sales_detail,id',
            'items.*.product_id' => 'required|exists:master_produk,id',
            'items.*.qty' => 'required|numeric|min:0.01', // Always in base unit
            'items.*.harga_per_base' => 'required|numeric|min:0', // Prorated price per base unit
            // Produk serial: ulid unit (SN) yang dikembalikan; jumlah = qty retur
            'items.*.serial_unit_ids' => 'nullable|array',
            'items.*.serial_unit_ids.*' => 'string',
        ]);

        // Verify shift is active
        $shift = PosTerminalShift::find($validated['shift_id']);
        if (!$shift || !$shift->isActive()) {
            return $this->error('Shift tidak aktif', 422);
        }

        if ($errors = InventoryMasterRules::salesReturnPayloadErrors($validated)) {
            return $this->validationError($errors, 'Validasi gagal');
        }

        $salesReturn = $action->execute($validated);

        return $this->success([
            'sales_return' => $salesReturn,
        ], 'Retur berhasil diproses', 201);
    }

    /**
     * List returns for current shift.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.retur')) {
            return $this->error('Unauthorized', 403);
        }

        $shiftId = $request->input('shift_id');
        if (!$shiftId) {
            return $this->error('shift_id is required', 422);
        }

        $returns = DocSalesReturn::with([
            'sales:id,ulid,nomor_dokumen',
            'customer:id,ulid,kode_customer,nama',
        ])
        ->byShift($shiftId)
        ->orderByDesc('tanggal')
        ->get();

        return $this->success([
            'returns' => $returns,
        ]);
    }
}
