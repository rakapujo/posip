<?php

namespace App\Exports;

use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use App\Exports\Concerns\UsesExportSheetStyles;

class MarginPerBarangExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    public function __construct(
        protected string $priceField = 'harga_4',
    ) {
    }

    public function query()
    {
        $priceField = $this->priceField;

        return DB::table('master_produk as p')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->whereNull('p.deleted_at')
            ->where('p.status', 'active')
            ->select(
                'p.kode_produk',
                'p.nama_produk',
                'k.nama_kategori',
                'p.avg_cost',
                DB::raw("p.{$priceField} as harga_jual"),
                DB::raw("(p.{$priceField} - p.avg_cost) as margin_nominal"),
                DB::raw("CASE WHEN p.{$priceField} > 0 THEN ROUND(((p.{$priceField} - p.avg_cost) * 1.0 / p.{$priceField}) * 100, 2) ELSE 0 END as margin_percent")
            )
            ->orderBy('p.kode_produk');
    }

    public function headings(): array
    {
        return ['No', 'Kode', 'Nama Produk', 'Kategori', 'HPP', 'Harga Jual', 'Margin', 'Margin %'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->kode_produk,
            $row->nama_produk,
            $row->nama_kategori ?? '-',
            (float) $row->avg_cost,
            (float) $row->harga_jual,
            (float) $row->margin_nominal,
            (float) $row->margin_percent,
        ];
    }

}
