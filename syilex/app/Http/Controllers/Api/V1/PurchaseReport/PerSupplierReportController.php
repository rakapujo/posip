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
 * Laporan Pembelian Per Supplier — aggregated by supplier.
 * Split dari PurchaseReportController (W3 refactor).
 */
class PerSupplierReportController extends BaseApiController
{
    /**
     * Laporan Pembelian Per Supplier — aggregated by supplier.
     * Respects po.view_harga for financial fields.
     */
    public function perSupplier(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.pembelian')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());
        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);
        $canViewHarga = auth()->user()->can('po.view_harga');
        $source = ReportHelperService::resolveSource($request);

        // Base query: aggregate by supplier (PO + Pembelian Serial via PurchaseReportSource)
        $query = DB::query()
            ->fromSub(PurchaseReportSource::documents($dateFrom, $dateToEnd, $source), 'dpo')
            ->join('master_supplier as ms', 'ms.id', '=', 'dpo.supplier_id');

        // Filters
        if ($request->filled('warehouse_id')) {
            $query->where('dpo.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('ms.kode_supplier', 'like', "%{$search}%")
                  ->orWhere('ms.nama_supplier', 'like', "%{$search}%");
            });
        }

        // Select columns
        $selectColumns = [
            'ms.id as supplier_id',
            'ms.ulid',
            'ms.kode_supplier',
            'ms.nama_supplier',
            DB::raw('COUNT(*) as jumlah_po'),
        ];

        if ($canViewHarga) {
            $selectColumns = array_merge($selectColumns, [
                DB::raw('COALESCE(SUM(dpo.subtotal), 0) as total_subtotal'),
                DB::raw('COALESCE(SUM(dpo.total_diskon_header), 0) as total_diskon'),
                DB::raw('COALESCE(SUM(dpo.grand_total), 0) as total_grand_total'),
            ]);
        }

        $query->select($selectColumns)
            ->groupBy('ms.id', 'ms.ulid', 'ms.kode_supplier', 'ms.nama_supplier');

        $sortableFields = ['kode_supplier', 'nama_supplier', 'jumlah_po'];
        if ($canViewHarga) {
            $sortableFields = array_merge($sortableFields, ['total_subtotal', 'total_diskon', 'total_grand_total']);
        }
        $defaultSort = $canViewHarga ? 'total_grand_total' : 'jumlah_po';
        ReportHelperService::applySortWhitelist($query, $request, $sortableFields, $defaultSort);

        $query->orderBy('ms.kode_supplier', 'asc');

        $perPage = $this->getPerPage($request);
        $paginator = $query->paginate($perPage);

        // Summary
        $summaryQuery = DB::query()
            ->fromSub(PurchaseReportSource::documents($dateFrom, $dateToEnd, $source), 'dpo')
            ->join('master_supplier as ms', 'ms.id', '=', 'dpo.supplier_id');

        if ($request->filled('warehouse_id')) {
            $summaryQuery->where('dpo.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $summaryQuery->where(function ($q) use ($search) {
                $q->where('ms.kode_supplier', 'like', "%{$search}%")
                  ->orWhere('ms.nama_supplier', 'like', "%{$search}%");
            });
        }

        $summarySelect = [
            DB::raw('COUNT(DISTINCT dpo.supplier_id) as total_supplier'),
            DB::raw('COUNT(*) as total_po'),
        ];

        if ($canViewHarga) {
            $summarySelect = array_merge($summarySelect, [
                DB::raw('COALESCE(SUM(dpo.subtotal), 0) as total_subtotal'),
                DB::raw('COALESCE(SUM(dpo.total_diskon_header), 0) as total_diskon'),
                DB::raw('COALESCE(SUM(dpo.grand_total), 0) as total_grand_total'),
            ]);
        }

        $summaryRaw = $summaryQuery->select($summarySelect)->first();

        $summary = [
            'total_supplier' => $summaryRaw->total_supplier ?? 0,
            'total_po' => $summaryRaw->total_po ?? 0,
        ];

        if ($canViewHarga) {
            $summary['total_subtotal'] = round((float) ($summaryRaw->total_subtotal ?? 0), 2);
            $summary['total_diskon'] = round((float) ($summaryRaw->total_diskon ?? 0), 2);
            $summary['total_grand_total'] = round((float) ($summaryRaw->total_grand_total ?? 0), 2);
        }

        return $this->success(
            ReportHelperService::buildPaginatedResponse($paginator, $summary, ['can_view_harga' => $canViewHarga, 'source' => $source])
        );
    }

    /**
     * Show individual POs for a specific supplier (detail drill-down).
     */
    public function showSupplier(Request $request, int $supplierId): JsonResponse
    {
        if (!auth()->user()->can('laporan.pembelian')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());
        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);
        $canViewHarga = auth()->user()->can('po.view_harga');
        $source = ReportHelperService::resolveSource($request);

        // Find supplier
        $supplier = DB::table('master_supplier')
            ->where('id', $supplierId)
            ->select('id', 'ulid', 'kode_supplier', 'nama_supplier')
            ->first();

        if (!$supplier) {
            return $this->notFound('Supplier tidak ditemukan.');
        }

        // Select columns
        $selectColumns = [
            'dpo.ulid',
            'dpo.sumber',
            'dpo.tanggal_po',
            'dpo.nomor_dokumen',
            'mw.nama_warehouse',
            'dpo.tempo_hari',
            'dpo.details_count',
        ];

        if ($canViewHarga) {
            $selectColumns = array_merge($selectColumns, [
                'dpo.subtotal',
                'dpo.total_diskon_header',
                'dpo.grand_total',
            ]);
        }

        $query = DB::query()
            ->fromSub(PurchaseReportSource::documents($dateFrom, $dateToEnd, $source), 'dpo')
            ->join('master_warehouse as mw', 'mw.id', '=', 'dpo.warehouse_id')
            ->where('dpo.supplier_id', $supplierId)
            ->select($selectColumns);

        if ($request->filled('warehouse_id')) {
            $query->where('dpo.warehouse_id', $request->warehouse_id);
        }

        ReportHelperService::applySortWhitelist(
            $query,
            $request,
            ['tanggal_po', 'nomor_dokumen', 'grand_total'],
            'tanggal_po'
        );

        $details = $query->paginate($this->getPerPage($request));

        // Summary for this supplier
        $summaryQuery = DB::query()
            ->fromSub(PurchaseReportSource::documents($dateFrom, $dateToEnd, $source), 'dpo')
            ->where('dpo.supplier_id', $supplierId);

        if ($request->filled('warehouse_id')) {
            $summaryQuery->where('dpo.warehouse_id', $request->warehouse_id);
        }

        $summarySelect = [
            DB::raw('COUNT(*) as jumlah_po'),
        ];

        if ($canViewHarga) {
            $summarySelect = array_merge($summarySelect, [
                DB::raw('COALESCE(SUM(subtotal), 0) as total_subtotal'),
                DB::raw('COALESCE(SUM(total_diskon_header), 0) as total_diskon'),
                DB::raw('COALESCE(SUM(grand_total), 0) as total_grand_total'),
            ]);
        }

        $summaryRaw = $summaryQuery->select($summarySelect)->first();

        $summary = [
            'jumlah_po' => $summaryRaw->jumlah_po ?? 0,
        ];

        if ($canViewHarga) {
            $summary['total_subtotal'] = round((float) ($summaryRaw->total_subtotal ?? 0), 2);
            $summary['total_diskon'] = round((float) ($summaryRaw->total_diskon ?? 0), 2);
            $summary['total_grand_total'] = round((float) ($summaryRaw->total_grand_total ?? 0), 2);
        }

        return $this->success([
            'supplier' => $supplier,
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

    public function exportPerSupplier(Request $request)
    {
        if (!auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_pembelian_per_supplier_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new PurchasePerSupplierExport(
            $request->date_from,
            $request->date_to,
            auth()->user()->can('po.view_harga'),
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->input('search'),
            ReportHelperService::resolveSource($request),
        ), $filename);
    }
}
