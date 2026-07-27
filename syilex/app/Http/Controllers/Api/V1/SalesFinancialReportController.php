<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SalesBiayaExport;
use App\Exports\SalesDiscLineExport;
use App\Exports\SalesDiscNotaExport;
use App\Exports\SalesPembulatanExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\MasterPosTerminal;
use App\Services\ReportHelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class SalesFinancialReportController extends BaseApiController
{
    /**
     * Laporan Pembulatan — UNION doc_sales + doc_sales_returns, paginated.
     * Shows rounding amounts per transaction (sales and returns).
     */
    public function pembulatan(Request $request): JsonResponse
    {
        if (! auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);

        // Sales query
        $salesQuery = DB::table('doc_sales as ds')
            ->leftJoin('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $dateFrom)
            ->where('ds.tanggal', '<=', $dateToEnd)
            ->select(
                'ds.tanggal',
                'ds.nomor_dokumen',
                DB::raw("'Penjualan' as tipe"),
                'pt.nama_terminal',
                'ds.grand_total',
                'ds.pembulatan'
            );

        // Returns query (linked + free / whereNull sales_id)
        $returQuery = DB::table('doc_sales_returns as dsr')
            ->leftJoin('doc_sales as ds2', 'ds2.id', '=', 'dsr.sales_id')
            ->leftJoin('master_pos_terminal as pt2', 'pt2.id', '=', 'dsr.terminal_id')
            ->whereIn('dsr.status', ['lock', 'approved'])
            ->where(function ($q) {
                $q->whereNull('dsr.sales_id')
                    ->orWhere('ds2.status', 'completed');
            })
            ->where('dsr.tanggal', '>=', $dateFrom)
            ->where('dsr.tanggal', '<=', $dateToEnd)
            ->select(
                'dsr.tanggal',
                'dsr.nomor_dokumen',
                DB::raw("'Retur' as tipe"),
                'pt2.nama_terminal',
                'dsr.grand_total',
                'dsr.pembulatan'
            );

        // Apply filters
        if ($request->filled('terminal_id')) {
            $salesQuery->where('ds.terminal_id', $request->terminal_id);
            $returQuery->where('dsr.terminal_id', $request->terminal_id);
        }
        if (in_array($request->source, ['pos', 'manual'], true)) {
            $salesQuery->where('ds.source', $request->source);
            $returQuery->where('dsr.source', $request->source);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $salesQuery->where('ds.nomor_dokumen', 'like', "%{$search}%");
            $returQuery->where('dsr.nomor_dokumen', 'like', "%{$search}%");
        }
        if ($request->filled('tipe')) {
            if ($request->tipe === 'Penjualan') {
                $returQuery = null;
            } elseif ($request->tipe === 'Retur') {
                $salesQuery = null;
            }
        }

        // Build UNION
        if ($salesQuery && $returQuery) {
            $unionQuery = DB::query()->fromSub(
                $salesQuery->unionAll($returQuery),
                'combined'
            );
        } elseif ($salesQuery) {
            $unionQuery = DB::query()->fromSub($salesQuery, 'combined');
        } else {
            $unionQuery = DB::query()->fromSub($returQuery, 'combined');
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');
        $dir = $sortOrder === 'asc' ? 'asc' : 'desc';
        $sortableFields = ['tanggal', 'nomor_dokumen', 'tipe', 'grand_total', 'pembulatan'];

        if (in_array($sortField, $sortableFields)) {
            $unionQuery->orderBy($sortField, $dir);
        } else {
            $unionQuery->orderBy('tanggal', 'desc');
        }

        $perPage = $this->getPerPage($request);
        $paginator = $unionQuery->paginate($perPage);

        // Summary — separate queries for accuracy
        $salesSummaryQuery = DB::table('doc_sales')
            ->where('status', 'completed')
            ->where('tanggal', '>=', $dateFrom)
            ->where('tanggal', '<=', $dateToEnd);

        $returSummaryQuery = DB::table('doc_sales_returns as dsr')
            ->leftJoin('doc_sales as ds', 'ds.id', '=', 'dsr.sales_id')
            ->whereIn('dsr.status', ['lock', 'approved'])
            ->where(function ($q) {
                $q->whereNull('dsr.sales_id')
                    ->orWhere('ds.status', 'completed');
            })
            ->where('dsr.tanggal', '>=', $dateFrom)
            ->where('dsr.tanggal', '<=', $dateToEnd);

        if ($request->filled('terminal_id')) {
            $salesSummaryQuery->where('terminal_id', $request->terminal_id);
            $returSummaryQuery->where('dsr.terminal_id', $request->terminal_id);
        }
        if (in_array($request->source, ['pos', 'manual'], true)) {
            $salesSummaryQuery->where('source', $request->source);
            $returSummaryQuery->where('dsr.source', $request->source);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $salesSummaryQuery->where('nomor_dokumen', 'like', "%{$search}%");
            $returSummaryQuery->where('dsr.nomor_dokumen', 'like', "%{$search}%");
        }

        $salesSummary = $salesSummaryQuery->select(
            DB::raw('COUNT(*) as jumlah'),
            DB::raw('COALESCE(SUM(pembulatan), 0) as total_pembulatan')
        )->first();

        $returSummary = $returSummaryQuery->select(
            DB::raw('COUNT(*) as jumlah'),
            DB::raw('COALESCE(SUM(dsr.pembulatan), 0) as total_pembulatan')
        )->first();

        $totalPembulatanSales = (float) ($salesSummary->total_pembulatan ?? 0);
        $totalPembulatanRetur = (float) ($returSummary->total_pembulatan ?? 0);

        $summary = [
            'jumlah_transaksi' => ($salesSummary->jumlah ?? 0) + ($returSummary->jumlah ?? 0),
            'total_pembulatan_penjualan' => round($totalPembulatanSales, 2),
            'total_pembulatan_retur' => round($totalPembulatanRetur, 2),
            'net_pembulatan' => round($totalPembulatanSales - $totalPembulatanRetur, 2),
        ];

        return $this->success(ReportHelperService::buildPaginatedResponse($paginator, $summary));
    }

    /**
     * Laporan Disc Line — per-nota with total line-level discounts.
     * Only shows notes that have disc line > 0.
     */
    public function discLine(Request $request): JsonResponse
    {
        if (! auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), ReportHelperService::modeRules()));

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);
        $mode = ReportHelperService::resolveMode($request);
        $applyNet = $mode === 'net';

        $query = DB::table('doc_sales as ds')
            ->leftJoin('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
            ->join('doc_sales_detail as dsd', 'dsd.sales_id', '=', 'ds.id')
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $dateFrom)
            ->where('ds.tanggal', '<=', $dateToEnd)
            ->groupBy('ds.id', 'ds.ulid', 'ds.tanggal', 'ds.nomor_dokumen', 'pt.nama_terminal')
            ->havingRaw('SUM(dsd.diskon_total) > 0');

        // ACC-4 Wave B: LEFT JOIN retur per nota (pre-aggregated) so mode=net nets the row itself.
        // Value is constant per nota, MAX() avoids multiplying it across the joined dsd lines.
        if ($applyNet) {
            $query->leftJoinSub(
                ReportHelperService::salesDiscLineReturnByNotaSubquery($dateFrom, $dateToEnd, $this->salesReturnFilters($request)),
                'ret',
                fn ($join) => $join->on('ret.sales_id', '=', 'ds.id')
            );
        }

        $query->select([
            'ds.ulid',
            'ds.tanggal',
            'ds.nomor_dokumen',
            'pt.nama_terminal',
            DB::raw('COUNT(dsd.id) as jumlah_item'),
            DB::raw('SUM(dsd.qty * dsd.harga_satuan) as total_bruto'),
            $applyNet
                ? DB::raw('GREATEST(SUM(dsd.diskon_total) - COALESCE(MAX(ret.ret_disc), 0), 0) as total_disc_line')
                : DB::raw('SUM(dsd.diskon_total) as total_disc_line'),
            DB::raw('SUM(dsd.jumlah) as total_setelah_disc'),
            // ponytail: bruto/setelah stay bruto; only money-field disc_line nets (matches summary formula)
        ]);

        // Filters
        if ($request->filled('terminal_id')) {
            $query->where('ds.terminal_id', $request->terminal_id);
        }
        if (in_array($request->source, ['pos', 'manual'], true)) {
            $query->where('ds.source', $request->source);
        }
        if ($request->filled('search')) {
            $query->where('ds.nomor_dokumen', 'like', "%{$request->search}%");
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');
        $dir = $sortOrder === 'asc' ? 'asc' : 'desc';
        $sortableFields = ['tanggal', 'nomor_dokumen', 'total_bruto', 'total_disc_line', 'total_setelah_disc'];

        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $dir);
        } else {
            $query->orderBy('tanggal', 'desc');
        }

        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);

        // Summary
        $summaryQuery = DB::table('doc_sales as ds')
            ->join('doc_sales_detail as dsd', 'dsd.sales_id', '=', 'ds.id')
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $dateFrom)
            ->where('ds.tanggal', '<=', $dateToEnd);

        if ($request->filled('terminal_id')) {
            $summaryQuery->where('ds.terminal_id', $request->terminal_id);
        }
        if (in_array($request->source, ['pos', 'manual'], true)) {
            $summaryQuery->where('ds.source', $request->source);
        }
        if ($request->filled('search')) {
            $summaryQuery->where('ds.nomor_dokumen', 'like', "%{$request->search}%");
        }

        // Only count notes with disc line > 0
        $summaryRaw = DB::query()->fromSub(
            $summaryQuery->groupBy('ds.id')
                ->select(
                    DB::raw('SUM(dsd.qty * dsd.harga_satuan) as bruto'),
                    DB::raw('SUM(dsd.diskon_total) as disc'),
                    DB::raw('SUM(dsd.jumlah) as setelah')
                )
                ->havingRaw('SUM(dsd.diskon_total) > 0'),
            'per_nota'
        )->select(
            DB::raw('COUNT(*) as jumlah_nota'),
            DB::raw('COALESCE(SUM(bruto), 0) as total_bruto'),
            DB::raw('COALESCE(SUM(disc), 0) as total_disc_line'),
            DB::raw('COALESCE(SUM(setelah), 0) as total_setelah_disc')
        )->first();

        $summary = [
            'jumlah_nota' => $summaryRaw->jumlah_nota ?? 0,
            'total_bruto' => round((float) ($summaryRaw->total_bruto ?? 0), 2),
            'total_disc_line' => round((float) ($summaryRaw->total_disc_line ?? 0), 2),
            'total_setelah_disc' => round((float) ($summaryRaw->total_setelah_disc ?? 0), 2),
        ];

        if ($mode === 'net') {
            $retDisc = $this->netDiscLineReduction($request, $dateFrom, $dateToEnd);
            $summary['total_disc_line'] = round(max(0, $summary['total_disc_line'] - $retDisc), 2);
            // ponytail: bruto/setelah stay bruto; only money-field disc_line nets
        }

        return $this->success(ReportHelperService::buildPaginatedResponse($paginator, $summary, ['mode' => $mode]));
    }

    /**
     * Disc Line Detail — per-item discounts for a specific sales note.
     */
    public function discLineDetail(string $salesUlid): JsonResponse
    {
        if (! auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $sale = DB::table('doc_sales as ds')
            ->leftJoin('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
            ->where('ds.ulid', $salesUlid)
            ->where('ds.status', 'completed')
            ->select('ds.id', 'ds.nomor_dokumen', 'ds.tanggal', 'pt.nama_terminal as terminal')
            ->first();

        if (! $sale) {
            return $this->notFound('Nota penjualan tidak ditemukan.');
        }

        $items = DB::table('doc_sales_detail as dsd')
            ->join('master_produk as mp', 'mp.id', '=', 'dsd.product_id')
            ->where('dsd.sales_id', $sale->id)
            ->orderBy('dsd.id')
            ->select(
                'mp.kode_produk',
                'mp.nama_produk',
                'dsd.qty',
                'dsd.unit',
                'dsd.harga_satuan',
                DB::raw('(dsd.qty * dsd.harga_satuan) as bruto'),
                'dsd.diskon_1_tipe',
                'dsd.diskon_1_nilai',
                'dsd.diskon_1_hasil',
                'dsd.diskon_2_tipe',
                'dsd.diskon_2_nilai',
                'dsd.diskon_2_hasil',
                'dsd.diskon_3_tipe',
                'dsd.diskon_3_nilai',
                'dsd.diskon_3_hasil',
                'dsd.diskon_4_tipe',
                'dsd.diskon_4_nilai',
                'dsd.diskon_4_hasil',
                'dsd.diskon_5_tipe',
                'dsd.diskon_5_nilai',
                'dsd.diskon_5_hasil',
                'dsd.diskon_total',
                'dsd.jumlah'
            )
            ->get();

        $summaryRaw = DB::table('doc_sales_detail')
            ->where('sales_id', $sale->id)
            ->select(
                DB::raw('SUM(qty * harga_satuan) as total_bruto'),
                DB::raw('SUM(diskon_total) as total_disc'),
                DB::raw('SUM(jumlah) as total_jumlah')
            )
            ->first();

        return $this->success([
            'sale' => [
                'nomor_dokumen' => $sale->nomor_dokumen,
                'tanggal' => $sale->tanggal,
                'terminal' => $sale->terminal,
            ],
            'items' => $items,
            'summary' => [
                'total_bruto' => round((float) ($summaryRaw->total_bruto ?? 0), 2),
                'total_disc' => round((float) ($summaryRaw->total_disc ?? 0), 2),
                'total_jumlah' => round((float) ($summaryRaw->total_jumlah ?? 0), 2),
            ],
        ]);
    }

    /**
     * Laporan Disc Nota — per-nota with 3-level nota discounts.
     * Only shows notes that have total_diskon > 0.
     */
    public function discNota(Request $request): JsonResponse
    {
        if (! auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), ReportHelperService::modeRules()));

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);
        $mode = ReportHelperService::resolveMode($request);
        $applyNet = $mode === 'net';

        $query = DB::table('doc_sales as ds')
            ->leftJoin('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $dateFrom)
            ->where('ds.tanggal', '<=', $dateToEnd)
            ->where('ds.total_diskon', '>', 0);

        // ACC-4 Wave B: LEFT JOIN retur per nota so mode=net nets the row itself.
        if ($applyNet) {
            $query->leftJoinSub(
                ReportHelperService::salesDiscNotaReturnByNotaSubquery($dateFrom, $dateToEnd, $this->salesReturnFilters($request)),
                'ret',
                fn ($join) => $join->on('ret.sales_id', '=', 'ds.id')
            );
        }

        $query->select([
            'ds.tanggal',
            'ds.nomor_dokumen',
            'pt.nama_terminal',
            'ds.subtotal',
            'ds.diskon_nota_1_tipe',
            'ds.diskon_nota_1_nilai',
            'ds.diskon_nota_1_hasil',
            'ds.diskon_nota_1_label',
            'ds.diskon_nota_2_tipe',
            'ds.diskon_nota_2_nilai',
            'ds.diskon_nota_2_hasil',
            'ds.diskon_nota_2_label',
            'ds.diskon_nota_3_tipe',
            'ds.diskon_nota_3_nilai',
            'ds.diskon_nota_3_hasil',
            'ds.diskon_nota_3_label',
            $applyNet
                ? DB::raw('GREATEST(ds.total_diskon - COALESCE(ret.ret_disc, 0), 0) as total_diskon')
                : DB::raw('ds.total_diskon as total_diskon'),
            'ds.total_setelah_diskon',
            // ponytail: subtotal/total_setelah_diskon stay bruto; only total_diskon nets (matches summary formula)
        ]);

        // Filters
        if ($request->filled('terminal_id')) {
            $query->where('ds.terminal_id', $request->terminal_id);
        }
        if (in_array($request->source, ['pos', 'manual'], true)) {
            $query->where('ds.source', $request->source);
        }
        if ($request->filled('search')) {
            $query->where('ds.nomor_dokumen', 'like', "%{$request->search}%");
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');
        $dir = $sortOrder === 'asc' ? 'asc' : 'desc';
        $sortableFields = ['tanggal', 'nomor_dokumen', 'subtotal', 'total_diskon', 'total_setelah_diskon'];

        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField, $dir);
        } else {
            $query->orderBy('tanggal', 'desc');
        }

        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);

        // Summary
        $summaryQuery = DB::table('doc_sales')
            ->where('status', 'completed')
            ->where('tanggal', '>=', $dateFrom)
            ->where('tanggal', '<=', $dateToEnd)
            ->where('total_diskon', '>', 0);

        if ($request->filled('terminal_id')) {
            $summaryQuery->where('terminal_id', $request->terminal_id);
        }
        if (in_array($request->source, ['pos', 'manual'], true)) {
            $summaryQuery->where('source', $request->source);
        }
        if ($request->filled('search')) {
            $summaryQuery->where('nomor_dokumen', 'like', "%{$request->search}%");
        }

        $summaryRaw = $summaryQuery->select(
            DB::raw('COUNT(*) as jumlah_nota'),
            DB::raw('COALESCE(SUM(subtotal), 0) as total_subtotal'),
            DB::raw('COALESCE(SUM(total_diskon), 0) as total_diskon'),
            DB::raw('COALESCE(SUM(total_setelah_diskon), 0) as total_setelah_diskon')
        )->first();

        $summary = [
            'jumlah_nota' => $summaryRaw->jumlah_nota ?? 0,
            'total_subtotal' => round((float) ($summaryRaw->total_subtotal ?? 0), 2),
            'total_diskon' => round((float) ($summaryRaw->total_diskon ?? 0), 2),
            'total_setelah_diskon' => round((float) ($summaryRaw->total_setelah_diskon ?? 0), 2),
        ];

        if ($mode === 'net') {
            $retDisc = $this->netDiscNotaReduction($request, $dateFrom, $dateToEnd);
            $summary['total_diskon'] = round(max(0, $summary['total_diskon'] - $retDisc), 2);
        }

        return $this->success(ReportHelperService::buildPaginatedResponse($paginator, $summary, ['mode' => $mode]));
    }

    /**
     * Laporan Biaya — per-nota with biaya kirim and biaya lain.
     * Only shows notes that have total biaya > 0.
     */
    public function biaya(Request $request): JsonResponse
    {
        if (! auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), ReportHelperService::modeRules()));

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);
        $mode = ReportHelperService::resolveMode($request);
        $applyNet = $mode === 'net';

        $query = DB::table('doc_sales as ds')
            ->leftJoin('master_pos_terminal as pt', 'pt.id', '=', 'ds.terminal_id')
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $dateFrom)
            ->where('ds.tanggal', '<=', $dateToEnd)
            ->whereRaw('(COALESCE(ds.biaya_kirim_hasil, 0) + COALESCE(ds.biaya_lain_hasil, 0)) > 0');

        // ACC-4 Wave B: LEFT JOIN retur per nota so mode=net nets the row itself.
        if ($applyNet) {
            $query->leftJoinSub(
                ReportHelperService::salesBiayaReturnByNotaSubquery($dateFrom, $dateToEnd, $this->salesReturnFilters($request)),
                'ret',
                fn ($join) => $join->on('ret.sales_id', '=', 'ds.id')
            );
        }

        $query->select([
            'ds.tanggal',
            'ds.nomor_dokumen',
            'pt.nama_terminal',
            'ds.total_setelah_diskon',
            'ds.biaya_kirim_tipe',
            'ds.biaya_kirim_nilai',
            $applyNet
                ? DB::raw('GREATEST(COALESCE(ds.biaya_kirim_hasil, 0) - COALESCE(ret.ret_kirim, 0), 0) as biaya_kirim_hasil')
                : DB::raw('ds.biaya_kirim_hasil as biaya_kirim_hasil'),
            'ds.biaya_lain_tipe',
            'ds.biaya_lain_nilai',
            $applyNet
                ? DB::raw('GREATEST(COALESCE(ds.biaya_lain_hasil, 0) - COALESCE(ret.ret_lain, 0), 0) as biaya_lain_hasil')
                : DB::raw('ds.biaya_lain_hasil as biaya_lain_hasil'),
            $applyNet
                ? DB::raw('GREATEST(COALESCE(ds.biaya_kirim_hasil, 0) - COALESCE(ret.ret_kirim, 0), 0) + GREATEST(COALESCE(ds.biaya_lain_hasil, 0) - COALESCE(ret.ret_lain, 0), 0) as total_biaya')
                : DB::raw('(COALESCE(ds.biaya_kirim_hasil, 0) + COALESCE(ds.biaya_lain_hasil, 0)) as total_biaya'),
            'ds.dpp',
        ]);

        // Filters
        if ($request->filled('terminal_id')) {
            $query->where('ds.terminal_id', $request->terminal_id);
        }
        if (in_array($request->source, ['pos', 'manual'], true)) {
            $query->where('ds.source', $request->source);
        }
        if ($request->filled('search')) {
            $query->where('ds.nomor_dokumen', 'like', "%{$request->search}%");
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');
        $dir = $sortOrder === 'asc' ? 'asc' : 'desc';
        $sortableFields = ['tanggal', 'nomor_dokumen', 'total_setelah_diskon', 'biaya_kirim', 'biaya_lain', 'total_biaya', 'dpp'];

        if (in_array($sortField, $sortableFields)) {
            // Map frontend field names to select aliases (matches netted columns too when mode=net)
            $columnMap = [
                'biaya_kirim' => 'biaya_kirim_hasil',
                'biaya_lain' => 'biaya_lain_hasil',
            ];
            $query->orderBy($columnMap[$sortField] ?? $sortField, $dir);
        } else {
            $query->orderBy('tanggal', 'desc');
        }

        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);

        // Summary
        $summaryQuery = DB::table('doc_sales')
            ->where('status', 'completed')
            ->where('tanggal', '>=', $dateFrom)
            ->where('tanggal', '<=', $dateToEnd)
            ->whereRaw('(COALESCE(biaya_kirim_hasil, 0) + COALESCE(biaya_lain_hasil, 0)) > 0');

        if ($request->filled('terminal_id')) {
            $summaryQuery->where('terminal_id', $request->terminal_id);
        }
        if (in_array($request->source, ['pos', 'manual'], true)) {
            $summaryQuery->where('source', $request->source);
        }
        if ($request->filled('search')) {
            $summaryQuery->where('nomor_dokumen', 'like', "%{$request->search}%");
        }

        $summaryRaw = $summaryQuery->select(
            DB::raw('COUNT(*) as jumlah_nota'),
            DB::raw('COALESCE(SUM(biaya_kirim_hasil), 0) as total_biaya_kirim'),
            DB::raw('COALESCE(SUM(biaya_lain_hasil), 0) as total_biaya_lain'),
            DB::raw('COALESCE(SUM(COALESCE(biaya_kirim_hasil, 0) + COALESCE(biaya_lain_hasil, 0)), 0) as total_biaya')
        )->first();

        $summary = [
            'jumlah_nota' => $summaryRaw->jumlah_nota ?? 0,
            'total_biaya_kirim' => round((float) ($summaryRaw->total_biaya_kirim ?? 0), 2),
            'total_biaya_lain' => round((float) ($summaryRaw->total_biaya_lain ?? 0), 2),
            'total_biaya' => round((float) ($summaryRaw->total_biaya ?? 0), 2),
        ];

        if ($mode === 'net') {
            [$retKirim, $retLain] = $this->netBiayaReduction($request, $dateFrom, $dateToEnd);
            $summary['total_biaya_kirim'] = round(max(0, $summary['total_biaya_kirim'] - $retKirim), 2);
            $summary['total_biaya_lain'] = round(max(0, $summary['total_biaya_lain'] - $retLain), 2);
            $summary['total_biaya'] = round($summary['total_biaya_kirim'] + $summary['total_biaya_lain'], 2);
        }

        return $this->success(ReportHelperService::buildPaginatedResponse($paginator, $summary, ['mode' => $mode]));
    }

    // ========================
    // ACC-4 Net reductions — thin wrappers over ReportHelperService::sales*Return* (shared with exports)
    // ========================

    /** @return array{terminal_id: mixed, source: mixed, search: mixed} */
    private function salesReturnFilters(Request $request): array
    {
        return [
            'terminal_id' => $request->input('terminal_id'),
            'source' => $request->input('source'),
            'search' => $request->input('search'),
        ];
    }

    /** Linked returns: proportional line disc; free returns → 0 (no line disc on return detail). */
    private function netDiscLineReduction(Request $request, string $dateFrom, string $dateToEnd): float
    {
        return ReportHelperService::salesDiscLineReturnTotal($dateFrom, $dateToEnd, $this->salesReturnFilters($request));
    }

    /** Linked only: return.grand_total * (sale.total_diskon / sale.grand_total); free → 0. */
    private function netDiscNotaReduction(Request $request, string $dateFrom, string $dateToEnd): float
    {
        return ReportHelperService::salesDiscNotaReturnTotal($dateFrom, $dateToEnd, $this->salesReturnFilters($request));
    }

    /** @return array{0: float, 1: float} [kirim, lain] */
    private function netBiayaReduction(Request $request, string $dateFrom, string $dateToEnd): array
    {
        return ReportHelperService::salesBiayaReturnTotals($dateFrom, $dateToEnd, $this->salesReturnFilters($request));
    }

    // ========================
    // Export Excel Methods
    // ========================

    public function exportPembulatan(Request $request)
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_pembulatan_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new SalesPembulatanExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            $request->input('tipe'),
            $request->input('search'),
            $request->input('source'),
        ), $filename);
    }

    public function exportDiscLine(Request $request)
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), ReportHelperService::modeRules()));

        $filename = 'laporan_disc_line_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new SalesDiscLineExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            $request->input('search'),
            $request->input('source'),
            ReportHelperService::resolveMode($request),
        ), $filename);
    }

    public function exportDiscNota(Request $request)
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), ReportHelperService::modeRules()));

        $filename = 'laporan_disc_nota_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new SalesDiscNotaExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            $request->input('search'),
            $request->input('source'),
            ReportHelperService::resolveMode($request),
        ), $filename);
    }

    public function exportBiaya(Request $request)
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate(array_merge(ReportHelperService::dateRangeRules(), ReportHelperService::modeRules()));

        $filename = 'laporan_biaya_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new SalesBiayaExport(
            $request->date_from,
            $request->date_to,
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            $request->input('search'),
            $request->input('source'),
            ReportHelperService::resolveMode($request),
        ), $filename);
    }

    /**
     * Shared dropdown data for all financial report filters.
     */
    public function dropdowns(): JsonResponse
    {
        if (! auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        $terminals = MasterPosTerminal::select('id', 'kode_terminal', 'nama_terminal')
            ->whereHas('shifts.sales')
            ->orderBy('kode_terminal')
            ->get()
            ->makeVisible('id');

        return $this->success([
            'terminals' => $terminals,
        ]);
    }
}
