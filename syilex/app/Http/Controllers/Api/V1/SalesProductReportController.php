<?php

namespace App\Http\Controllers\Api\V1;

use App\Exports\SalesPerBarangExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\MasterBrand;
use App\Models\MasterKategori;
use App\Models\MasterPosTerminal;
use App\Models\MasterWarehouse;
use App\Services\ReportHelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

class SalesProductReportController extends BaseApiController
{
    /**
     * List sales aggregated by product (paginated, with filters).
     * Only includes completed sales (excludes voided).
     */
    public function index(Request $request): JsonResponse
    {
        if (! auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $canViewHpp = auth()->user()->can('stok.view_hpp');

        $request->validate(ReportHelperService::dateRangeRules());

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);

        // Retur subquery: linked (by sales tanggal) + free (by return tanggal)
        $returAgg = DB::table('doc_sales_return_detail as dsrd')
            ->join('doc_sales_returns as dsr', 'dsr.id', '=', 'dsrd.return_id')
            ->leftJoin('doc_sales as ds2', 'ds2.id', '=', 'dsr.sales_id')
            ->whereIn('dsr.status', ['lock', 'approved'])
            ->where(function ($q) use ($dateFrom, $dateToEnd) {
                $q->where(function ($linked) use ($dateFrom, $dateToEnd) {
                    $linked->whereNotNull('dsr.sales_id')
                        ->where('ds2.status', 'completed')
                        ->where('ds2.tanggal', '>=', $dateFrom)
                        ->where('ds2.tanggal', '<=', $dateToEnd);
                })->orWhere(function ($free) use ($dateFrom, $dateToEnd) {
                    $free->whereNull('dsr.sales_id')
                        ->where('dsr.tanggal', '>=', $dateFrom)
                        ->where('dsr.tanggal', '<=', $dateToEnd);
                });
            });

        if ($request->filled('terminal_id')) {
            $returAgg->where('ds2.terminal_id', $request->terminal_id);
        }
        if ($request->filled('warehouse_id')) {
            $returAgg->where('dsr.warehouse_id', $request->warehouse_id);
        }

        $returAgg->select('dsrd.product_id', DB::raw('SUM(dsrd.qty_base) as qty_retur'))
            ->groupBy('dsrd.product_id');

        // Base query: aggregate doc_sales_detail by product_id
        $query = DB::table('doc_sales_detail as dsd')
            ->join('doc_sales as ds', 'ds.id', '=', 'dsd.sales_id')
            ->join('master_produk as mp', 'mp.id', '=', 'dsd.product_id')
            ->leftJoin('master_brand as mb', 'mb.id', '=', 'mp.brand_id')
            ->leftJoin('master_kategori as mk', 'mk.id', '=', 'mp.kategori_id')
            ->leftJoinSub($returAgg, 'retur_agg', function ($join) {
                $join->on('retur_agg.product_id', '=', 'mp.id');
            })
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $dateFrom)
            ->where('ds.tanggal', '<=', $dateToEnd);

        // Filters
        if ($request->filled('terminal_id')) {
            $query->where('ds.terminal_id', $request->terminal_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('ds.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('brand_id')) {
            $query->where('mp.brand_id', $request->brand_id);
        }
        if ($request->filled('kategori_id')) {
            $query->where('mp.kategori_id', $request->kategori_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('mp.kode_produk', 'like', "%{$search}%")
                    ->orWhere('mp.nama_produk', 'like', "%{$search}%");
            });
        }

        // Select columns — pendapatan uses nett formula (after nota discount allocation)
        $nett = ReportHelperService::salesLineNettExpr('dsd', 'ds');
        $selectColumns = [
            'mp.ulid',
            'mp.kode_produk',
            'mp.nama_produk',
            'mb.nama_brand as brand',
            'mk.nama_kategori as kategori',
            DB::raw('SUM(dsd.qty_base) as qty_terjual'),
            DB::raw("SUM({$nett}) as pendapatan"),
            DB::raw('COALESCE(MAX(retur_agg.qty_retur), 0) as qty_retur'),
        ];

        if ($canViewHpp) {
            $selectColumns[] = DB::raw('SUM(dsd.qty_base * dsd.hpp_at_time) as hpp_total');
        }

        $query->select($selectColumns)
            ->groupBy('mp.id', 'mp.ulid', 'mp.kode_produk', 'mp.nama_produk', 'mb.nama_brand', 'mk.nama_kategori');

        // Sort
        $sortField = $request->input('sort_field', 'pendapatan');
        $sortOrder = $request->input('sort_order', 'desc');
        $dir = $sortOrder === 'asc' ? 'asc' : 'desc';

        $sortableFields = ['kode_produk', 'nama_produk', 'qty_terjual', 'qty_retur', 'pendapatan'];
        if ($canViewHpp) {
            $sortableFields = array_merge($sortableFields, ['hpp_total', 'laba_kotor', 'margin_persen']);
        }

        if (in_array($sortField, $sortableFields)) {
            if ($sortField === 'laba_kotor') {
                $query->orderByRaw("(SUM({$nett}) - SUM(dsd.qty_base * dsd.hpp_at_time)) {$dir}");
            } elseif ($sortField === 'margin_persen') {
                $query->orderByRaw("CASE WHEN SUM({$nett}) > 0 THEN (SUM({$nett}) - SUM(dsd.qty_base * dsd.hpp_at_time)) / SUM({$nett}) * 100 ELSE 0 END {$dir}");
            } else {
                $query->orderBy($sortField, $dir);
            }
        } else {
            $query->orderBy('pendapatan', 'desc');
        }

        $query->orderBy('mp.kode_produk', 'asc');

        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);

        // Compute laba_kotor and margin_persen on items
        $items = collect($paginator->items())->map(function ($item) use ($canViewHpp) {
            $row = (array) $item;
            if ($canViewHpp) {
                $row['laba_kotor'] = round($row['pendapatan'] - ($row['hpp_total'] ?? 0), 2);
                $row['margin_persen'] = $row['pendapatan'] > 0
                    ? round(($row['laba_kotor'] / $row['pendapatan']) * 100, 2)
                    : 0;
            }

            return $row;
        });

        // Summary query (separate, without GROUP BY)
        $summaryQuery = DB::table('doc_sales_detail as dsd')
            ->join('doc_sales as ds', 'ds.id', '=', 'dsd.sales_id')
            ->join('master_produk as mp', 'mp.id', '=', 'dsd.product_id')
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $dateFrom)
            ->where('ds.tanggal', '<=', $dateToEnd);

        if ($request->filled('terminal_id')) {
            $summaryQuery->where('ds.terminal_id', $request->terminal_id);
        }
        if ($request->filled('warehouse_id')) {
            $summaryQuery->where('ds.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('brand_id')) {
            $summaryQuery->where('mp.brand_id', $request->brand_id);
        }
        if ($request->filled('kategori_id')) {
            $summaryQuery->where('mp.kategori_id', $request->kategori_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $summaryQuery->where(function ($q) use ($search) {
                $q->where('mp.kode_produk', 'like', "%{$search}%")
                    ->orWhere('mp.nama_produk', 'like', "%{$search}%");
            });
        }

        $hpp = ReportHelperService::salesLineHppExpr('dsd');
        $summarySelect = [
            DB::raw('COUNT(DISTINCT dsd.product_id) as total_produk'),
            DB::raw('COALESCE(SUM(dsd.qty_base), 0) as total_qty'),
            DB::raw("COALESCE(SUM({$nett}), 0) as total_pendapatan"),
        ];

        if ($canViewHpp) {
            $summarySelect[] = DB::raw("COALESCE(SUM({$hpp}), 0) as total_hpp");
        }

        $summaryRaw = $summaryQuery->select($summarySelect)->first();

        // Retur summary (T1): tanggal dokumen retur — selaras Gross Profit; terminal/WH on return
        $returRev = ReportHelperService::returnLineRevenueExpr('dsrd');
        $returHpp = ReportHelperService::returnLineHppExpr('dsrd');
        $returSummaryQuery = DB::table('doc_sales_return_detail as dsrd')
            ->join('doc_sales_returns as dsr', 'dsr.id', '=', 'dsrd.return_id')
            ->whereIn('dsr.status', ['lock', 'approved'])
            ->where('dsr.tanggal', '>=', $dateFrom)
            ->where('dsr.tanggal', '<=', $dateToEnd);

        if ($request->filled('terminal_id')) {
            $returSummaryQuery->where('dsr.terminal_id', $request->terminal_id);
        }
        if ($request->filled('warehouse_id')) {
            $returSummaryQuery->where('dsr.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('brand_id') || $request->filled('kategori_id') || $request->filled('search')) {
            $returSummaryQuery->join('master_produk as mp', 'mp.id', '=', 'dsrd.product_id');
            if ($request->filled('brand_id')) {
                $returSummaryQuery->where('mp.brand_id', $request->brand_id);
            }
            if ($request->filled('kategori_id')) {
                $returSummaryQuery->where('mp.kategori_id', $request->kategori_id);
            }
            if ($request->filled('search')) {
                $search = $request->search;
                $returSummaryQuery->where(function ($q) use ($search) {
                    $q->where('mp.kode_produk', 'like', "%{$search}%")
                        ->orWhere('mp.nama_produk', 'like', "%{$search}%");
                });
            }
        }

        $returRaw = $returSummaryQuery->select([
            DB::raw('COALESCE(SUM(COALESCE(dsrd.qty_base, dsrd.qty)), 0) as total_qty_retur'),
            DB::raw("COALESCE(SUM({$returRev}), 0) as total_pendapatan_retur"),
            DB::raw("COALESCE(SUM({$returHpp}), 0) as total_hpp_retur"),
        ])->first();

        $totalPendapatan = round($summaryRaw->total_pendapatan ?? 0, 2);
        $totalPendapatanRetur = round($returRaw->total_pendapatan_retur ?? 0, 2);
        $totalPendapatanNet = round($totalPendapatan - $totalPendapatanRetur, 2);

        $summary = [
            'total_produk' => $summaryRaw->total_produk ?? 0,
            'total_qty' => $summaryRaw->total_qty ?? 0,
            'total_qty_retur' => $returRaw->total_qty_retur ?? 0,
            'total_pendapatan' => $totalPendapatan,
            'total_pendapatan_retur' => $totalPendapatanRetur,
            'total_pendapatan_net' => $totalPendapatanNet,
        ];

        if ($canViewHpp) {
            $totalHppGross = round($summaryRaw->total_hpp ?? 0, 2);
            $totalHppRetur = round($returRaw->total_hpp_retur ?? 0, 2);
            $totalHppNet = round($totalHppGross - $totalHppRetur, 2);
            $totalLabaNet = round($totalPendapatanNet - $totalHppNet, 2);
            // Card HPP/Laba/margin = net (setelah retur); _net aliases for explicit consumers
            $summary['total_hpp'] = $totalHppNet;
            $summary['total_hpp_net'] = $totalHppNet;
            $summary['total_laba'] = $totalLabaNet;
            $summary['total_laba_net'] = $totalLabaNet;
            $summary['avg_margin'] = $totalPendapatanNet > 0
                ? round(($totalLabaNet / $totalPendapatanNet) * 100, 2)
                : 0;
        }

        return $this->success([
            'items' => $items,
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
            'summary' => $summary,
            'can_view_hpp' => $canViewHpp,
        ]);
    }

    /**
     * Show individual transactions for a specific product.
     */
    public function show(Request $request, string $productUlid): JsonResponse
    {
        if (! auth()->user()->can('laporan.penjualan')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $canViewHpp = auth()->user()->can('stok.view_hpp');

        $request->validate(ReportHelperService::dateRangeRules());

        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);

        // Find product
        $product = DB::table('master_produk as mp')
            ->leftJoin('master_brand as mb', 'mb.id', '=', 'mp.brand_id')
            ->leftJoin('master_kategori as mk', 'mk.id', '=', 'mp.kategori_id')
            ->where('mp.ulid', $productUlid)
            ->select('mp.id', 'mp.ulid', 'mp.kode_produk', 'mp.nama_produk', 'mb.nama_brand as brand', 'mk.nama_kategori as kategori')
            ->first();

        if (! $product) {
            return $this->notFound('Produk tidak ditemukan.');
        }

        // Nett pendapatan per line
        $nett = ReportHelperService::salesLineNettExpr('dsd', 'ds');

        // Query individual transactions
        $selectColumns = [
            'ds.tanggal',
            'ds.nomor_dokumen',
            'dsd.unit',
            'dsd.qty',
            'dsd.qty_base',
            'dsd.harga_satuan',
            'dsd.diskon_total',
            'dsd.jumlah as jumlah_bruto',
            DB::raw("({$nett}) as jumlah"),
        ];

        if ($canViewHpp) {
            $selectColumns[] = 'dsd.hpp_at_time';
            $selectColumns[] = DB::raw('(dsd.qty_base * dsd.hpp_at_time) as hpp_line');
            $selectColumns[] = DB::raw("(({$nett}) - (dsd.qty_base * dsd.hpp_at_time)) as laba");
        }

        $query = DB::table('doc_sales_detail as dsd')
            ->join('doc_sales as ds', 'ds.id', '=', 'dsd.sales_id')
            ->where('dsd.product_id', $product->id)
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $dateFrom)
            ->where('ds.tanggal', '<=', $dateToEnd)
            ->select($selectColumns);

        if ($request->filled('terminal_id')) {
            $query->where('ds.terminal_id', $request->terminal_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('ds.warehouse_id', $request->warehouse_id);
        }

        // Sort
        $sortField = $request->input('sort_field', 'tanggal');
        $sortOrder = $request->input('sort_order', 'desc');

        $sortableFields = ['tanggal', 'nomor_dokumen', 'qty', 'jumlah'];
        if (in_array($sortField, $sortableFields)) {
            $query->orderBy($sortField === 'tanggal' ? 'ds.tanggal' : ($sortField === 'nomor_dokumen' ? 'ds.nomor_dokumen' : "dsd.{$sortField}"), $sortOrder === 'asc' ? 'asc' : 'desc');
        } else {
            $query->orderBy('ds.tanggal', 'desc');
        }
        $query->orderBy('ds.created_at', 'desc');

        $perPage = $this->getPerPage($request);
        $details = $query->paginate($perPage);

        // Summary for this product
        $summaryQuery = DB::table('doc_sales_detail as dsd')
            ->join('doc_sales as ds', 'ds.id', '=', 'dsd.sales_id')
            ->where('dsd.product_id', $product->id)
            ->where('ds.status', 'completed')
            ->where('ds.tanggal', '>=', $dateFrom)
            ->where('ds.tanggal', '<=', $dateToEnd);

        if ($request->filled('terminal_id')) {
            $summaryQuery->where('ds.terminal_id', $request->terminal_id);
        }
        if ($request->filled('warehouse_id')) {
            $summaryQuery->where('ds.warehouse_id', $request->warehouse_id);
        }

        $summarySelect = [
            DB::raw('COALESCE(SUM(dsd.qty_base), 0) as total_qty'),
            DB::raw("COALESCE(SUM({$nett}), 0) as total_pendapatan"),
        ];

        if ($canViewHpp) {
            $summarySelect[] = DB::raw('COALESCE(SUM(dsd.qty_base * dsd.hpp_at_time), 0) as total_hpp');
        }

        $summaryRaw = $summaryQuery->select($summarySelect)->first();

        $summary = [
            'total_qty' => $summaryRaw->total_qty ?? 0,
            'total_pendapatan' => round($summaryRaw->total_pendapatan ?? 0, 2),
        ];

        if ($canViewHpp) {
            $totalHpp = $summaryRaw->total_hpp ?? 0;
            $summary['total_hpp'] = $totalHpp;
            $summary['total_laba'] = round($summary['total_pendapatan'] - $totalHpp, 2);
        }

        return $this->success([
            'product' => $product,
            'details' => $details->items(),
            'pagination' => [
                'current_page' => $details->currentPage(),
                'last_page' => $details->lastPage(),
                'per_page' => $details->perPage(),
                'total' => $details->total(),
            ],
            'summary' => $summary,
            'can_view_hpp' => $canViewHpp,
        ]);
    }

    /**
     * Export sales per barang to Excel.
     */
    public function export(Request $request)
    {
        if (! auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_penjualan_per_barang_'.date('Y-m-d_His').'.xlsx';

        return Excel::download(new SalesPerBarangExport(
            $request->date_from,
            $request->date_to,
            auth()->user()->can('stok.view_hpp'),
            $request->filled('terminal_id') ? (int) $request->terminal_id : null,
            $request->filled('brand_id') ? (int) $request->brand_id : null,
            $request->filled('kategori_id') ? (int) $request->kategori_id : null,
            $request->input('search'),
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
        ), $filename);
    }

    /**
     * Get dropdown data for filters.
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

        $brands = MasterBrand::select('id', 'nama_brand')
            ->active()
            ->orderBy('nama_brand')
            ->get()
            ->makeVisible('id');

        $kategoris = MasterKategori::select('id', 'nama_kategori')
            ->active()
            ->orderBy('nama_kategori')
            ->get()
            ->makeVisible('id');

        $warehouses = MasterWarehouse::select('id', 'kode_warehouse', 'nama_warehouse')
            ->whereIn('id', DB::table('doc_sales')->distinct()->pluck('warehouse_id'))
            ->orderBy('nama_warehouse')
            ->get()
            ->makeVisible('id');

        return $this->success([
            'terminals' => $terminals,
            'brands' => $brands,
            'kategoris' => $kategoris,
            'warehouses' => $warehouses,
        ]);
    }
}
