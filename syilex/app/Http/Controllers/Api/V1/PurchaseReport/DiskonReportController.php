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
 * Laporan Diskon Pembelian — per-PO with 3-level header discounts.
 * Split dari PurchaseReportController (W3 refactor).
 */
class DiskonReportController extends BaseApiController
{
    /**
     * Laporan Diskon Pembelian — per-PO with 3-level header discounts.
     * Only shows POs that have total_diskon_header > 0.
     */
    public function diskon(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.pembelian')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }
        // Seluruh laporan ini adalah nilai diskon (uang) → wajib izin lihat harga.
        if (!auth()->user()->can('po.view_harga')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat nilai diskon pembelian.');
        }

        $request->validate(ReportHelperService::dateRangeRules());
        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);

        $source = ReportHelperService::resolveSource($request);

        $query = DB::query()
            ->fromSub(PurchaseReportSource::documents($dateFrom, $dateToEnd, $source), 'dpo')
            ->join('master_supplier as ms', 'ms.id', '=', 'dpo.supplier_id')
            ->where('dpo.total_diskon_header', '>', 0)
            ->select(
                'dpo.ulid',
                'dpo.sumber',
                'dpo.tanggal_po',
                'dpo.nomor_dokumen',
                'ms.nama_supplier',
                'dpo.subtotal',
                'dpo.diskon_1_tipe',
                'dpo.diskon_1_nilai',
                'dpo.diskon_1_hasil',
                'dpo.diskon_2_tipe',
                'dpo.diskon_2_nilai',
                'dpo.diskon_2_hasil',
                'dpo.diskon_3_tipe',
                'dpo.diskon_3_nilai',
                'dpo.diskon_3_hasil',
                'dpo.total_diskon_header',
                'dpo.total_setelah_diskon'
            );

        // Filters
        if ($request->filled('supplier_id')) {
            $query->where('dpo.supplier_id', $request->supplier_id);
        }
        if ($request->filled('search')) {
            $query->where('dpo.nomor_dokumen', 'like', "%{$request->search}%");
        }

        ReportHelperService::applySortWhitelist(
            $query,
            $request,
            ['tanggal_po', 'nomor_dokumen', 'subtotal', 'total_diskon_header', 'total_setelah_diskon'],
            'tanggal_po'
        );

        $paginator = $query->paginate($this->getPerPage($request));

        // Summary — apply same filters untuk agregat konsisten
        $summaryQuery = DB::query()
            ->fromSub(PurchaseReportSource::documents($dateFrom, $dateToEnd, $source), 'dpo')
            ->where('dpo.total_diskon_header', '>', 0);

        if ($request->filled('supplier_id')) {
            $summaryQuery->where('dpo.supplier_id', $request->supplier_id);
        }
        if ($request->filled('search')) {
            $summaryQuery->where('dpo.nomor_dokumen', 'like', "%{$request->search}%");
        }

        $summaryRaw = $summaryQuery->select(
            DB::raw('COUNT(*) as jumlah_po'),
            DB::raw('COALESCE(SUM(dpo.subtotal), 0) as total_subtotal'),
            DB::raw('COALESCE(SUM(dpo.total_diskon_header), 0) as total_diskon'),
            DB::raw('COALESCE(SUM(dpo.total_setelah_diskon), 0) as total_setelah_diskon')
        )->first();

        $summary = [
            'jumlah_po' => $summaryRaw->jumlah_po ?? 0,
            'total_subtotal' => round((float) ($summaryRaw->total_subtotal ?? 0), 2),
            'total_diskon' => round((float) ($summaryRaw->total_diskon ?? 0), 2),
            'total_setelah_diskon' => round((float) ($summaryRaw->total_setelah_diskon ?? 0), 2),
        ];

        return $this->success(ReportHelperService::buildPaginatedResponse($paginator, $summary, ['source' => $source]));
    }

    public function exportDiskon(Request $request)
    {
        if (!auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }
        // Seluruh laporan ini adalah nilai diskon (uang) → wajib izin lihat harga.
        if (!auth()->user()->can('po.view_harga')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat nilai diskon pembelian.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_diskon_pembelian_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new PurchaseDiskonExport(
            $request->date_from,
            $request->date_to,
            $request->filled('supplier_id') ? (int) $request->supplier_id : null,
            $request->input('search'),
            ReportHelperService::resolveSource($request),
        ), $filename);
    }
}
