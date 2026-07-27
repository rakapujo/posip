<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocSales;
use App\Models\DocSalesPayment;
use App\Models\DocSalesReturn;
use App\Models\MasterMetodePembayaran;
use App\Models\PosCashTransaction;
use App\Models\PosTerminalShift;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CashTransactionController extends BaseApiController
{
    /**
     * List all transactions for current shift.
     * Returns separate arrays for tunai and non_tunai.
     */
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $shiftId = $request->input('shift_id');
        if (!$shiftId) {
            return $this->error('shift_id is required', 422);
        }

        $shift = PosTerminalShift::find($shiftId);
        if (!$shift) {
            return $this->error('Shift tidak ditemukan', 404);
        }
        if ($denied = $this->authorizeShiftAccess($shift)) {
            return $denied;
        }

        // Get payment method IDs by type
        $tunaiMethodIds = MasterMetodePembayaran::where('metode', 'tunai')->pluck('id')->toArray();
        $nonTunaiMethods = MasterMetodePembayaran::where('metode', 'non_tunai')
            ->select('id', 'nama_pembayaran')
            ->get()
            ->keyBy('id');

        // ==================== TUNAI ====================
        $tunaiTx = collect();

        // 1. Manual cash transactions (setor_awal, kas_masuk, kas_keluar)
        $manualTx = PosCashTransaction::byShift($shiftId)
            ->orderBy('created_at')
            ->get();

        foreach ($manualTx as $tx) {
            $tunaiTx->push([
                'id' => 'manual_' . $tx->id,
                'tipe' => $tx->tipe,
                'nominal' => (float) $tx->nominal,
                'keterangan' => $tx->keterangan,
                'created_at' => $tx->created_at,
            ]);
        }

        // 2. Cash sales - NET amount (cash received - kembalian)
        if (!empty($tunaiMethodIds)) {
            $sales = DocSales::where('shift_id', $shiftId)
                ->where('status', 'completed')
                ->with(['payments' => function ($q) use ($tunaiMethodIds) {
                    $q->whereIn('metode_pembayaran_id', $tunaiMethodIds);
                }])
                ->orderBy('tanggal')
                ->get();

            foreach ($sales as $sale) {
                $cashReceived = $sale->payments->sum('nominal');
                if ($cashReceived > 0) {
                    // Net = cash received - kembalian (kembalian only applies to cash)
                    $netCash = (float) $cashReceived - (float) ($sale->kembalian ?? 0);
                    $tunaiTx->push([
                        'id' => 'sale_' . $sale->id,
                        'tipe' => 'penjualan',
                        'nominal' => $netCash,
                        'keterangan' => $sale->nomor_dokumen,
                        'created_at' => $sale->tanggal,
                    ]);
                }
            }
        }

        // Sort tunai by created_at
        $tunaiTx = $tunaiTx->sortBy('created_at')->values();

        // ==================== NON-TUNAI ====================
        $nonTunaiTx = collect();

        if ($nonTunaiMethods->isNotEmpty()) {
            $nonTunaiMethodIds = $nonTunaiMethods->keys()->toArray();

            $sales = DocSales::where('shift_id', $shiftId)
                ->where('status', 'completed')
                ->with(['payments' => function ($q) use ($nonTunaiMethodIds) {
                    $q->whereIn('metode_pembayaran_id', $nonTunaiMethodIds);
                }])
                ->orderBy('tanggal')
                ->get();

            foreach ($sales as $sale) {
                foreach ($sale->payments as $payment) {
                    $methodName = $nonTunaiMethods[$payment->metode_pembayaran_id]->nama_pembayaran ?? 'Non-Tunai';
                    $nonTunaiTx->push([
                        'id' => 'nontunai_' . $payment->id,
                        'metode' => $methodName,
                        'nominal' => (float) $payment->nominal,
                        'keterangan' => $sale->nomor_dokumen,
                        'created_at' => $sale->tanggal,
                    ]);
                }
            }
        }

        // Sort non-tunai by created_at
        $nonTunaiTx = $nonTunaiTx->sortBy('created_at')->values();

        return $this->success([
            'tunai' => $tunaiTx,
            'non_tunai' => $nonTunaiTx,
            'subtotal_tunai' => $tunaiTx->sum(fn ($t) => in_array($t['tipe'], ['kas_keluar', 'refund_retur'], true) ? -$t['nominal'] : $t['nominal']),
            'subtotal_non_tunai' => $nonTunaiTx->sum('nominal'),
        ]);
    }

    /**
     * Create a cash transaction.
     */
    public function store(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $validated = $request->validate([
            'terminal_id' => 'required|exists:master_pos_terminal,id',
            'shift_id' => 'required|exists:pos_terminal_shifts,id',
            'tipe' => 'required|in:setor_awal,kas_masuk,kas_keluar',
            'nominal' => 'required|numeric|min:0',
            'keterangan' => 'nullable|string',
        ]);

        // Nominal must be > 0 for kas_masuk and kas_keluar
        if (in_array($validated['tipe'], ['kas_masuk', 'kas_keluar']) && $validated['nominal'] <= 0) {
            return $this->error('Nominal harus lebih dari 0', 422);
        }

        // Verify shift is active and caller owns it
        $shift = PosTerminalShift::find($validated['shift_id']);
        if (!$shift || !$shift->isActive()) {
            return $this->error('Shift tidak aktif', 422);
        }
        if ($shift->user_id !== auth()->id()) {
            return $this->error('Anda tidak memiliki akses ke shift ini', 403);
        }
        if ((int) $validated['terminal_id'] !== (int) $shift->terminal_id) {
            return $this->error('Terminal tidak sesuai dengan shift aktif', 422);
        }
        if ($shift->isLocked()) {
            return $this->error('Shift sedang dikunci', 422);
        }

        // Keterangan wajib for kas_keluar
        if ($validated['tipe'] === 'kas_keluar' && empty($validated['keterangan'])) {
            return $this->error('Keterangan wajib diisi untuk kas keluar', 422);
        }

        return DB::transaction(function () use ($validated) {
            if ($validated['tipe'] === 'setor_awal') {
                $existing = PosCashTransaction::byShift($validated['shift_id'])
                    ->setorAwal()
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    if ((float) $existing->nominal > 0) {
                        return $this->error('Setor awal sudah dilakukan untuk shift ini', 422);
                    }
                    $existing->update([
                        'nominal' => $validated['nominal'],
                        'keterangan' => $validated['keterangan'] ?? $existing->keterangan,
                    ]);

                    return $this->success([
                        'transaction' => $existing->fresh(),
                    ], 'Setor awal berhasil diperbarui', 200);
                }
            }

            $validated['created_by'] = auth()->id();
            $transaction = PosCashTransaction::create($validated);

            return $this->success([
                'transaction' => $transaction,
            ], 'Transaksi kas berhasil dicatat', 201);
        });
    }

    /**
     * Get cash summary for current shift.
     */
    public function summary(Request $request): JsonResponse
    {
        if (!auth()->user()->can('pos.access')) {
            return $this->error('Unauthorized', 403);
        }

        $shiftId = $request->input('shift_id');
        if (!$shiftId) {
            return $this->error('shift_id is required', 422);
        }

        $shift = PosTerminalShift::find($shiftId);
        if (!$shift) {
            return $this->error('Shift tidak ditemukan', 404);
        }
        if ($denied = $this->authorizeShiftAccess($shift)) {
            return $denied;
        }

        // Cash transactions from pos_cash_transactions
        $transactions = PosCashTransaction::byShift($shiftId)->get();
        $setorAwal = (float) $transactions->where('tipe', 'setor_awal')->sum('nominal');
        $kasMasuk = (float) $transactions->where('tipe', 'kas_masuk')->sum('nominal');

        // Manual kas keluar vs auto-created refund entries (tipe berbeda sejak Wave B)
        $kasKeluarManual = (float) $transactions->where('tipe', 'kas_keluar')->sum('nominal');
        $refundTunai = (float) $transactions->where('tipe', 'refund_retur')->sum('nominal');

        // Get cash payment method IDs (metode = 'tunai')
        $tunaiMethodIds = MasterMetodePembayaran::where('metode', 'tunai')->pluck('id')->toArray();

        // Cash sales (penjualan tunai) - NET amount (cash received - kembalian)
        $penjualanTunai = 0.0;
        if (!empty($tunaiMethodIds)) {
            $sales = DocSales::where('shift_id', $shiftId)
                ->where('status', 'completed')
                ->with(['payments' => function ($q) use ($tunaiMethodIds) {
                    $q->whereIn('metode_pembayaran_id', $tunaiMethodIds);
                }])
                ->get();

            foreach ($sales as $sale) {
                $cashReceived = (float) $sale->payments->sum('nominal');
                $kembalian = (float) ($sale->kembalian ?? 0);
                $penjualanTunai += ($cashReceived - $kembalian);
            }
        }

        // Non-tunai total
        $nonTunaiMethodIds = MasterMetodePembayaran::where('metode', 'non_tunai')->pluck('id')->toArray();
        $penjualanNonTunai = 0.0;
        if (!empty($nonTunaiMethodIds)) {
            $penjualanNonTunai = (float) DocSalesPayment::whereHas('sales', function ($q) use ($shiftId) {
                $q->where('shift_id', $shiftId)->where('status', 'completed');
            })
                ->whereIn('metode_pembayaran_id', $nonTunaiMethodIds)
                ->sum('nominal');
        }

        // Calculate total saldo kas (only tunai affects physical cash)
        // Saldo = Setor Awal + Penjualan Tunai + Kas Masuk - Kas Keluar Manual - Refund Tunai
        $saldo = $setorAwal + $penjualanTunai + $kasMasuk - $kasKeluarManual - $refundTunai;

        return $this->success([
            'setor_awal' => $setorAwal,
            'penjualan_tunai' => $penjualanTunai,
            'kas_masuk' => $kasMasuk,
            'kas_keluar' => $kasKeluarManual,
            'refund_tunai' => $refundTunai,
            'saldo' => $saldo,
            'penjualan_non_tunai' => $penjualanNonTunai,
            'total_penjualan' => $penjualanTunai + $penjualanNonTunai,
            'has_setor_awal' => $transactions->where('tipe', 'setor_awal')->isNotEmpty(),
        ]);
    }

    /**
     * Read access: shift owner or supervisor with terminal.force-release.
     */
    private function authorizeShiftAccess(PosTerminalShift $shift): ?JsonResponse
    {
        if ($shift->user_id === auth()->id()) {
            return null;
        }
        if (auth()->user()->can('terminal.force-release')) {
            return null;
        }

        return $this->error('Anda tidak memiliki akses ke shift ini', 403);
    }
}
