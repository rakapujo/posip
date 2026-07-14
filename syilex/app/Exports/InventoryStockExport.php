<?php

namespace App\Exports;

use App\Models\InventoryStock;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;

class InventoryStockExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected bool $canViewHpp;
    protected ?int $warehouseId;
    protected ?string $search;
    protected bool $lowStockOnly;
    protected ?string $status;

    public function __construct(bool $canViewHpp, ?int $warehouseId = null, ?string $search = null, bool $lowStockOnly = false, ?string $status = null)
    {
        $this->canViewHpp = $canViewHpp;
        $this->warehouseId = $warehouseId;
        $this->search = $search;
        $this->lowStockOnly = $lowStockOnly;
        $this->status = $status;
    }

    public function query()
    {
        $query = InventoryStock::query()
            ->with([
                'product:id,kode_produk,barcode,nama_produk,minimum_stok,status,brand_id,kategori_id,grup_id,unit_1,unit_2,unit_3,unit_4,konversi_1,konversi_2,konversi_3,konversi_4',
                'product.brand:id,kode_brand,nama_brand',
                'product.kategori:id,kode_kategori,nama_kategori,tipe_id',
                'product.kategori.tipe:id,kode_tipe,nama_tipe',
                'product.grup:id,kode_grup,nama_grup',
                'warehouse:id,kode_warehouse,nama_warehouse',
            ])
            ->activeWarehouse();

        // Filter by product status
        if ($this->status) {
            $query->whereHas('product', fn($q) => $q->where('status', $this->status));
        } else {
            $query->activeProduct();
        }

        // Filter by warehouse
        if ($this->warehouseId) {
            $query->where('warehouse_id', $this->warehouseId);
        }

        // Search
        if ($this->search) {
            $query->search($this->search);
        }

        // Low stock filter
        if ($this->lowStockOnly) {
            $query->lowStock();
        }

        return $query;
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        $headings = [
            'Kode Produk',
            'Barcode',
            'Nama Produk',
            'Hierarki Satuan',
            'Kode Brand',
            'Nama Brand',
            'Kode Tipe Produk',
            'Nama Tipe Produk',
            'Kode Kategori Produk',
            'Nama Kategori Produk',
            'Kode Grup Produk',
            'Nama Grup Produk',
            'Kode Gudang',
            'Nama Gudang',
            'Qty',
            'Satuan',
            'Stok per Satuan',
            'Min. Stok',
            'Status Stok',
        ];

        if ($this->canViewHpp) {
            $headings[] = 'HPP';
            $headings[] = 'Total Nilai';
        }

        return $headings;
    }

    /**
     * @param InventoryStock $stock
     * @return array
     */
    public function map($stock): array
    {
        $product = $stock->product;
        $isLowStock = $product && $stock->qty < $product->minimum_stok;
        $isNegative = $stock->qty < 0;

        // Determine stock status
        $statusStok = 'OK';
        if ($isNegative) {
            $statusStok = 'NEGATIF';
        } elseif ($isLowStock) {
            $statusStok = 'MENIPIS';
        }

        // Build unit hierarchy and stock breakdown
        $hierarkiSatuan = $this->formatUnitHierarchy($product);
        $stokPerSatuan = $this->formatStockBreakdown($stock->qty, $product);

        $row = [
            $product?->kode_produk ?? '-',
            $product?->barcode ?? '-',
            $product?->nama_produk ?? '-',
            $hierarkiSatuan,
            $product?->brand?->kode_brand ?? '-',
            $product?->brand?->nama_brand ?? '-',
            $product?->kategori?->tipe?->kode_tipe ?? '-',
            $product?->kategori?->tipe?->nama_tipe ?? '-',
            $product?->kategori?->kode_kategori ?? '-',
            $product?->kategori?->nama_kategori ?? '-',
            $product?->grup?->kode_grup ?? '-',
            $product?->grup?->nama_grup ?? '-',
            $stock->warehouse?->kode_warehouse ?? '-',
            $stock->warehouse?->nama_warehouse ?? '-',
            $stock->qty,
            $product?->unit_4 ?? 'PCS',
            $stokPerSatuan,
            $product?->minimum_stok ?? 0,
            $statusStok,
        ];

        if ($this->canViewHpp) {
            $row[] = $stock->avg_cost;
            $row[] = $stock->qty * $stock->avg_cost;
        }

        return $row;
    }

    /**
     * Get unique units from product (sorted by konversi descending)
     */
    protected function getUniqueUnits($product): array
    {
        if (!$product) return [];

        $units = [];
        for ($i = 1; $i <= 4; $i++) {
            $unit = $product->{"unit_$i"};
            $konversi = $product->{"konversi_$i"};
            if ($unit && $konversi !== null) {
                $units[] = ['unit' => $unit, 'konversi' => (int) $konversi];
            }
        }

        // Filter unique by konversi value
        $seen = [];
        $uniqueUnits = [];
        foreach ($units as $u) {
            if (!isset($seen[$u['konversi']])) {
                $seen[$u['konversi']] = true;
                $uniqueUnits[] = $u;
            }
        }

        // Sort by konversi descending (largest first)
        usort($uniqueUnits, fn($a, $b) => $b['konversi'] <=> $a['konversi']);

        return $uniqueUnits;
    }

    /**
     * Format unit hierarchy: "1 KRT = 10 BOX = 100 PCS"
     */
    protected function formatUnitHierarchy($product): string
    {
        $units = $this->getUniqueUnits($product);
        if (empty($units)) return '-';
        if (count($units) === 1) return "1 {$units[0]['unit']}";

        return implode(' = ', array_map(fn($u) => "{$u['konversi']} {$u['unit']}", $units));
    }

    /**
     * Format stock breakdown: "2 KRT | 3 BOX | 7 PCS"
     */
    protected function formatStockBreakdown(int $qty, $product): string
    {
        if (!$product) return (string) $qty;

        $units = $this->getUniqueUnits($product);
        if (empty($units)) return (string) $qty;
        if (count($units) === 1) return "{$qty} {$units[0]['unit']}";

        $remaining = abs($qty);
        $parts = [];

        foreach ($units as $u) {
            $count = intdiv($remaining, $u['konversi']);
            $remaining = $remaining % $u['konversi'];
            $parts[] = "{$count} {$u['unit']}";
        }

        $result = implode(' | ', $parts);
        return $qty < 0 ? "-{$result}" : $result;
    }
}
