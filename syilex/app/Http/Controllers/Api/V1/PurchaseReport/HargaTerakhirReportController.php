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
 * Laporan Harga Terakhir Pembelian — latest price per product.
 * Split dari PurchaseReportController (W3 refactor).
 */
class HargaTerakhirReportController extends BaseApiController
{
    /**
     * Laporan Harga Terakhir — latest purchase price per product.
     * Uses correlated subquery to find the most recent PO detail per product.
     * Respects po.view_harga for financial fields.
     */
    public function hargaTerakhir(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.pembelian')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());
        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);
        $canViewHarga = auth()->user()->can('po.view_harga');
        $source = ReportHelperService::resolveSource($request);

        // Baris pembelian TERBARU per produk (PO detail + unit serial) via ROW_NUMBER.
        // Filter supplier/gudang diterapkan SEBELUM ranking agar "terakhir" relatif ke filter.
        $ranked = DB::query()
            ->fromSub(PurchaseReportSource::lines($dateFrom, $dateToEnd, $source), 'l');
        if ($request->filled('supplier_id')) {
            $ranked->where('l.supplier_id', $request->supplier_id);
        }
        if ($request->filled('warehouse_id')) {
            $ranked->where('l.warehouse_id', $request->warehouse_id);
        }
        $ranked->select('l.*', DB::raw(
            'ROW_NUMBER() OVER (PARTITION BY l.product_id ORDER BY l.tanggal_po DESC, l.line_seq DESC) as rn'
        ));

        // Select columns
        $selectColumns = [
            'mp.ulid',
            'mp.kode_produk',
            'mp.nama_produk',
            'mb.nama_brand as brand',
            'mk.nama_kategori as kategori',
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
                'dpod.cost_per_unit',
            ]);
        }

        $query = DB::query()
            ->fromSub($ranked, 'dpod')
            ->join('master_produk as mp', 'mp.id', '=', 'dpod.product_id')
            ->leftJoin('master_brand as mb', 'mb.id', '=', 'mp.brand_id')
            ->leftJoin('master_kategori as mk', 'mk.id', '=', 'mp.kategori_id')
            ->join('master_supplier as ms', 'ms.id', '=', 'dpod.supplier_id')
            ->join('master_warehouse as mw', 'mw.id', '=', 'dpod.warehouse_id')
            ->where('dpod.rn', 1)
            ->select($selectColumns);

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

        $sortableFields = ['kode_produk', 'nama_produk', 'tanggal_po'];
        if ($canViewHarga) {
            $sortableFields = array_merge($sortableFields, ['harga_per_unit', 'cost_per_unit']);
        }
        ReportHelperService::applySortWhitelist($query, $request, $sortableFields, 'mp.kode_produk', 'asc');

        $paginator = $query->paginate($this->getPerPage($request));

        // Summary — total_produk is simply the paginator total
        $summary = [
            'total_produk' => $paginator->total(),
        ];

        return $this->success(
            ReportHelperService::buildPaginatedResponse($paginator, $summary, ['can_view_harga' => $canViewHarga, 'source' => $source])
        );
    }

    // ========================
    // Export Excel Methods
    // ========================

    public function exportHargaTerakhir(Request $request)
    {
        if (!auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_harga_terakhir_pembelian_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new PurchaseHargaTerakhirExport(
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
