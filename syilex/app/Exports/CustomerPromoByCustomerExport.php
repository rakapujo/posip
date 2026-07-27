<?php

namespace App\Exports;

use App\Exports\Concerns\UsesExportSheetStyles;
use App\Services\Reports\CustomerPromoReportResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;

class CustomerPromoByCustomerExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    protected int $rowNumber = 0;

    /** @var Collection<int, object> */
    protected Collection $rows;

    public function __construct(
        protected string $status = 'active_now',
        protected ?int $tipeId = null,
        protected ?int $kategoriId = null,
        protected ?string $search = null,
        protected bool $onlyTerjaring = false,
    ) {
        $promos = CustomerPromoReportResolver::fetchScopedPromos($status, brief: true);

        $q = DB::table('master_customer as c')
            ->leftJoin('master_tipe_customer as t', 't.id', '=', 'c.tipe_customer_id')
            ->leftJoin('master_kategori_customer as k', 'k.id', '=', 'c.kategori_customer_id')
            ->whereNull('c.deleted_at')
            ->select(
                'c.kode_customer', 'c.nama',
                'c.tipe_customer_id', 'c.kategori_customer_id',
                't.kode_tipe', 't.nama_tipe', 't.diskon_tipe as tipe_disc_tipe', 't.diskon_nilai as tipe_disc_nilai',
                'k.kode_kategori', 'k.nama_kategori', 'k.diskon_tipe as kat_disc_tipe', 'k.diskon_nilai as kat_disc_nilai'
            );

        if ($tipeId) {
            $q->where('c.tipe_customer_id', $tipeId);
        }
        if ($kategoriId) {
            $q->where('c.kategori_customer_id', $kategoriId);
        }
        if ($search) {
            $q->where(function ($qq) use ($search) {
                $qq->where('c.kode_customer', 'like', "%{$search}%")
                    ->orWhere('c.nama', 'like', "%{$search}%");
            });
        }

        $this->rows = $q->orderBy('c.kode_customer')->limit(5000)->get()->map(function ($c) use ($promos) {
            $hasTipeDisc = $c->tipe_disc_tipe && $c->tipe_disc_tipe !== 'none' && (float) $c->tipe_disc_nilai > 0;
            $hasKatDisc = $c->kat_disc_tipe && $c->kat_disc_tipe !== 'none' && (float) $c->kat_disc_nilai > 0;
            $promoCount = CustomerPromoReportResolver::eligiblePromosFor(
                $c->tipe_customer_id,
                $c->kategori_customer_id,
                $promos
            )->count();
            $terjaring = $hasTipeDisc || $hasKatDisc || $promoCount > 0;

            return (object) [
                'kode_customer' => $c->kode_customer,
                'nama' => $c->nama,
                'tipe' => $c->kode_tipe ? "{$c->kode_tipe} - {$c->nama_tipe}" : '-',
                'kategori' => $c->kode_kategori ? "{$c->kode_kategori} - {$c->nama_kategori}" : '-',
                'promo_line_count' => $promoCount,
                'terjaring' => $terjaring,
            ];
        })->filter(function ($row) use ($onlyTerjaring) {
            return ! $onlyTerjaring || $row->terjaring;
        })->values();
    }

    public function collection(): Collection
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return ['No', 'Kode Customer', 'Nama', 'Tipe', 'Kategori', 'Promo Line', 'Terjaring'];
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return [
            $this->rowNumber,
            $row->kode_customer,
            $row->nama,
            $row->tipe,
            $row->kategori,
            $row->promo_line_count,
            $row->terjaring ? 'Ya' : 'Tidak',
        ];
    }

}
