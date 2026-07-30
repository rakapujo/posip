<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Services\Reports\MarginPerBarangReportBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class MarginPerBarangExport implements FromCollection, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    public function __construct(
        protected array $filters = [],
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(MarginPerBarangReportBuilder::filtersFromRequest($request));
    }

    public function collection(): Collection
    {
        return MarginPerBarangReportBuilder::flatExportRows($this->filters);
    }

    public function headings(): array
    {
        return [
            'No',
            'Tipe',
            'Kode',
            'Nama Produk',
            'Kategori',
            'Kode Internal',
            'SN',
            'HPP / Modal',
            'Harga Jual',
            'Margin',
            'Margin %',
        ];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->tipe,
            $row->kode_produk,
            $row->nama_produk,
            $row->nama_kategori ?? '-',
            $row->kode_internal ?? '-',
            $row->serial_number ?? '-',
            (float) $row->avg_cost,
            (float) $row->harga_jual,
            (float) $row->margin_nominal,
            $row->tanpa_harga ? 'Tanpa harga' : (float) $row->margin_percent,
        ];
    }
}
