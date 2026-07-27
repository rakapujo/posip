<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Metode Pembayaran Breakdown — distribusi revenue per metode pembayaran.
 *
 * Source: doc_sales_payments (line per sale+method) + master_metode_pembayaran.
 * Hanya doc_sales.status = 'completed' yang dihitung.
 *
 * Metric per metode:
 *  - Jumlah transaksi (distinct sales_id)
 *  - Total nominal (SUM nominal)
 *  - Total biaya tambahan (SUM biaya_tambahan)
 *  - % dari total revenue
 *
 * Permission: laporan.performa.
 */
class PaymentMethodReportController extends BaseApiController
{
    public function breakdown(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.performa')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'terminal_id' => 'nullable|integer',
            'source' => 'nullable|in:pos,manual,all',
        ]);

        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());
        $terminalId = $request->filled('terminal_id') ? (int) $request->terminal_id : null;
        // B3.5: default 'all' (dulu tidak difilter sama sekali) — pos|manual bisa dipilih FE.
        $source = in_array($request->input('source'), ['pos', 'manual'], true) ? $request->input('source') : null;

        $rows = DB::table('doc_sales_payments as p')
            ->join('doc_sales as s', 's.id', '=', 'p.sales_id')
            ->join('master_metode_pembayaran as m', 'm.id', '=', 'p.metode_pembayaran_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('s.terminal_id', $terminalId))
            ->when($source, fn ($q) => $q->where('s.source', $source))
            ->select(
                'm.id as metode_id',
                'm.kode_pembayaran',
                'm.nama_pembayaran',
                'm.metode',
                'm.jenis',
                DB::raw('COUNT(DISTINCT p.sales_id) as trx_count'),
                DB::raw('COALESCE(SUM(p.nominal), 0) as nominal_total'),
                DB::raw('COALESCE(SUM(p.biaya_tambahan), 0) as biaya_total')
            )
            ->groupBy('m.id', 'm.kode_pembayaran', 'm.nama_pembayaran', 'm.metode', 'm.jenis')
            ->orderByDesc(DB::raw('SUM(p.nominal)'))
            ->get();

        // Settle piutang (kas) — seri terpisah; skip saat filter terminal (BO tanpa terminal) atau source=pos (piutang selalu BO)
        if (! $terminalId && $source !== 'pos') {
            $piutangRows = DB::table('doc_pembayaran_piutang')
                ->where('status', 'completed')
                ->where('total_bayar_cash', '>', 0)
                ->whereBetween('tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
                ->select(
                    'metode_pembayaran',
                    DB::raw('COUNT(*) as trx_count'),
                    DB::raw('COALESCE(SUM(total_bayar_cash), 0) as nominal_total')
                )
                ->groupBy('metode_pembayaran')
                ->get();

            foreach ($piutangRows as $pr) {
                $isCash = ($pr->metode_pembayaran ?? 'cash') === 'cash';
                $rows->push((object) [
                    'metode_id' => null,
                    'kode_pembayaran' => $isCash ? 'PPI-CASH' : 'PPI-TRF',
                    'nama_pembayaran' => $isCash ? 'Bayar Piutang (Cash)' : 'Bayar Piutang (Transfer)',
                    'metode' => $isCash ? 'tunai' : 'non_tunai',
                    'jenis' => 'piutang',
                    'trx_count' => (int) $pr->trx_count,
                    'nominal_total' => (float) $pr->nominal_total,
                    'biaya_total' => 0,
                ]);
            }
        }

        $grandTotal = $rows->sum('nominal_total');

        $items = $rows->map(fn ($r) => [
            'metode_id' => $r->metode_id,
            'kode_pembayaran' => $r->kode_pembayaran,
            'nama_pembayaran' => $r->nama_pembayaran,
            'metode' => $r->metode,
            'jenis' => $r->jenis,
            'trx_count' => (int) $r->trx_count,
            'nominal_total' => (float) $r->nominal_total,
            'biaya_total' => (float) $r->biaya_total,
            'percent' => $grandTotal > 0
                ? round(((float) $r->nominal_total / $grandTotal) * 100, 2)
                : 0,
        ])->sortByDesc('nominal_total')->values();

        // Tunai vs non-tunai split (summary)
        $tunai = $items->where('metode', 'tunai');
        $nonTunai = $items->where('metode', 'non_tunai');

        $tunaiDiterima = (float) $tunai->sum('nominal_total');
        $kembalian = (float) DB::table('doc_sales as s')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from.' 00:00:00', $to.' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('s.terminal_id', $terminalId))
            ->when($source, fn ($q) => $q->where('s.source', $source))
            ->whereExists(function ($sub) {
                $sub->select(DB::raw(1))
                    ->from('doc_sales_payments as pay')
                    ->join('master_metode_pembayaran as m', 'm.id', '=', 'pay.metode_pembayaran_id')
                    ->whereColumn('pay.sales_id', 's.id')
                    ->where('m.metode', 'tunai');
            })
            ->sum('s.kembalian');

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'source' => $source ?? 'all',
            'grand_total' => (float) $grandTotal,
            'summary' => [
                'tunai_nominal' => $tunaiDiterima,
                'tunai_diterima' => $tunaiDiterima,
                'kembalian' => $kembalian,
                'tunai_net' => round($tunaiDiterima - $kembalian, 2),
                'tunai_trx' => (int) $tunai->sum('trx_count'),
                'non_tunai_nominal' => (float) $nonTunai->sum('nominal_total'),
                'non_tunai_trx' => (int) $nonTunai->sum('trx_count'),
                'biaya_total' => (float) $items->sum('biaya_total'),
            ],
            'items' => $items,
        ]);
    }
}
