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

class CustomerPromoByTipeExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(protected string $status = 'active_now')
    {
        $this->rows = CustomerPromoReportResolver::byTipeExportRows($status);
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['No', 'Kode Tipe', 'Nama Tipe', 'Disc Nota', 'Jumlah Customer', 'Promo Line'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->kode_tipe,
            $row->nama_tipe,
            $row->disc_nota,
            $row->customer_count,
            $row->promo_count,
        ];
    }
}
