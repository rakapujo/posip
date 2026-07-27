<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Arus Kas Harian — aggregasi cash flow cross-shift per tanggal.
 *
 * Komponen:
 *  - Setor awal (pos_cash_transactions.tipe = 'setor_awal')
 *  - Kas masuk (pos_cash_transactions.tipe = 'kas_masuk')
 *  - Kas keluar MANUAL (pos_cash_transactions.tipe = 'kas_keluar', keterangan bukan "Refund retur")
 *  - Refund retur (pos_cash_transactions.tipe = 'kas_keluar', keterangan diawali "Refund retur")
 *  - Penjualan tunai NET: SUM(doc_sales_payments.nominal) untuk metode=tunai
 *                         dikurangi SUM(doc_sales.kembalian) per transaksi tunai
 *  - Bayar piutang cash: SUM(doc_pembayaran_piutang.total_bayar_cash) status=completed
 *    (di-skip bila filter terminal_id — BO tidak terikat terminal)
 *
 * Net = setor_awal + kas_masuk + penjualan_tunai_net + bayar_piutang_cash - kas_keluar_manual - refund_tunai
 *
 * Butuh permission: laporan.keuangan.
 */
class CashFlowReportController extends BaseApiController
{
    public function summary(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $filters = $this->parseFilters($request);

        $cashTx = $this->cashTransactionTotals($from, $to, $filters);
        $sales = $this->salesCashTotals($from, $to, $filters);
        $piutangCash = $this->pembayaranPiutangCashTotal($from, $to, $filters);
        $nonTunai = $this->salesNonTunaiTotals($from, $to, $filters);

        $net = $cashTx['setor_awal']
             + $cashTx['kas_masuk']
             + $sales['penjualan_tunai_net']
             + $piutangCash
             - $cashTx['kas_keluar_manual']
             - $cashTx['refund_tunai'];

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'setor_awal' => $cashTx['setor_awal'],
            'kas_masuk' => $cashTx['kas_masuk'],
            'penjualan_tunai_net' => $sales['penjualan_tunai_net'],
            'penjualan_non_tunai' => $nonTunai['penjualan_non_tunai'],
            'non_tunai_by_metode' => $nonTunai['by_metode'],
            'bayar_piutang_cash' => $piutangCash,
            'kas_keluar_manual' => $cashTx['kas_keluar_manual'],
            'refund_tunai' => $cashTx['refund_tunai'],
            'net_cash_flow' => round($net, 2),
        ]);
    }

    public function daily(Request $request): JsonResponse
    {
        if ($denied = $this->authorize()) {
            return $denied;
        }

        [$from, $to] = $this->parsePeriod($request);
        $filters = $this->parseFilters($request);

        // Kas transactions per tanggal
        $cashTxQuery = DB::table('pos_cash_transactions as c')
            ->leftJoin('pos_terminal_shifts as s', 's.id', '=', 'c.shift_id')
            ->whereBetween('c.created_at', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($filters['terminal_id']) {
            $cashTxQuery->where('c.terminal_id', $filters['terminal_id']);
        }
        if ($filters['warehouse_id']) {
            $cashTxQuery->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('master_pos_terminal as mpt')
                    ->whereColumn('mpt.id', 'c.terminal_id')
                    ->where('mpt.warehouse_id', $filters['warehouse_id']);
            });
        }

        $cashRows = (clone $cashTxQuery)
            ->select(
                DB::raw('DATE(c.created_at) as tanggal'),
                DB::raw("SUM(CASE WHEN c.tipe = 'setor_awal' THEN c.nominal ELSE 0 END) as setor_awal"),
                DB::raw("SUM(CASE WHEN c.tipe = 'kas_masuk' THEN c.nominal ELSE 0 END) as kas_masuk"),
                DB::raw("SUM(CASE WHEN c.tipe = 'kas_keluar' THEN c.nominal ELSE 0 END) as kas_keluar_manual"),
                DB::raw("SUM(CASE WHEN c.tipe = 'refund_retur' THEN c.nominal ELSE 0 END) as refund_tunai")
            )
            ->groupBy(DB::raw('DATE(c.created_at)'))
            ->get()
            ->keyBy('tanggal');

        // Penjualan tunai per tanggal
        $salesQuery = DB::table('doc_sales as s')
            ->join('doc_sales_payments as pay', 'pay.sales_id', '=', 's.id')
            ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
            ->where('s.status', 'completed')
            ->where('m.metode', 'tunai')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($filters['terminal_id']) {
            $salesQuery->where('s.terminal_id', $filters['terminal_id']);
        }
        if ($filters['warehouse_id']) {
            $salesQuery->where('s.warehouse_id', $filters['warehouse_id']);
        }

        $salesRows = (clone $salesQuery)
            ->select(
                DB::raw('DATE(s.tanggal) as tanggal'),
                DB::raw('SUM(pay.nominal) as cash_received'),
                DB::raw('SUM(DISTINCT s.kembalian) as kembalian_total') // approx; refined below
            )
            ->groupBy(DB::raw('DATE(s.tanggal)'))
            ->get()
            ->keyBy('tanggal');

        // Kembalian per tanggal (hindari distinct trick above — query terpisah untuk akurasi)
        $kembalianRows = DB::table('doc_sales as s')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($filters['terminal_id'], fn ($q) => $q->where('s.terminal_id', $filters['terminal_id']))
            ->when($filters['warehouse_id'], fn ($q) => $q->where('s.warehouse_id', $filters['warehouse_id']))
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('doc_sales_payments as pay')
                    ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
                    ->whereColumn('pay.sales_id', 's.id')
                    ->where('m.metode', 'tunai');
            })
            ->select(
                DB::raw('DATE(s.tanggal) as tanggal'),
                DB::raw('SUM(s.kembalian) as kembalian_total')
            )
            ->groupBy(DB::raw('DATE(s.tanggal)'))
            ->get()
            ->keyBy('tanggal');

        $piutangRows = collect();
        if (! $filters['terminal_id'] && ! $filters['warehouse_id']) {
            $piutangRows = DB::table('doc_pembayaran_piutang')
                ->where('status', 'completed')
                ->where('total_bayar_cash', '>', 0)
                ->whereBetween('tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
                ->select(
                    DB::raw('DATE(tanggal) as tanggal'),
                    DB::raw('COALESCE(SUM(total_bayar_cash), 0) as bayar_piutang_cash')
                )
                ->groupBy(DB::raw('DATE(tanggal)'))
                ->get()
                ->keyBy('tanggal');
        }

        // Penjualan non-tunai per tanggal — B3.2: info only, TIDAK masuk net_cash_flow.
        $nonTunaiQuery = DB::table('doc_sales as s')
            ->join('doc_sales_payments as pay', 'pay.sales_id', '=', 's.id')
            ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
            ->where('s.status', 'completed')
            ->where('m.metode', 'non_tunai')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($filters['terminal_id']) {
            $nonTunaiQuery->where('s.terminal_id', $filters['terminal_id']);
        }
        if ($filters['warehouse_id']) {
            $nonTunaiQuery->where('s.warehouse_id', $filters['warehouse_id']);
        }
        $nonTunaiRows = $nonTunaiQuery
            ->select(
                DB::raw('DATE(s.tanggal) as tanggal'),
                DB::raw('SUM(pay.nominal) as nominal')
            )
            ->groupBy(DB::raw('DATE(s.tanggal)'))
            ->get()
            ->keyBy('tanggal');

        // Merge dates
        $allDates = collect($cashRows->keys())
            ->merge($salesRows->keys())
            ->merge($piutangRows->keys())
            ->merge($nonTunaiRows->keys())
            ->unique()
            ->sort();

        $rows = $allDates->map(function ($tanggal) use ($cashRows, $salesRows, $kembalianRows, $piutangRows, $nonTunaiRows) {
            $c = $cashRows->get($tanggal);
            $sv = $salesRows->get($tanggal);
            $kv = $kembalianRows->get($tanggal);
            $pv = $piutangRows->get($tanggal);
            $nt = $nonTunaiRows->get($tanggal);

            $setorAwal = (float) ($c->setor_awal ?? 0);
            $kasMasuk = (float) ($c->kas_masuk ?? 0);
            $kasKeluar = (float) ($c->kas_keluar_manual ?? 0);
            $refund = (float) ($c->refund_tunai ?? 0);
            $penjualanNet = (float) ($sv->cash_received ?? 0) - (float) ($kv->kembalian_total ?? 0);
            $bayarPiutang = (float) ($pv->bayar_piutang_cash ?? 0);
            $penjualanNonTunai = (float) ($nt->nominal ?? 0);
            $net = $setorAwal + $kasMasuk + $penjualanNet + $bayarPiutang - $kasKeluar - $refund;

            return [
                'tanggal' => $tanggal,
                'setor_awal' => round($setorAwal, 2),
                'kas_masuk' => round($kasMasuk, 2),
                'penjualan_tunai_net' => round($penjualanNet, 2),
                'penjualan_non_tunai' => round($penjualanNonTunai, 2),
                'bayar_piutang_cash' => round($bayarPiutang, 2),
                'kas_keluar_manual' => round($kasKeluar, 2),
                'refund_tunai' => round($refund, 2),
                'net_cash_flow' => round($net, 2),
            ];
        });

        return $this->success(['items' => $rows->values()]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    private function authorize(): ?JsonResponse
    {
        if (! auth()->user()->can('laporan.keuangan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        return null;
    }

    private function parsePeriod(Request $request): array
    {
        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());

        return [$from, $to];
    }

    private function parseFilters(Request $request): array
    {
        return [
            'terminal_id' => $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            'warehouse_id' => $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
        ];
    }

    private function cashTransactionTotals(string $from, string $to, array $filters): array
    {
        $q = DB::table('pos_cash_transactions as c')
            ->whereBetween('c.created_at', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($filters['terminal_id']) {
            $q->where('c.terminal_id', $filters['terminal_id']);
        }
        if ($filters['warehouse_id']) {
            $q->whereExists(function ($sub) use ($filters) {
                $sub->select(DB::raw(1))
                    ->from('master_pos_terminal as mpt')
                    ->whereColumn('mpt.id', 'c.terminal_id')
                    ->where('mpt.warehouse_id', $filters['warehouse_id']);
            });
        }

        $row = $q->select(
            DB::raw("COALESCE(SUM(CASE WHEN c.tipe = 'setor_awal' THEN c.nominal ELSE 0 END), 0) as setor_awal"),
            DB::raw("COALESCE(SUM(CASE WHEN c.tipe = 'kas_masuk' THEN c.nominal ELSE 0 END), 0) as kas_masuk"),
            DB::raw("COALESCE(SUM(CASE WHEN c.tipe = 'kas_keluar' THEN c.nominal ELSE 0 END), 0) as kas_keluar_manual"),
            DB::raw("COALESCE(SUM(CASE WHEN c.tipe = 'refund_retur' THEN c.nominal ELSE 0 END), 0) as refund_tunai")
        )->first();

        return [
            'setor_awal' => (float) $row->setor_awal,
            'kas_masuk' => (float) $row->kas_masuk,
            'kas_keluar_manual' => (float) $row->kas_keluar_manual,
            'refund_tunai' => (float) $row->refund_tunai,
        ];
    }

    private function salesCashTotals(string $from, string $to, array $filters): array
    {
        // Cash received (dari semua payment line yang metode=tunai)
        $qPay = DB::table('doc_sales as s')
            ->join('doc_sales_payments as pay', 'pay.sales_id', '=', 's.id')
            ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
            ->where('s.status', 'completed')
            ->where('m.metode', 'tunai')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59']);
        if ($filters['terminal_id']) {
            $qPay->where('s.terminal_id', $filters['terminal_id']);
        }
        if ($filters['warehouse_id']) {
            $qPay->where('s.warehouse_id', $filters['warehouse_id']);
        }
        $cashReceived = (float) $qPay->sum('pay.nominal');

        // Kembalian: hanya dari transaksi yang SETIDAKNYA ada 1 payment tunai (untuk konsistensi)
        $qKembali = DB::table('doc_sales as s')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($filters['terminal_id'], fn ($q) => $q->where('s.terminal_id', $filters['terminal_id']))
            ->when($filters['warehouse_id'], fn ($q) => $q->where('s.warehouse_id', $filters['warehouse_id']))
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('doc_sales_payments as pay')
                    ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
                    ->whereColumn('pay.sales_id', 's.id')
                    ->where('m.metode', 'tunai');
            });
        $kembalian = (float) $qKembali->sum('s.kembalian');

        return [
            'penjualan_tunai_net' => round($cashReceived - $kembalian, 2),
        ];
    }

    /** Informational only — excluded from net_cash_flow. */
    private function salesNonTunaiTotals(string $from, string $to, array $filters): array
    {
        $rows = DB::table('doc_sales as s')
            ->join('doc_sales_payments as pay', 'pay.sales_id', '=', 's.id')
            ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
            ->where('s.status', 'completed')
            ->where('m.metode', 'non_tunai')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($filters['terminal_id'], fn ($q) => $q->where('s.terminal_id', $filters['terminal_id']))
            ->when($filters['warehouse_id'], fn ($q) => $q->where('s.warehouse_id', $filters['warehouse_id']))
            ->select(
                'm.id as metode_id',
                'm.kode_pembayaran',
                'm.nama_pembayaran',
                DB::raw('COALESCE(SUM(pay.nominal), 0) as nominal_total')
            )
            ->groupBy('m.id', 'm.kode_pembayaran', 'm.nama_pembayaran')
            ->orderByDesc(DB::raw('SUM(pay.nominal)'))
            ->get();

        $byMetode = $rows->map(fn ($r) => [
            'metode_id' => $r->metode_id,
            'kode_pembayaran' => $r->kode_pembayaran,
            'nama_pembayaran' => $r->nama_pembayaran,
            'nominal_total' => (float) $r->nominal_total,
        ])->values()->all();

        return [
            'penjualan_non_tunai' => round((float) $rows->sum('nominal_total'), 2),
            'by_metode' => $byMetode,
        ];
    }

    private function pembayaranPiutangCashTotal(string $from, string $to, array $filters): float
    {
        // BO settle tidak punya terminal/warehouse — skip saat filter terminal/warehouse agar tidak double-count di laporan POS.
        if ($filters['terminal_id'] || $filters['warehouse_id']) {
            return 0.0;
        }

        return round((float) DB::table('doc_pembayaran_piutang')
            ->where('status', 'completed')
            ->whereBetween('tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->sum('total_bayar_cash'), 2);
    }
}
