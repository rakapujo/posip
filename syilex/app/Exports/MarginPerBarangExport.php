<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class MarginPerBarangExport implements FromQuery, ShouldAutoSize, WithHeadings, WithMapping, WithStyles
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    public function __construct(
        protected string $priceField = 'harga_4',
        protected string $marginBucket = 'any',
        protected string $sort = 'margin_asc',
        protected ?string $search = null,
        protected ?string $status = 'active',
        protected ?int $brandId = null,
        protected ?int $kategoriId = null,
        protected ?int $grupId = null,
        protected ?int $tipeId = null,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        return new self(
            $request->input('price_field', 'harga_4'),
            $request->input('margin_bucket', 'any'),
            $request->input('sort', 'margin_asc'),
            $request->filled('search') ? $request->search : null,
            $request->input('status', 'active'),
            $request->filled('brand_id') ? (int) $request->brand_id : null,
            $request->filled('kategori_id') ? (int) $request->kategori_id : null,
            $request->filled('grup_id') ? (int) $request->grup_id : null,
            $request->filled('tipe_id') ? (int) $request->tipe_id : null,
        );
    }

    public function query()
    {
        $priceField = $this->priceField;
        $marginExpr = "(CASE WHEN p.{$priceField} > 0 THEN ((p.{$priceField} - p.avg_cost) * 1.0 / p.{$priceField}) * 100 ELSE 0 END)";

        $q = DB::table('master_produk as p')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->whereNull('p.deleted_at')
            ->select(
                'p.kode_produk',
                'p.nama_produk',
                'k.nama_kategori',
                'p.avg_cost',
                DB::raw("p.{$priceField} as harga_jual"),
                DB::raw("(p.{$priceField} - p.avg_cost) as margin_nominal"),
                DB::raw("CASE WHEN p.{$priceField} > 0 THEN ROUND(((p.{$priceField} - p.avg_cost) * 1.0 / p.{$priceField}) * 100, 2) ELSE 0 END as margin_percent")
            );

        if ($this->status) {
            $q->where('p.status', $this->status);
        }
        if ($this->brandId) {
            $q->where('p.brand_id', $this->brandId);
        }
        if ($this->kategoriId) {
            $q->where('p.kategori_id', $this->kategoriId);
        }
        if ($this->grupId) {
            $q->where('p.grup_id', $this->grupId);
        }
        if ($this->tipeId) {
            $q->where('p.tipe_id', $this->tipeId);
        }
        if ($this->search) {
            $s = $this->search;
            $q->where(function ($qq) use ($s) {
                $qq->where('p.kode_produk', 'like', "%{$s}%")
                    ->orWhere('p.nama_produk', 'like', "%{$s}%");
            });
        }
        if ($this->marginBucket !== 'any') {
            $q->whereRaw($marginExpr.' '.match ($this->marginBucket) {
                'low' => '< 10',
                'medium' => 'BETWEEN 10 AND 20',
                'high' => '> 20',
                default => '>= 0',
            });
        }

        match ($this->sort) {
            'margin_desc' => $q->orderByRaw($marginExpr.' DESC'),
            'nama_asc' => $q->orderBy('p.nama_produk'),
            'kode_asc' => $q->orderBy('p.kode_produk'),
            default => $q->orderByRaw($marginExpr.' ASC'),
        };

        return $q;
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
