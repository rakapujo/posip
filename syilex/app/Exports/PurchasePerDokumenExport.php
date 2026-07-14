<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class PurchasePerDokumenExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected string $dateFrom;
    protected string $dateTo;
    protected bool $canViewHarga;
    protected ?int $supplierId;
    protected ?int $warehouseId;
    protected ?string $search;
    protected string $source;
    protected int $rowNumber = 0;

    public function __construct(
        string $dateFrom,
        string $dateTo,
        bool $canViewHarga,
        ?int $supplierId = null,
        ?int $warehouseId = null,
        ?string $search = null,
        ?string $source = null
    ) {
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo . ' 23:59:59';
        $this->canViewHarga = $canViewHarga;
        $this->supplierId = $supplierId;
        $this->warehouseId = $warehouseId;
        $this->search = $search;
        $this->source = $source ?? 'all';
    }

    public function query()
    {
        $select = [
            'dpo.sumber',
            'dpo.tanggal_po', 'dpo.nomor_dokumen',
            'ms.kode_supplier', 'ms.nama_supplier',
            'mw.nama_warehouse',
            'dpo.details_count',
            'dpo.tempo_hari', 'dpo.tanggal_jatuh_tempo',
        ];

        if ($this->canViewHarga) {
            $select = array_merge($select, [
                'dpo.subtotal', 'dpo.total_diskon_header', 'dpo.grand_total',
            ]);
        }

        $query = DB::query()
            ->fromSub(\App\Services\PurchaseReportSource::documents($this->dateFrom, $this->dateTo, $this->source), 'dpo')
            ->join('master_supplier as ms', 'ms.id', '=', 'dpo.supplier_id')
            ->join('master_warehouse as mw', 'mw.id', '=', 'dpo.warehouse_id')
            ->select($select);

        if ($this->supplierId) {
            $query->where('dpo.supplier_id', $this->supplierId);
        }
        if ($this->warehouseId) {
            $query->where('dpo.warehouse_id', $this->warehouseId);
        }
        if ($this->search) {
            $search = $this->search;
            $query->where(function ($q) use ($search) {
                $q->where('dpo.nomor_dokumen', 'like', "%{$search}%")
                  ->orWhere('ms.nama_supplier', 'like', "%{$search}%");
            });
        }

        return $query->orderBy('dpo.tanggal_po', 'desc');
    }

    public function headings(): array
    {
        $headings = ['No', 'Sumber', 'Tanggal', 'No. Dokumen', 'Supplier', 'Gudang', 'Jumlah Item'];

        if ($this->canViewHarga) {
            $headings = array_merge($headings, ['Subtotal', 'Diskon', 'Grand Total']);
        }

        $headings = array_merge($headings, ['Tempo (Hari)', 'Jatuh Tempo']);

        return $headings;
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $mapped = [
            $this->rowNumber,
            $row->sumber === 'serial' ? 'Serial' : 'PO',
            $row->tanggal_po,
            $row->nomor_dokumen,
            "[{$row->kode_supplier}] {$row->nama_supplier}",
            $row->nama_warehouse,
            $row->details_count,
        ];

        if ($this->canViewHarga) {
            $mapped = array_merge($mapped, [
                $row->subtotal,
                $row->total_diskon_header,
                $row->grand_total,
            ]);
        }

        $mapped = array_merge($mapped, [
            $row->tempo_hari ?? 0,
            $row->tanggal_jatuh_tempo ?? '-',
        ]);

        return $mapped;
    }

}
