<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class ReturPatternExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(
        protected string $dateFrom,
        protected string $dateTo,
        protected ?int $terminalId = null,
        protected ?int $kategoriId = null,
        protected int $limit = 50,
        protected string $sort = 'count_desc',
    ) {
        $base = DB::table('doc_sales_return_detail as rd')
            ->join('doc_sales_returns as r', 'r.id', '=', 'rd.return_id')
            ->join('master_produk as p', 'p.id', '=', 'rd.product_id')
            ->leftJoin('master_kategori as k', 'k.id', '=', 'p.kategori_id')
            ->whereBetween('r.tanggal', [$dateFrom.' 00:00:00', $dateTo.' 23:59:59'])
            ->when($terminalId, fn ($q) => $q->where('r.terminal_id', $terminalId))
            ->when($kategoriId, fn ($q) => $q->where('p.kategori_id', $kategoriId));

        $query = (clone $base)
            ->select(
                'p.kode_produk',
                'p.nama_produk',
                'k.nama_kategori',
                DB::raw('COUNT(DISTINCT r.id) as retur_count'),
                DB::raw('SUM(rd.qty_base) as qty_total'),
                DB::raw('SUM(rd.harga_satuan * rd.qty) as nominal_total')
            )
            ->groupBy('p.id', 'p.kode_produk', 'p.nama_produk', 'k.nama_kategori');

        match ($sort) {
            'qty_desc' => $query->orderByDesc(DB::raw('SUM(rd.qty_base)')),
            'nominal_desc' => $query->orderByDesc(DB::raw('SUM(rd.harga_satuan * rd.qty)')),
            default => $query->orderByDesc(DB::raw('COUNT(DISTINCT r.id)')),
        };

        $this->rows = $query->limit($limit)->get()->map(fn ($r) => (object) [
            'kode_produk' => $r->kode_produk,
            'nama_produk' => $r->nama_produk,
            'kategori' => $r->nama_kategori ?? '-',
            'retur_count' => (int) $r->retur_count,
            'qty_total' => (float) $r->qty_total,
            'nominal_total' => (float) $r->nominal_total,
        ]);
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['No', 'Kode Produk', 'Nama Produk', 'Kategori', 'Jumlah Retur', 'Qty Retur', 'Nominal Retur'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->kode_produk,
            $row->nama_produk,
            $row->kategori,
            $row->retur_count,
            $row->qty_total,
            $row->nominal_total,
        ];
    }
}
