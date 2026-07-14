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
use App\Services\ReportHelperService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Shared dropdown data untuk filter laporan pembelian.
 * Split dari PurchaseReportController (W3 refactor).
 */
class DropdownsController extends BaseApiController
{
    /**
     * Shared dropdown data for all purchase report filters.
     */
    public function dropdowns(): JsonResponse
    {
        if (!auth()->user()->can('laporan.pembelian')) {
            return $this->forbidden('Anda tidak memiliki akses.');
        }

        // Supplier/warehouse yang punya pembelian APPROVED — PO biasa ATAU pembelian serial.
        $suppliers = MasterSupplier::select('id', 'kode_supplier', 'nama_supplier')
            ->where(fn ($q) => $q
                ->whereHas('purchaseOrders', fn ($p) => $p->approved())
                ->orWhereHas('serialIntakes', fn ($s) => $s->approved()))
            ->active()
            ->orderBy('nama_supplier')
            ->get()
            ->makeVisible('id');

        $warehouses = MasterWarehouse::select('id', 'kode_warehouse', 'nama_warehouse')
            ->where(fn ($q) => $q
                ->whereHas('purchaseOrders', fn ($p) => $p->approved())
                ->orWhereHas('serialIntakes', fn ($s) => $s->approved()))
            ->active()
            ->orderBy('nama_warehouse')
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

        return $this->success([
            'suppliers' => $suppliers,
            'warehouses' => $warehouses,
            'brands' => $brands,
            'kategoris' => $kategoris,
        ]);
    }
}
