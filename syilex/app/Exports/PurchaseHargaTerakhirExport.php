<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class PurchaseHargaTerakhirExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
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
        // Baris pembelian terbaru per produk (PO detail + unit serial) via ROW_NUMBER.
        $ranked = DB::query()
            ->fromSub(\App\Services\PurchaseReportSource::lines($this->dateFrom, $this->dateTo, $this->source), 'l');
        if ($this->supplierId) {
            $ranked->where('l.supplier_id', $this->supplierId);
        }
        if ($this->warehouseId) {
            $ranked->where('l.warehouse_id', $this->warehouseId);
        }
        $ranked->select('l.*', DB::raw(
            'ROW_NUMBER() OVER (PARTITION BY l.product_id ORDER BY l.tanggal_po DESC, l.line_seq DESC) as rn'
        ));

        $selectColumns = [
            'dpod.sumber',
            'mp.kode_produk', 'mp.nama_produk',
            'mb.nama_brand as brand', 'mk.nama_kategori as kategori',
            'dpod.tanggal_po', 'dpod.nomor_dokumen',
            'ms.nama_supplier', 'mw.nama_warehouse',
            'dpod.unit_used', 'dpod.qty_in_unit', 'dpod.qty_in_base',
        ];

        if ($this->canViewHarga) {
            $selectColumns = array_merge($selectColumns, [
                'dpod.harga_per_unit', 'dpod.total_diskon_item', 'dpod.cost_per_unit',
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
        $headings = [
            'No', 'Sumber', 'Kode Produk', 'Nama Produk', 'Brand', 'Kategori',
            'Tgl Terakhir', 'No. Dokumen', 'Supplier', 'Gudang',
            'Unit', 'Qty',
        ];

        if ($this->canViewHarga) {
            $headings = array_merge($headings, ['Harga/Unit', 'Diskon', 'Nett/Unit']);
        }

        return $headings;
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $mapped = [
            $this->rowNumber,
            $row->sumber === 'serial' ? 'Serial' : 'PO',
            $row->kode_produk,
            $row->nama_produk,
            $row->brand ?? '-',
            $row->kategori ?? '-',
            $row->tanggal_po,
            $row->nomor_dokumen,
            $row->nama_supplier,
            $row->nama_warehouse,
            $row->unit_used,
            $row->qty_in_unit,
        ];

        if ($this->canViewHarga) {
            $mapped = array_merge($mapped, [
                $row->harga_per_unit,
                $row->total_diskon_item,
                $row->cost_per_unit,
            ]);
        }

        return $mapped;
    }

}
