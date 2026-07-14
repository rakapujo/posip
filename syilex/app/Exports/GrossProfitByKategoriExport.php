<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Services\Reports\GrossProfitReportResolver;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class GrossProfitByKategoriExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
        protected ?int $terminalId = null,
    ) {
        $this->rows = GrossProfitReportResolver::byKategoriRows($dateFrom, $dateTo, $terminalId);
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['No', 'Kategori', 'Revenue', 'HPP', 'Gross Profit', 'Margin %'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->nama_kategori,
            $row->revenue,
            $row->hpp,
            $row->profit,
            $row->margin_percent,
        ];
    }
}
