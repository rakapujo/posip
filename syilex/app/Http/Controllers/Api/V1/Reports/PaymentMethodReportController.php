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
        ]);

        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());
        $terminalId = $request->filled('terminal_id') ? (int) $request->terminal_id : null;

        $rows = DB::table('doc_sales_payments as p')
            ->join('doc_sales as s', 's.id', '=', 'p.sales_id')
            ->join('master_metode_pembayaran as m', 'm.id', '=', 'p.metode_pembayaran_id')
            ->where('s.status', 'completed')
            ->whereBetween('s.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('s.terminal_id', $terminalId))
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
        ]);

        // Tunai vs non-tunai split (summary)
        $tunai = $items->where('metode', 'tunai');
        $nonTunai = $items->where('metode', 'non_tunai');

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'grand_total' => (float) $grandTotal,
            'summary' => [
                'tunai_nominal' => (float) $tunai->sum('nominal_total'),
                'tunai_trx' => (int) $tunai->sum('trx_count'),
                'non_tunai_nominal' => (float) $nonTunai->sum('nominal_total'),
                'non_tunai_trx' => (int) $nonTunai->sum('trx_count'),
                'biaya_total' => (float) $items->sum('biaya_total'),
            ],
            'items' => $items->values(),
        ]);
    }
}
