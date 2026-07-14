<?php

namespace App\Exports;

use App\Models\DocPromo;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use App\Exports\Concerns\UsesExportSheetStyles;

class ProductPromoByPromoExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(protected string $status = 'active_now')
    {
        $query = DocPromo::query();

        match ($status) {
            'active_now' => $query->effective(),
            'approved_all' => $query->where('status', 'approved'),
            'upcoming' => $query->where('status', 'approved')
                ->where('tanggal_mulai', '>', now()->toDateString()),
            'expired' => $query->where('status', 'approved')
                ->whereNotNull('tanggal_selesai')
                ->where('tanggal_selesai', '<', now()->toDateString()),
            default => $query->effective(),
        };

        $promos = $query->select('id', 'kode_promo', 'nama_promo', 'tanggal_mulai', 'tanggal_selesai', 'status')->get();
        $detailsByPromo = DB::table('doc_promo_details')
            ->whereIn('promo_id', $promos->pluck('id'))
            ->get()
            ->groupBy('promo_id');

        $this->rows = $promos->map(function ($promo) use ($detailsByPromo) {
            $details = $detailsByPromo->get($promo->id, collect());
            $productCount = $this->countProductsForPromo($details);

            return (object) [
                'kode_promo' => $promo->kode_promo,
                'nama_promo' => $promo->nama_promo,
                'tanggal_mulai' => $promo->tanggal_mulai,
                'tanggal_selesai' => $promo->tanggal_selesai,
                'status' => $promo->status,
                'detail_count' => $details->count(),
                'product_count' => $productCount,
            ];
        })->values();
    }

    private function countProductsForPromo(Collection $details): int
    {
        $ids = [];
        foreach ($details as $detail) {
            $ids = array_merge($ids, $this->expandTargetToProductIds($detail));
        }

        return count(array_unique($ids));
    }

    /** @return list<int> */
    private function expandTargetToProductIds(object $detail): array
    {
        return match ($detail->target_type) {
            'semua' => DB::table('master_produk')
                ->whereNull('deleted_at')->where('status', 'active')->pluck('id')->all(),
            'produk' => $detail->target_id ? [(int) $detail->target_id] : [],
            'kategori' => DB::table('master_produk')
                ->whereNull('deleted_at')->where('kategori_id', $detail->target_id)->pluck('id')->all(),
            'grup' => DB::table('master_produk')
                ->whereNull('deleted_at')->where('grup_id', $detail->target_id)->pluck('id')->all(),
            default => [],
        };
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['No', 'Kode Promo', 'Nama Promo', 'Mulai', 'Selesai', 'Status', 'Jumlah Rule', 'Produk Ter-cover'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->kode_promo,
            $row->nama_promo,
            $row->tanggal_mulai,
            $row->tanggal_selesai,
            $row->status,
            $row->detail_count,
            $row->product_count,
        ];
    }

}
