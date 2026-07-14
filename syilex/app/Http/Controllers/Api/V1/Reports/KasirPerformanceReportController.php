<?php

namespace App\Http\Controllers\Api\V1\Reports;

use App\Http\Controllers\Api\BaseApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Performance Kasir — agregasi per kasir (doc_sales.created_by) untuk periode filter.
 *
 * Metric:
 *  - Jumlah transaksi completed
 *  - Omzet total
 *  - Rata-rata per transaksi
 *  - Jumlah void
 *  - Jumlah retur (yang dibuat kasir ini)
 *  - Total diskon yang diberikan (diskon_nota_*_hasil + SUM diskon_total item)
 *  - Jumlah shift ditutup (normal vs paksa)
 *
 * Butuh permission: laporan.performa.
 */
class KasirPerformanceReportController extends BaseApiController
{
    public function index(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.performa')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate([
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date|after_or_equal:date_from',
            'terminal_id' => 'nullable|integer',
            'user_id' => 'nullable|integer',
            'sort' => 'nullable|in:omzet_desc,omzet_asc,trx_desc,void_desc,retur_desc',
        ]);

        $from = $request->input('date_from', now()->startOfMonth()->toDateString());
        $to = $request->input('date_to', now()->toDateString());
        $terminalId = $request->filled('terminal_id') ? (int) $request->terminal_id : null;
        $userId = $request->filled('user_id') ? (int) $request->user_id : null;

        // Sales per kasir
        $salesAgg = DB::table('doc_sales as s')
            ->join('users as u', 'u.id', '=', 's.created_by')
            ->whereBetween('s.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('s.terminal_id', $terminalId))
            ->when($userId, fn ($q) => $q->where('s.created_by', $userId))
            ->select(
                'u.id as user_id',
                'u.name as user_name',
                DB::raw("SUM(CASE WHEN s.status = 'completed' THEN 1 ELSE 0 END) as trx_completed"),
                DB::raw("SUM(CASE WHEN s.status = 'voided' THEN 1 ELSE 0 END) as trx_voided"),
                DB::raw("COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.grand_total ELSE 0 END), 0) as omzet"),
                DB::raw("COALESCE(SUM(CASE WHEN s.status = 'completed' THEN s.total_diskon ELSE 0 END), 0) as diskon_nota_total")
            )
            ->groupBy('u.id', 'u.name')
            ->get()
            ->keyBy('user_id');

        // Line discount aggregate (per sale → sum diskon_total di detail)
        $lineDisc = DB::table('doc_sales_detail as d')
            ->join('doc_sales as s', 's.id', '=', 'd.sales_id')
            ->whereBetween('s.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->where('s.status', 'completed')
            ->when($terminalId, fn ($q) => $q->where('s.terminal_id', $terminalId))
            ->when($userId, fn ($q) => $q->where('s.created_by', $userId))
            ->select(
                's.created_by as user_id',
                DB::raw('COALESCE(SUM(d.diskon_total), 0) as diskon_line_total')
            )
            ->groupBy('s.created_by')
            ->get()
            ->keyBy('user_id');

        // Retur yang diproses kasir (creator retur)
        $returAgg = DB::table('doc_sales_returns as r')
            ->whereBetween('r.tanggal', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('r.terminal_id', $terminalId))
            ->when($userId, fn ($q) => $q->where('r.created_by', $userId))
            ->select(
                'r.created_by as user_id',
                DB::raw('COUNT(*) as retur_count'),
                DB::raw('COALESCE(SUM(r.grand_total), 0) as retur_nominal')
            )
            ->groupBy('r.created_by')
            ->get()
            ->keyBy('user_id');

        // Shift per kasir: jumlah + breakdown normal vs paksa
        $shiftAgg = DB::table('pos_terminal_shifts as sh')
            ->whereBetween('sh.started_at', [$from . ' 00:00:00', $to . ' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('sh.terminal_id', $terminalId))
            ->when($userId, fn ($q) => $q->where('sh.user_id', $userId))
            ->select(
                'sh.user_id',
                DB::raw('COUNT(*) as shift_total'),
                DB::raw("SUM(CASE WHEN sh.ended_by_force = 1 THEN 1 ELSE 0 END) as shift_paksa"),
                DB::raw('COALESCE(SUM(sh.selisih), 0) as total_selisih')
            )
            ->groupBy('sh.user_id')
            ->get()
            ->keyBy('user_id');

        // Merge all
        $allUserIds = $salesAgg->keys()
            ->merge($returAgg->keys())
            ->merge($shiftAgg->keys())
            ->unique();

        $rows = $allUserIds->map(function ($userId) use ($salesAgg, $lineDisc, $returAgg, $shiftAgg) {
            $sale = $salesAgg->get($userId);
            $line = $lineDisc->get($userId);
            $retur = $returAgg->get($userId);
            $shift = $shiftAgg->get($userId);

            $trx = (int) ($sale->trx_completed ?? 0);
            $omzet = (float) ($sale->omzet ?? 0);

            return [
                'user_id' => (int) $userId,
                'user_name' => $sale->user_name ?? ($this->lookupUserName($userId) ?? '-'),
                'trx_completed' => $trx,
                'trx_voided' => (int) ($sale->trx_voided ?? 0),
                'omzet' => $omzet,
                'avg_per_trx' => $trx > 0 ? round($omzet / $trx, 2) : 0,
                'diskon_total' => (float) ($sale->diskon_nota_total ?? 0) + (float) ($line->diskon_line_total ?? 0),
                'retur_count' => (int) ($retur->retur_count ?? 0),
                'retur_nominal' => (float) ($retur->retur_nominal ?? 0),
                'shift_total' => (int) ($shift->shift_total ?? 0),
                'shift_paksa' => (int) ($shift->shift_paksa ?? 0),
                'shift_selisih' => (float) ($shift->total_selisih ?? 0),
            ];
        });

        // Sort
        $sort = $request->input('sort', 'omzet_desc');
        $rows = match ($sort) {
            'omzet_asc' => $rows->sortBy('omzet'),
            'trx_desc' => $rows->sortByDesc('trx_completed'),
            'void_desc' => $rows->sortByDesc('trx_voided'),
            'retur_desc' => $rows->sortByDesc('retur_count'),
            default => $rows->sortByDesc('omzet'),
        };

        return $this->success([
            'period' => ['from' => $from, 'to' => $to],
            'items' => $rows->values(),
        ]);
    }

    private function lookupUserName(int $userId): ?string
    {
        $name = DB::table('users')->where('id', $userId)->value('name');
        return $name ?: null;
    }
}
