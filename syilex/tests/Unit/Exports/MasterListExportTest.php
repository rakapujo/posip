<?php

namespace Tests\Unit\Exports;

use App\Exports\MasterListExport;
use App\Models\MasterBrand;
use App\Models\MasterTipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MasterListExportTest extends TestCase
{
    use RefreshDatabase;

    private function createBrand(string $kode, string $nama, string $status = 'active'): MasterBrand
    {
        $user = User::factory()->create();

        return MasterBrand::create([
            'ulid' => (string) Str::ulid(),
            'kode_brand' => $kode,
            'nama_brand' => $nama,
            'status' => $status,
            'created_by' => $user->id,
        ]);
    }

    private function createTipe(string $kode, string $nama): MasterTipe
    {
        $user = User::factory()->create();

        return MasterTipe::create([
            'ulid' => (string) Str::ulid(),
            'kode_tipe' => $kode,
            'nama_tipe' => $nama,
            'status' => 'active',
            'created_by' => $user->id,
        ]);
    }
    #[Test]
    public function status_label_maps_active_and_inactive(): void
    {
        $this->assertSame('Aktif', MasterListExport::statusLabel('active'));
        $this->assertSame('Nonaktif', MasterListExport::statusLabel('inactive'));
    }
    #[Test]
    public function brands_export_filters_search_and_status(): void
    {
        $this->createBrand('BR01', 'Alpha', 'active');
        $this->createBrand('BR02', 'Beta', 'inactive');

        $rows = MasterListExport::brands('Alpha', 'active')->query()->get();

        $this->assertCount(1, $rows);
        $this->assertSame('Alpha', $rows->first()->nama_brand);
    }
    #[Test]
    public function brands_export_maps_row_with_row_number(): void
    {
        $brand = $this->createBrand('ZZZ', 'Zeta', 'active');

        $export = MasterListExport::brands(null, null);
        $mapped = $export->map($brand);

        $this->assertSame(1, $mapped[0]);
        $this->assertSame('ZZZ', $mapped[1]);
        $this->assertSame('Zeta', $mapped[2]);
        $this->assertSame('Aktif', $mapped[3]);
    }
    #[Test]
    public function tipes_export_orders_by_kode_tipe(): void
    {
        $this->createTipe('B', 'B');
        $this->createTipe('A', 'A');

        $rows = MasterListExport::tipes(null, null)->query()->pluck('kode_tipe')->all();

        $this->assertSame(['A', 'B'], $rows);
    }
}
