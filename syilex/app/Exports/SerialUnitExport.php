<?php

namespace App\Exports;

use App\Models\SerialUnit;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use App\Exports\Concerns\UsesExportSheetStyles;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Export Excel Register Unit Serial — filter sama dengan SerialUnitController::index.
 * Menampilkan Harga Modal (input) + Modal Landed (cost_per_unit = HPP riil).
 */
class SerialUnitExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected ?string $search;
    protected ?string $productId;
    protected ?string $warehouseId;
    protected ?string $status;
    protected bool $canViewHpp;
    protected int $rowNumber = 0;

    public function __construct(
        ?string $search = null,
        ?string $productId = null,
        ?string $warehouseId = null,
        ?string $status = null,
        bool $canViewHpp = false
    ) {
        $this->search = $search;
        $this->productId = $productId;
        $this->warehouseId = $warehouseId;
        $this->status = $status;
        $this->canViewHpp = $canViewHpp;
    }

    public function query()
    {
        $q = SerialUnit::query()->with([
            'product:id,kode_produk,nama_produk',
            'warehouse:id,nama_warehouse',
            'intake:id,nomor_dokumen,tanggal',
        ]);

        if ($this->productId) {
            $pid = $this->productId;
            $q->whereHas('product', fn ($x) => is_numeric($pid) ? $x->where('id', $pid) : $x->where('ulid', $pid));
        }
        if ($this->warehouseId) {
            $wid = $this->warehouseId;
            $q->whereHas('warehouse', fn ($x) => is_numeric($wid) ? $x->where('id', $wid) : $x->where('ulid', $wid));
        }
        if ($this->status) {
            $q->where('status', $this->status);
        }
        if ($this->search) {
            $q->search($this->search);
        }

        return $q->orderBy('created_at', 'desc');
    }

    public function headings(): array
    {
        // Kolom cost (Harga Modal + Modal Landed/HPP) hanya untuk yang berizin lihat HPP.
        $cost = $this->canViewHpp ? ['Harga Modal', 'Modal Landed (HPP)'] : [];

        return array_merge(
            ['No', 'Kode Internal', 'Nomor Seri', 'Produk', 'Status'],
            $cost,
            ['Harga Jual', 'Grade', 'Baterai', 'Health (%)', 'Akun',
                'Gudang', 'Asal Dokumen', 'Tgl Masuk', 'Terjual'],
        );
    }

    public function map($u): array
    {
        $this->rowNumber++;

        $cost = $this->canViewHpp ? [$u->harga_modal, $u->cost_per_unit] : [];

        return array_merge(
            [
                $this->rowNumber,
                $u->kode_internal ?? '-',
                $u->serial_number,
                $u->product ? "[{$u->product->kode_produk}] {$u->product->nama_produk}" : '-',
                strtoupper($u->status ?? '-'),
            ],
            $cost,
            [
                $u->harga_jual,
                $u->grade ?? '-',
                $u->battery_condition ?? '-',
                $u->battery_health,
                $u->account_status ?? '-',
                $u->warehouse?->nama_warehouse ?? '-',
                $u->intake?->nomor_dokumen ?? '-',
                $u->intake?->tanggal?->format('Y-m-d') ?? '-',
                $u->sold_at?->format('Y-m-d H:i') ?? '-',
            ],
        );
    }
}
