<?php

namespace App\Exports;

use App\Services\Reports\DeadStockReportResolver;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use App\Exports\Concerns\UsesExportSheetStyles;

class DeadStockExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, array<string, mixed>> */
    protected Collection $items;

    public function __construct(
        array $options,
        protected bool $canViewHpp,
    ) {
        $result = DeadStockReportResolver::resolve(array_merge($options, ['limit' => 500]), $canViewHpp);
        $this->items = $result['items'];
    }

    public function collection(): Collection
    {
        return $this->items;
    }

    public function headings(): array
    {
        $headings = ['No', 'Kode', 'Nama Produk', 'Kategori', 'Grup', 'Qty Stok', 'Hari Idle', 'Terakhir Laku', 'Never Sold'];

        if ($this->canViewHpp) {
            $headings = array_merge($headings, ['HPP', 'Nilai Stok']);
        }

        return $headings;
    }

    public function map($row): array
    {
        $this->rowNumber++;

        $mapped = [
            $this->rowNumber,
            $row['kode_produk'],
            $row['nama_produk'],
            $row['kategori'] ?? '-',
            $row['grup'] ?? '-',
            $row['stock_qty'],
            $row['days_idle'] ?? '-',
            $row['last_sold'] ?? '-',
            $row['never_sold'] ? 'Ya' : 'Tidak',
        ];

        if ($this->canViewHpp) {
            $mapped[] = $row['avg_cost'] ?? 0;
            $mapped[] = $row['stock_value'] ?? 0;
        }

        return $mapped;
    }

}
