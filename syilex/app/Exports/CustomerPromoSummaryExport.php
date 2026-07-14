<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Services\Reports\CustomerPromoReportResolver;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class CustomerPromoSummaryExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(protected string $status = 'active_now')
    {
        $this->rows = CustomerPromoReportResolver::summaryExportRows($status);
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['Metrik', 'Nilai'];
    }

    public function map($row): array
    {
        return [$row->metric, $row->value];
    }
}
