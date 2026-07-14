<?php

namespace App\Exports;

use App\Models\MasterBrand;
use App\Models\MasterGrup;
use App\Models\MasterKategori;
use App\Models\MasterKategoriCustomer;
use App\Models\MasterTipe;
use App\Models\MasterTipeCustomer;
use App\Models\MasterWarehouse;
use Illuminate\Database\Eloquent\Builder;
use Closure;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use App\Exports\Concerns\UsesExportSheetStyles;

/**
 * DRY export untuk master data sederhana — satu class, factory per resource.
 */
class MasterListExport implements FromQuery, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
{
    use UsesExportSheetStyles;

    private int $rowNumber = 0;

    public function __construct(
        private readonly Closure $queryFactory,
        private readonly array $headings,
        private readonly Closure $mapRow,
    ) {}

    public function query(): Builder
    {
        return ($this->queryFactory)();
    }

    public function headings(): array
    {
        return $this->headings;
    }

    public function map($row): array
    {
        $this->rowNumber++;

        return ($this->mapRow)($row, $this->rowNumber);
    }

    public static function statusLabel(?string $status): string
    {
        return $status === 'active' ? 'Aktif' : 'Nonaktif';
    }

    /** @param class-string $modelClass */
    private static function applySearchStatus(
        string $modelClass,
        array $searchFields,
        string $orderColumn,
        ?string $search,
        ?string $status,
        ?callable $extraFilter = null,
    ): Builder {
        $query = $modelClass::query();

        if ($search) {
            $query->where(function ($q) use ($search, $searchFields) {
                foreach ($searchFields as $field) {
                    $q->orWhere($field, 'like', "%{$search}%");
                }
            });
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($extraFilter) {
            $extraFilter($query);
        }

        return $query->orderBy($orderColumn, 'asc');
    }

    public static function brands(?string $search, ?string $status): self
    {
        return new self(
            fn () => self::applySearchStatus(MasterBrand::class, ['kode_brand', 'nama_brand'], 'kode_brand', $search, $status),
            ['No', 'Kode Brand', 'Nama Brand', 'Status'],
            fn ($row, $n) => [$n, $row->kode_brand, $row->nama_brand, self::statusLabel($row->status)],
        );
    }

    public static function tipes(?string $search, ?string $status): self
    {
        return new self(
            fn () => self::applySearchStatus(MasterTipe::class, ['kode_tipe', 'nama_tipe'], 'kode_tipe', $search, $status),
            ['No', 'Kode Tipe', 'Nama Tipe', 'Status'],
            fn ($row, $n) => [$n, $row->kode_tipe, $row->nama_tipe, self::statusLabel($row->status)],
        );
    }

    public static function tipeCustomers(?string $search, ?string $status): self
    {
        return new self(
            fn () => self::applySearchStatus(MasterTipeCustomer::class, ['kode_tipe', 'nama_tipe'], 'kode_tipe', $search, $status),
            ['No', 'Kode Tipe', 'Nama Tipe', 'Keterangan', 'Status'],
            fn ($row, $n) => [$n, $row->kode_tipe, $row->nama_tipe, $row->keterangan ?? '-', self::statusLabel($row->status)],
        );
    }

    public static function kategoriCustomers(?string $search, ?string $status): self
    {
        return new self(
            fn () => self::applySearchStatus(MasterKategoriCustomer::class, ['kode_kategori', 'nama_kategori'], 'kode_kategori', $search, $status),
            ['No', 'Kode Kategori', 'Nama Kategori', 'Keterangan', 'Status'],
            fn ($row, $n) => [$n, $row->kode_kategori, $row->nama_kategori, $row->keterangan ?? '-', self::statusLabel($row->status)],
        );
    }

    public static function kategoris(?string $search, ?string $status, ?int $tipeId): self
    {
        return new self(
            fn () => self::applySearchStatus(
                MasterKategori::class,
                ['kode_kategori', 'nama_kategori'],
                'kode_kategori',
                $search,
                $status,
                function ($q) use ($tipeId) {
                    $q->with(['tipe:id,kode_tipe,nama_tipe']);
                    if ($tipeId) {
                        $q->where('tipe_id', $tipeId);
                    }
                },
            ),
            ['No', 'Kode Kategori', 'Nama Kategori', 'Kode Tipe', 'Tipe', 'Status'],
            fn ($row, $n) => [
                $n,
                $row->kode_kategori,
                $row->nama_kategori,
                $row->tipe?->kode_tipe ?? '-',
                $row->tipe?->nama_tipe ?? '-',
                self::statusLabel($row->status),
            ],
        );
    }

    public static function grups(?string $search, ?string $status, ?int $kategoriId): self
    {
        return new self(
            fn () => self::applySearchStatus(
                MasterGrup::class,
                ['kode_grup', 'nama_grup'],
                'kode_grup',
                $search,
                $status,
                function ($q) use ($kategoriId) {
                    $q->with([
                        'kategori:id,kode_kategori,nama_kategori,tipe_id',
                        'kategori.tipe:id,kode_tipe,nama_tipe',
                    ]);
                    if ($kategoriId) {
                        $q->where('kategori_id', $kategoriId);
                    }
                },
            ),
            ['No', 'Kode Grup', 'Nama Grup', 'Kode Kategori', 'Kategori', 'Kode Tipe', 'Tipe', 'Status'],
            fn ($row, $n) => [
                $n,
                $row->kode_grup,
                $row->nama_grup,
                $row->kategori?->kode_kategori ?? '-',
                $row->kategori?->nama_kategori ?? '-',
                $row->kategori?->tipe?->kode_tipe ?? '-',
                $row->kategori?->tipe?->nama_tipe ?? '-',
                self::statusLabel($row->status),
            ],
        );
    }

    public static function warehouses(?string $search, ?string $status, ?string $isSaleable): self
    {
        return new self(
            fn () => self::applySearchStatus(
                MasterWarehouse::class,
                ['kode_warehouse', 'nama_warehouse', 'alamat', 'pic_name'],
                'kode_warehouse',
                $search,
                $status,
                function ($q) use ($isSaleable) {
                    if ($isSaleable !== null) {
                        $q->where('is_saleable', $isSaleable === '1' || $isSaleable === 'true');
                    }
                },
            ),
            ['No', 'Kode Warehouse', 'Nama Warehouse', 'Alamat', 'PIC', 'Telepon PIC', 'Dapat Dijual (POS)', 'Status'],
            fn ($row, $n) => [
                $n,
                $row->kode_warehouse,
                $row->nama_warehouse,
                $row->alamat ?? '-',
                $row->pic_name ?? '-',
                $row->pic_phone ?? '-',
                $row->is_saleable ? 'Ya' : 'Tidak',
                self::statusLabel($row->status),
            ],
        );
    }
}
