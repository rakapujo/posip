<?php

namespace App\Exports;

use App\Models\StockCard;
use App\Models\MasterProduk;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithTitle;
use App\Exports\Concerns\UsesExportSheetStyles;

class StockCardExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize, WithTitle
{
    use UsesExportSheetStyles;

    protected int $productId;
    protected bool $canViewHpp;
    protected ?int $warehouseId;
    protected ?string $startDate;
    protected ?string $endDate;
    protected ?string $transactionType;
    protected bool $hppChangedOnly;
    protected ?MasterProduk $product;

    public function __construct(
        int $productId,
        bool $canViewHpp,
        ?int $warehouseId = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?string $transactionType = null,
        bool $hppChangedOnly = false
    ) {
        $this->productId = $productId;
        $this->canViewHpp = $canViewHpp;
        $this->warehouseId = $warehouseId;
        $this->startDate = $startDate;
        $this->endDate = $endDate;
        $this->transactionType = $transactionType;
        $this->hppChangedOnly = $hppChangedOnly;
        $this->product = MasterProduk::with('brand:id,kode_brand,nama_brand')->find($productId);
    }

    public function query()
    {
        $query = StockCard::query()
            ->with([
                'product:id,kode_produk,barcode,nama_produk',
                'product.brand:id,kode_brand,nama_brand',
                'warehouse:id,kode_warehouse,nama_warehouse',
                'createdBy:id,name',
            ])
            ->byProduct($this->productId);

        // Filter by warehouse
        if ($this->warehouseId) {
            $query->byWarehouse($this->warehouseId);
        }

        // Filter by date range
        if ($this->startDate || $this->endDate) {
            $query->byDateRange($this->startDate, $this->endDate);
        }

        // Filter by transaction type
        if ($this->transactionType) {
            $query->byTransactionType($this->transactionType);
        }

        // Filter only records where HPP changed (for Pergerakan HPP export)
        if ($this->hppChangedOnly) {
            $query->whereColumn('avg_cost_before', '!=', 'avg_cost_after');
        }

        return $query
            ->orderBy('tanggal', 'asc')
            ->orderBy('id', 'asc');
    }

    /**
     * @return string
     */
    public function title(): string
    {
        return $this->hppChangedOnly ? 'Pergerakan HPP' : 'Kartu Stok';
    }

    /**
     * @return array
     */
    public function headings(): array
    {
        $headings = [
            'Tanggal',
            'Kode Produk',
            'Barcode',
            'Nama Produk',
            'Kode Gudang',
            'Nama Gudang',
            'Tipe Transaksi',
            'No. Dokumen',
            'Qty Masuk',
            'Qty Keluar',
            'Saldo',
        ];

        if ($this->canViewHpp) {
            $headings[] = 'HPP per Unit';
            $headings[] = 'Total HPP';
            $headings[] = 'HPP Sebelum';
            $headings[] = 'HPP Sesudah';
        }

        $headings[] = 'Keterangan';
        $headings[] = 'Dibuat Oleh';
        $headings[] = 'Dibuat Pada';

        return $headings;
    }

    /**
     * @param StockCard $card
     * @return array
     */
    public function map($card): array
    {
        $row = [
            $card->tanggal->format('d/m/Y H:i'),
            $card->product?->kode_produk ?? '-',
            $card->product?->barcode ?? '-',
            $card->product?->nama_produk ?? '-',
            $card->warehouse?->kode_warehouse ?? '-',
            $card->warehouse?->nama_warehouse ?? '-',
            StockCard::TRANSACTION_TYPES[$card->transaction_type] ?? $card->transaction_type,
            $card->transaction_no ?? '-',
            $card->qty_in,
            $card->qty_out,
            $card->qty_balance,
        ];

        if ($this->canViewHpp) {
            $row[] = $card->cost_per_unit;
            $row[] = $card->total_cost;
            $row[] = $card->avg_cost_before;
            $row[] = $card->avg_cost_after;
        }

        $row[] = $card->notes ?? '-';
        $row[] = $card->createdBy?->name ?? '-';
        $row[] = $card->created_at?->format('d/m/Y H:i') ?? '-';

        return $row;
    }
}
