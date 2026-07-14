<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Services\Reports\ProductPromoReportResolver;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class ProductPromoByProductExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(
        protected string $status = 'active_now',
        protected bool $onlyWithPromo = false,
    ) {
        $this->rows = ProductPromoReportResolver::byProductRows($status, $onlyWithPromo);
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['No', 'Kode Produk', 'Nama Produk', 'Brand', 'Kategori', 'Jumlah Promo', 'Kode Promo'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->kode_produk,
            $row->nama_produk,
            $row->brand,
            $row->kategori,
            $row->promo_count,
            $row->kode_promos,
        ];
    }
}
