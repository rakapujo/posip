<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class PurchasePerBarangExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected string $dateFrom;
    protected string $dateTo;
    protected bool $canViewHarga;
    protected ?int $supplierId;
    protected ?int $warehouseId;
    protected ?int $brandId;
    protected ?int $kategoriId;
    protected ?string $search;
    protected string $source;
    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        bool $canViewHarga,
        ?int $supplierId = null,
        ?int $warehouseId = null,
        ?int $brandId = null,
        ?int $kategoriId = null,
        ?string $search = null,
        ?string $source = null
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo . ' 23:59:59';
        $this->canViewHarga = $canViewHarga;
        $this->supplierId = $supplierId;
        $this->warehouseId = $warehouseId;
        $this->brandId = $brandId;
        $this->kategoriId = $kategoriId;
        $this->search = $search;
        $this->source = $source ?? 'all';
    }

    public function query()
    {
        $selectColumns = [
            'mp.kode_produk', 'mp.nama_produk',
            'mb.nama_brand as brand', 'mk.nama_kategori as kategori',
            DB::raw('COUNT(DISTINCT dpod.nomor_dokumen) as jumlah_po'),
            DB::raw('SUM(dpod.qty_in_base) as total_qty'),
        ];

        if ($this->canViewHarga) {
            $selectColumns = array_merge($selectColumns, [
                DB::raw('COALESCE(SUM(dpod.harga_bruto), 0) as total_bruto'),
                DB::raw('COALESCE(SUM(dpod.total_diskon_item), 0) as total_diskon'),
                DB::raw('COALESCE(SUM(dpod.subtotal), 0) as total_subtotal'),
            ]);
        }

        $query = DB::query()
            ->fromSub(\App\Services\PurchaseReportSource::lines($this->dateFrom, $this->dateTo, $this->source), 'dpod')
            ->join('master_produk as mp', 'mp.id', '=', 'dpod.product_id')
            ->leftJoin('master_brand as mb', 'mb.id', '=', 'mp.brand_id')
            ->leftJoin('master_kategori as mk', 'mk.id', '=', 'mp.kategori_id')
            ->select($selectColumns)
            ->groupBy('mp.id', 'mp.kode_produk', 'mp.nama_produk', 'mb.nama_brand', 'mk.nama_kategori');

        if ($this->supplierId) {
            $query->where('dpod.supplier_id', $this->supplierId);
        }
        if ($this->warehouseId) {
            $query->where('dpod.warehouse_id', $this->warehouseId);
        }
        if ($this->brandId) {
            $query->where('mp.brand_id', $this->brandId);
        }
        if ($this->kategoriId) {
            $query->where('mp.kategori_id', $this->kategoriId);
        }
        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('mp.kode_produk', 'like', "%{$search}%")
                  ->orWhere('mp.nama_produk', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('mp.kode_produk', 'asc');
    }

    public function headings(): array
    {
        $headings = ['No', 'Kode Produk', 'Nama Produk', 'Brand', 'Kategori', 'Jumlah PO', 'Total Qty'];

        if ($this->canViewHarga) {
            $headings = array_merge($headings, ['Total Bruto', 'Total Diskon', 'Total Nett']);
        }

        return $headings;
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $mapped = [
            $this->rowNumber,
            $row->kode_produk,
            $row->nama_produk,
            $row->brand ?? '-',
            $row->kategori ?? '-',
            $row->jumlah_po,
            $row->total_qty,
        ];

        if ($this->canViewHarga) {
            $mapped = array_merge($mapped, [
                $row->total_bruto,
                $row->total_diskon,
                $row->total_subtotal,
            ]);
        }

        return $mapped;
    }

}
