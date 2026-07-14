<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class PurchasePerSupplierExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected string $dateFrom;
    protected string $dateTo;
    protected bool $canViewHarga;
    protected ?int $warehouseId;
    protected ?string $search;
    protected string $source;
    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        bool $canViewHarga,
        ?int $warehouseId = null,
        ?string $search = null,
        ?string $source = null
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo . ' 23:59:59';
        $this->canViewHarga = $canViewHarga;
        $this->warehouseId = $warehouseId;
        $this->search = $search;
        $this->source = $source ?? 'all';
    }

    public function query()
    {
        $selectColumns = [
            'ms.kode_supplier', 'ms.nama_supplier',
            DB::raw('COUNT(*) as jumlah_po'),
        ];

        if ($this->canViewHarga) {
            $selectColumns = array_merge($selectColumns, [
                DB::raw('COALESCE(SUM(dpo.subtotal), 0) as total_subtotal'),
                DB::raw('COALESCE(SUM(dpo.total_diskon_header), 0) as total_diskon'),
                DB::raw('COALESCE(SUM(dpo.grand_total), 0) as total_grand_total'),
            ]);
        }

        $query = DB::query()
            ->fromSub(\App\Services\PurchaseReportSource::documents($this->dateFrom, $this->dateTo, $this->source), 'dpo')
            ->join('master_supplier as ms', 'ms.id', '=', 'dpo.supplier_id')
            ->select($selectColumns)
            ->groupBy('ms.id', 'ms.kode_supplier', 'ms.nama_supplier');

        if ($this->warehouseId) {
            $query->where('dpo.warehouse_id', $this->warehouseId);
        }
        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('ms.kode_supplier', 'like', "%{$search}%")
                  ->orWhere('ms.nama_supplier', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('ms.kode_supplier', 'asc');
    }

    public function headings(): array
    {
        $headings = ['No', 'Kode Supplier', 'Nama Supplier', 'Jumlah PO'];

        if ($this->canViewHarga) {
            $headings = array_merge($headings, ['Total Subtotal', 'Total Diskon', 'Total Grand Total']);
        }

        return $headings;
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $mapped = [
            $this->rowNumber,
            $row->kode_supplier,
            $row->nama_supplier,
            $row->jumlah_po,
        ];

        if ($this->canViewHarga) {
            $mapped = array_merge($mapped, [
                $row->total_subtotal,
                $row->total_diskon,
                $row->total_grand_total,
            ]);
        }

        return $mapped;
    }

}
