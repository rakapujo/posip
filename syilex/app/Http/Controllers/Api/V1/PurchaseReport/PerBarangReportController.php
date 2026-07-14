<?php

namespace App\Http\Controllers\Api\V1\PurchaseReport;

use App\Exports\PurchaseDiskonExport;
use App\Exports\PurchaseHargaTerakhirExport;
use App\Exports\PurchasePerBarangExport;
use App\Exports\PurchasePerDokumenExport;
use App\Exports\PurchasePerSupplierExport;
use App\Http\Controllers\Api\BaseApiController;
use App\Models\DocPurchaseOrder;
use App\Models\MasterBrand;
use App\Models\MasterKategori;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Services\PurchaseReportSource;
use App\Services\ReportHelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Laporan Pembelian Per Barang — aggregated by product.
 * Split dari PurchaseReportController (W3 refactor).
 */
class PerBarangReportController extends BaseApiController
{
    /**
     * Laporan Pembelian Per Barang — aggregated by product.
     * Respects po.view_harga for financial fields.
     */
    public function perBarang(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.pembelian')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());
        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);
        $canViewHarga = auth()->user()->can('po.view_harga');
        $source = ReportHelperService::resolveSource($request);

        // Base query: aggregate baris pembelian (PO detail + unit serial) per produk
        $query = DB::query()
            ->fromSub(PurchaseReportSource::lines($dateFrom, $dateToEnd, $source), 'dpod')
            ->join('master_produk as mp', 'mp.id', '=', 'dpod.product_id')
            ->leftJoin('master_brand as mb', 'mb.id', '=', 'mp.brand_id')
            ->leftJoin('master_kategori as mk', 'mk.id', '=', 'mp.kategori_id');

        // Filters
        if ($request->filled('supplier_id')) {
            $query->where('dpod.supplier_id', $request->supplier_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('dpod.warehouse_id', $request->warehouse_id);
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

        // Select columns (jumlah_po = jumlah dokumen distinct yang memuat produk ini)
        $selectColumns = [
            'mp.ulid',
            'mp.kode_produk',
            'mp.nama_produk',
            'mb.nama_brand as brand',
            'mk.nama_kategori as kategori',
            DB::raw('SUM(dpod.qty_in_base) as total_qty'),
            DB::raw('COUNT(DISTINCT dpod.nomor_dokumen) as jumlah_po'),
        ];

        if ($canViewHarga) {
            $selectColumns = array_merge($selectColumns, [
                DB::raw('COALESCE(SUM(dpod.harga_bruto), 0) as total_bruto'),
                DB::raw('COALESCE(SUM(dpod.total_diskon_item), 0) as total_diskon'),
                DB::raw('COALESCE(SUM(dpod.subtotal), 0) as total_subtotal'),
            ]);
        }

        $query->select($selectColumns)
            ->groupBy('mp.id', 'mp.ulid', 'mp.kode_produk', 'mp.nama_produk', 'mb.nama_brand', 'mk.nama_kategori');

        $sortableFields = ['kode_produk', 'nama_produk', 'total_qty', 'jumlah_po'];
        if ($canViewHarga) {
            $sortableFields = array_merge($sortableFields, ['total_bruto', 'total_diskon', 'total_subtotal']);
        }
        $defaultSort = $canViewHarga ? 'total_subtotal' : 'total_qty';
        ReportHelperService::applySortWhitelist($query, $request, $sortableFields, $defaultSort);
        $query->orderBy('mp.kode_produk', 'asc'); // tiebreaker

        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);

        // Summary
        $summaryQuery = DB::query()
            ->fromSub(PurchaseReportSource::lines($dateFrom, $dateToEnd, $source), 'dpod')
            ->join('master_produk as mp', 'mp.id', '=', 'dpod.product_id');

        if ($request->filled('supplier_id')) {
            $summaryQuery->where('dpod.supplier_id', $request->supplier_id);
        }
        if ($request->filled('warehouse_id')) {
            $summaryQuery->where('dpod.warehouse_id', $request->warehouse_id);
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

        $summarySelect = [
            DB::raw('COUNT(DISTINCT dpod.product_id) as total_produk'),
            DB::raw('COALESCE(SUM(dpod.qty_in_base), 0) as total_qty'),
        ];

        if ($canViewHarga) {
            $summarySelect = array_merge($summarySelect, [
                DB::raw('COALESCE(SUM(dpod.harga_bruto), 0) as total_bruto'),
                DB::raw('COALESCE(SUM(dpod.total_diskon_item), 0) as total_diskon'),
                DB::raw('COALESCE(SUM(dpod.subtotal), 0) as total_subtotal'),
            ]);
        }

        $summaryRaw = $summaryQuery->select($summarySelect)->first();

        $summary = [
            'total_produk' => $summaryRaw->total_produk ?? 0,
            'total_qty' => $summaryRaw->total_qty ?? 0,
        ];

        if ($canViewHarga) {
            $summary['total_bruto'] = round((float) ($summaryRaw->total_bruto ?? 0), 2);
            $summary['total_diskon'] = round((float) ($summaryRaw->total_diskon ?? 0), 2);
            $summary['total_subtotal'] = round((float) ($summaryRaw->total_subtotal ?? 0), 2);
        }

        return $this->success(
            ReportHelperService::buildPaginatedResponse($paginator, $summary, ['can_view_harga' => $canViewHarga, 'source' => $source])
        );
    }

    /**
     * Show individual PO lines for a specific product (detail drill-down).
     */
    public function showBarang(Request $request, string $productUlid): JsonResponse
    {
        if (!auth()->user()->can('laporan.pembelian')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());
        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);
        $canViewHarga = auth()->user()->can('po.view_harga');
        $source = ReportHelperService::resolveSource($request);

        // Find product
        $product = DB::table('master_produk as mp')
            ->leftJoin('master_brand as mb', 'mb.id', '=', 'mp.brand_id')
            ->leftJoin('master_kategori as mk', 'mk.id', '=', 'mp.kategori_id')
            ->where('mp.ulid', $productUlid)
            ->select('mp.id', 'mp.ulid', 'mp.kode_produk', 'mp.nama_produk', 'mb.nama_brand as brand', 'mk.nama_kategori as kategori')
            ->first();

        if (!$product) {
            return $this->notFound('Produk tidak ditemukan.');
        }

        // Select columns
        $selectColumns = [
            'dpod.sumber',
            'dpod.tanggal_po',
            'dpod.nomor_dokumen',
            'ms.nama_supplier',
            'mw.nama_warehouse',
            'dpod.unit_used',
            'dpod.qty_in_unit',
            'dpod.qty_in_base',
        ];

        if ($canViewHarga) {
            $selectColumns = array_merge($selectColumns, [
                'dpod.harga_per_unit',
                'dpod.harga_bruto',
                'dpod.total_diskon_item',
                'dpod.subtotal',
            ]);
        }

        $query = DB::query()
            ->fromSub(PurchaseReportSource::lines($dateFrom, $dateToEnd, $source), 'dpod')
            ->join('master_supplier as ms', 'ms.id', '=', 'dpod.supplier_id')
            ->join('master_warehouse as mw', 'mw.id', '=', 'dpod.warehouse_id')
            ->where('dpod.product_id', $product->id)
            ->select($selectColumns);

        if ($request->filled('supplier_id')) {
            $query->where('dpod.supplier_id', $request->supplier_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('dpod.warehouse_id', $request->warehouse_id);
        }

        ReportHelperService::applySortWhitelist(
            $query,
            $request,
            ['tanggal_po', 'nomor_dokumen', 'qty_in_base', 'subtotal'],
            'dpod.tanggal_po'
        );

        $perPage = $this->getPerPage($request);
        $details = $query->paginate($perPage);

        // Summary for this product
        $summaryQuery = DB::query()
            ->fromSub(PurchaseReportSource::lines($dateFrom, $dateToEnd, $source), 'dpod')
            ->where('dpod.product_id', $product->id);

        if ($request->filled('supplier_id')) {
            $summaryQuery->where('dpod.supplier_id', $request->supplier_id);
        }
        if ($request->filled('warehouse_id')) {
            $summaryQuery->where('dpod.warehouse_id', $request->warehouse_id);
        }

        $summarySelect = [
            DB::raw('COALESCE(SUM(dpod.qty_in_base), 0) as total_qty'),
            DB::raw('COUNT(DISTINCT dpod.nomor_dokumen) as jumlah_po'),
        ];

        if ($canViewHarga) {
            $summarySelect = array_merge($summarySelect, [
                DB::raw('COALESCE(SUM(dpod.harga_bruto), 0) as total_bruto'),
                DB::raw('COALESCE(SUM(dpod.total_diskon_item), 0) as total_diskon'),
                DB::raw('COALESCE(SUM(dpod.subtotal), 0) as total_subtotal'),
            ]);
        }

        $summaryRaw = $summaryQuery->select($summarySelect)->first();

        $summary = [
            'total_qty' => $summaryRaw->total_qty ?? 0,
            'jumlah_po' => $summaryRaw->jumlah_po ?? 0,
        ];

        if ($canViewHarga) {
            $summary['total_bruto'] = round((float) ($summaryRaw->total_bruto ?? 0), 2);
            $summary['total_diskon'] = round((float) ($summaryRaw->total_diskon ?? 0), 2);
            $summary['total_subtotal'] = round((float) ($summaryRaw->total_subtotal ?? 0), 2);
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
            'can_view_harga' => $canViewHarga,
        ]);
    }

    public function exportPerBarang(Request $request)
    {
        if (!auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_pembelian_per_barang_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new PurchasePerBarangExport(
            $request->date_from,
            $request->date_to,
            auth()->user()->can('po.view_harga'),
            $request->filled('supplier_id') ? (int) $request->supplier_id : null,
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->filled('brand_id') ? (int) $request->brand_id : null,
            $request->filled('kategori_id') ? (int) $request->kategori_id : null,
            $request->input('search'),
            ReportHelperService::resolveSource($request),
        ), $filename);
    }
}
