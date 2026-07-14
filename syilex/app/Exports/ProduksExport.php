<?php

namespace App\Exports;

use App\Models\MasterProduk;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class ProduksExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected bool $canViewHpp;
    protected ?string $search;
    protected ?int $brandId;
    protected ?int $tipeId;
    protected ?int $kategoriId;
    protected ?int $grupId;
    protected ?string $status;
    protected int $rowNumber = 0;

    public function __construct(
        bool $canViewHpp,
        ?string $search = null,
        ?int $brandId = null,
        ?int $tipeId = null,
        ?int $kategoriId = null,
        ?int $grupId = null,
        ?string $status = null
    ) {
        $this->canViewHpp = $canViewHpp;
        $this->search = $search;
        $this->brandId = $brandId;
        $this->tipeId = $tipeId;
        $this->kategoriId = $kategoriId;
        $this->grupId = $grupId;
        $this->status = $status;
    }

    public function query()
    {
        // Eager load inventoryStocks untuk cegah N+1 di map() saat hitung totalStok.
        // Tanpa ini, export 1000 produk = 1001 query (massive slowdown).
        $query = MasterProduk::with(['brand', 'tipe', 'kategori', 'grup', 'inventoryStocks']);

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('kode_produk', 'like', "%{$this->search}%")
                  ->orWhere('barcode', 'like', "%{$this->search}%")
                  ->orWhere('nama_produk', 'like', "%{$this->search}%");
            });
        }

        if ($this->brandId) {
            $query->where('brand_id', $this->brandId);
        }

        if ($this->tipeId) {
            $query->where('tipe_id', $this->tipeId);
        }

        if ($this->kategoriId) {
            $query->where('kategori_id', $this->kategoriId);
        }

        if ($this->grupId) {
            $query->where('grup_id', $this->grupId);
        }

        if ($this->status) {
            $query->where('status', $this->status);
        }

        return $query->orderBy('kode_produk', 'asc');
    }

    public function headings(): array
    {
        $headings = [
            'No',
            'Kode Produk',
            'Barcode',
            'Nama Produk',
            'Jenis',
            'Kode Brand',
            'Brand',
            'Kode Tipe',
            'Tipe',
            'Kode Kategori',
            'Kategori',
            'Kode Grup',
            'Grup',
            'Unit 1',
            'Konversi 1',
            'Harga 1',
            'Unit 2',
            'Konversi 2',
            'Harga 2',
            'Unit 3',
            'Konversi 3',
            'Harga 3',
            'Unit 4',
            'Harga 4',
            'Minimum Stok',
        ];

        if ($this->canViewHpp) {
            $headings[] = 'HPP';
        }

        $headings[] = 'Total Stok (Unit 4)';

        if ($this->canViewHpp) {
            $headings[] = 'Total Nilai';
        }

        $headings[] = 'Status';

        return $headings;
    }

    public function map($product): array
    {
        $this->rowNumber++;

        // Pakai relation yang sudah di-eager-load di query() — bukan query baru.
        $totalStok = (int) $product->inventoryStocks->sum('qty');

        $isSerial = (bool) $product->is_serial;
        // Produk serial: harga/satuan/stok di-scaffold di level produk → kosongkan (data asli per-unit).
        $blank = fn ($v) => $isSerial ? '' : $v;

        $row = [
            $this->rowNumber,
            $product->kode_produk,
            $isSerial ? '-' : ($product->barcode ?? '-'),
            $product->nama_produk,
            $isSerial ? 'Serial' : 'Retail',
            $product->brand?->kode_brand ?? '-',
            $product->brand?->nama_brand ?? '-',
            $product->tipe?->kode_tipe ?? '-',
            $product->tipe?->nama_tipe ?? '-',
            $product->kategori?->kode_kategori ?? '-',
            $product->kategori?->nama_kategori ?? '-',
            $product->grup?->kode_grup ?? '-',
            $product->grup?->nama_grup ?? '-',
            $blank($product->unit_1),
            $blank($product->konversi_1),
            $blank($product->harga_1),
            $blank($product->unit_2),
            $blank($product->konversi_2),
            $blank($product->harga_2),
            $blank($product->unit_3),
            $blank($product->konversi_3),
            $blank($product->harga_3),
            $blank($product->unit_4),
            $blank($product->harga_4),
            $isSerial ? '' : ($product->minimum_stok ?? 0),
        ];

        if ($this->canViewHpp) {
            $row[] = $blank($product->avg_cost);
        }

        $row[] = $blank($totalStok);

        if ($this->canViewHpp) {
            $row[] = $isSerial ? '' : $totalStok * ($product->avg_cost ?? 0);
        }

        $row[] = $product->status === 'active' ? 'Aktif' : 'Nonaktif';

        return $row;
    }

}
