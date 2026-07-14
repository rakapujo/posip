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
 * Laporan Pembelian Per Dokumen — paginated list of approved POs + detail.
 * Split dari PurchaseReportController (W3 refactor).
 */
class PerDokumenReportController extends BaseApiController
{
    /**
     * Laporan Pembelian Per Dokumen — paginated list of approved POs.
     * Respects po.view_harga for financial fields.
     */
    public function perDokumen(Request $request): JsonResponse
    {
        if (!auth()->user()->can('laporan.pembelian')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());
        [$dateFrom, $dateToEnd] = ReportHelperService::parseDateRange($request);
        $canViewHarga = auth()->user()->can('po.view_harga');
        $source = ReportHelperService::resolveSource($request);

        // Base select (sumber: po/serial — pembelian serial ikut via PurchaseReportSource)
        $select = [
            'dpo.ulid',
            'dpo.sumber',
            'dpo.tanggal_po',
            'dpo.nomor_dokumen',
            'ms.kode_supplier',
            'ms.nama_supplier',
            'mw.nama_warehouse',
            'dpo.tempo_hari',
            'dpo.tanggal_jatuh_tempo',
            'dpo.details_count',
        ];

        if ($canViewHarga) {
            $select = array_merge($select, [
                'dpo.subtotal',
                'dpo.total_diskon_header',
                'dpo.total_setelah_diskon',
                'dpo.total_biaya_tambahan',
                'dpo.grand_total',
            ]);
        }

        $query = DB::query()
            ->fromSub(PurchaseReportSource::documents($dateFrom, $dateToEnd, $source), 'dpo')
            ->join('master_supplier as ms', 'ms.id', '=', 'dpo.supplier_id')
            ->join('master_warehouse as mw', 'mw.id', '=', 'dpo.warehouse_id')
            ->select($select);

        // Filters
        if ($request->filled('supplier_id')) {
            $query->where('dpo.supplier_id', $request->supplier_id);
        }
        if ($request->filled('warehouse_id')) {
            $query->where('dpo.warehouse_id', $request->warehouse_id);
        }
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('dpo.nomor_dokumen', 'like', "%{$search}%")
                  ->orWhere('ms.nama_supplier', 'like', "%{$search}%");
            });
        }

        ReportHelperService::applySortWhitelist(
            $query,
            $request,
            ['tanggal_po', 'nomor_dokumen', 'grand_total', 'tempo_hari'],
            'tanggal_po'
        );

        $paginator = $query->paginate($this->getPerPage($request));

        // Summary (only if can view harga)
        $summary = [
            'jumlah_po' => 0,
            'total_subtotal' => 0,
            'total_diskon' => 0,
            'total_grand_total' => 0,
        ];

        if ($canViewHarga) {
            $summaryQuery = DB::query()
                ->fromSub(PurchaseReportSource::documents($dateFrom, $dateToEnd, $source), 'dpo')
                ->join('master_supplier as ms', 'ms.id', '=', 'dpo.supplier_id');

            if ($request->filled('supplier_id')) {
                $summaryQuery->where('dpo.supplier_id', $request->supplier_id);
            }
            if ($request->filled('warehouse_id')) {
                $summaryQuery->where('dpo.warehouse_id', $request->warehouse_id);
            }
            if ($request->filled('search')) {
                $search = $request->search;
                $summaryQuery->where(function ($q) use ($search) {
                    $q->where('dpo.nomor_dokumen', 'like', "%{$search}%")
                      ->orWhere('ms.nama_supplier', 'like', "%{$search}%");
                });
            }

            $summaryRaw = $summaryQuery->select(
                DB::raw('COUNT(*) as jumlah_po'),
                DB::raw('COALESCE(SUM(dpo.subtotal), 0) as total_subtotal'),
                DB::raw('COALESCE(SUM(dpo.total_diskon_header), 0) as total_diskon'),
                DB::raw('COALESCE(SUM(dpo.grand_total), 0) as total_grand_total')
            )->first();

            $summary = [
                'jumlah_po' => $summaryRaw->jumlah_po ?? 0,
                'total_subtotal' => round((float) ($summaryRaw->total_subtotal ?? 0), 2),
                'total_diskon' => round((float) ($summaryRaw->total_diskon ?? 0), 2),
                'total_grand_total' => round((float) ($summaryRaw->total_grand_total ?? 0), 2),
            ];
        } else {
            // Still count POs even without harga permission
            $countQuery = DB::query()
                ->fromSub(PurchaseReportSource::documents($dateFrom, $dateToEnd, $source), 'dpo')
                ->join('master_supplier as ms', 'ms.id', '=', 'dpo.supplier_id');

            if ($request->filled('supplier_id')) {
                $countQuery->where('dpo.supplier_id', $request->supplier_id);
            }
            if ($request->filled('warehouse_id')) {
                $countQuery->where('dpo.warehouse_id', $request->warehouse_id);
            }
            if ($request->filled('search')) {
                $search = $request->search;
                $countQuery->where(function ($q) use ($search) {
                    $q->where('dpo.nomor_dokumen', 'like', "%{$search}%")
                      ->orWhere('ms.nama_supplier', 'like', "%{$search}%");
                });
            }

            $summary['jumlah_po'] = $countQuery->count();
        }

        return $this->success(
            ReportHelperService::buildPaginatedResponse($paginator, $summary, ['can_view_harga' => $canViewHarga, 'source' => $source])
        );
    }

    /**
     * Show a single PO detail for the report.
     * Uses laporan.pembelian permission, respects po.view_harga for financial fields.
     */
    public function showPo(string $ulid): JsonResponse
    {
        if (!auth()->user()->can('laporan.pembelian')) {
            return $this->forbidden('Anda tidak memiliki akses untuk melihat laporan.');
        }

        $po = DocPurchaseOrder::with([
            'supplier:id,ulid,kode_supplier,nama_supplier',
            'warehouse:id,ulid,kode_warehouse,nama_warehouse',
            'details.product:id,ulid,kode_produk,nama_produk',
            'createdBy:id,name',
            'approvedBy:id,name',
        ])->where('ulid', $ulid)
          ->where('status', 'approved')
          ->first();

        if (!$po) {
            return $this->notFound('Purchase order tidak ditemukan.');
        }

        $canViewHarga = auth()->user()->can('po.view_harga');

        // Hide sensitive detail fields if not allowed
        foreach ($po->details as $detail) {
            if (!$canViewHarga) {
                $detail->makeHidden([
                    'harga_per_unit', 'harga_per_base', 'harga_bruto',
                    'diskon_1_hasil', 'diskon_2_hasil', 'diskon_3_hasil',
                    'diskon_4_hasil', 'diskon_5_hasil', 'total_diskon_item',
                    'subtotal', 'cost_per_unit',
                ]);
            }
        }

        // Hide sensitive header fields if not allowed
        if (!$canViewHarga) {
            $po->makeHidden([
                'subtotal', 'diskon_1_hasil', 'diskon_2_hasil', 'diskon_3_hasil',
                'total_diskon_header', 'total_setelah_diskon',
                'biaya_kirim_hasil', 'biaya_lain_hasil', 'total_biaya_tambahan',
                'dpp', 'pajak_nominal', 'pembulatan', 'grand_total',
            ]);
        }

        return $this->success([
            'purchase_order' => $po,
            'can_view_harga' => $canViewHarga,
        ]);
    }

    public function exportPerDokumen(Request $request)
    {
        if (!auth()->user()->can('laporan.export')) {
            return $this->forbidden('Anda tidak memiliki akses untuk export laporan.');
        }

        $request->validate(ReportHelperService::dateRangeRules());

        $filename = 'laporan_pembelian_per_dokumen_' . date('Y-m-d_His') . '.xlsx';

        return Excel::download(new PurchasePerDokumenExport(
            $request->date_from,
            $request->date_to,
            auth()->user()->can('po.view_harga'),
            $request->filled('supplier_id') ? (int) $request->supplier_id : null,
            $request->filled('warehouse_id') ? (int) $request->warehouse_id : null,
            $request->input('search'),
            ReportHelperService::resolveSource($request),
        ), $filename);
    }
}
