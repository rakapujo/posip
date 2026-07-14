<?php

namespace Tests\Feature\Reports;

use App\Models\MasterProduk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MarginPerBarangReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $userWithPerm;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['laporan.keuangan', 'stok.view_hpp'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->userWithPerm = User::factory()->create();
        $this->userWithPerm->givePermissionTo(['laporan.keuangan', 'stok.view_hpp']);
    }

    public function test_requires_laporan_keuangan_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/margin-per-barang')
            ->assertForbidden();
    }

    public function test_requires_stok_view_hpp_permission(): void
    {
        $other = User::factory()->create();
        $other->givePermissionTo('laporan.keuangan');
        $this->actingAs($other)
            ->getJson('/api/v1/reports/margin-per-barang')
            ->assertForbidden();
    }

    public function test_computes_margin_correctly(): void
    {
        MasterProduk::factory()->create([
            'kode_produk' => 'LOW-1',
            'nama_produk' => 'Low Margin',
            'avg_cost' => 950,
            'harga_4' => 1000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);
        MasterProduk::factory()->create([
            'kode_produk' => 'HIGH-1',
            'nama_produk' => 'High Margin',
            'avg_cost' => 500,
            'harga_4' => 1000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?sort=margin_desc')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(2, $items);

        // Sorted DESC — High margin (50%) first
        $this->assertEquals('HIGH-1', $items[0]['kode_produk']);
        $this->assertEquals(50.0, (float) $items[0]['margin_percent']);
        $this->assertEquals(500, (float) $items[0]['margin_nominal']);

        $this->assertEquals('LOW-1', $items[1]['kode_produk']);
        $this->assertEquals(5.0, (float) $items[1]['margin_percent']);
    }

    public function test_margin_bucket_filter_low(): void
    {
        MasterProduk::factory()->create([
            'kode_produk' => 'LOW-1', 'avg_cost' => 950, 'harga_4' => 1000, 'status' => 'active',
        ]);
        MasterProduk::factory()->create([
            'kode_produk' => 'HIGH-1', 'avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active',
        ]);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?margin_bucket=low')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('LOW-1', $items[0]['kode_produk']);
    }

    public function test_margin_bucket_filter_high(): void
    {
        MasterProduk::factory()->create([
            'kode_produk' => 'LOW-1', 'avg_cost' => 950, 'harga_4' => 1000, 'status' => 'active',
        ]);
        MasterProduk::factory()->create([
            'kode_produk' => 'HIGH-1', 'avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active',
        ]);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?margin_bucket=high')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('HIGH-1', $items[0]['kode_produk']);
    }

    public function test_summary_counts_per_bucket(): void
    {
        MasterProduk::factory()->create(['avg_cost' => 950, 'harga_4' => 1000, 'status' => 'active']);
        MasterProduk::factory()->create(['avg_cost' => 850, 'harga_4' => 1000, 'status' => 'active']); // 15% medium
        MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active']);
        MasterProduk::factory()->create(['avg_cost' => 1100, 'harga_4' => 1000, 'status' => 'active']); // rugi

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang/summary')
            ->assertOk();

        $data = $response->json('data');
        $this->assertEquals(4, $data['total_produk']);
        $this->assertEquals(2, $data['margin_rendah']);  // 5% + negative (-10%) are both < 10
        $this->assertEquals(1, $data['margin_sedang']);  // 15%
        $this->assertEquals(1, $data['margin_tinggi']);  // 50%
        $this->assertEquals(1, $data['rugi_margin']);    // 1100 > 1000
    }

    public function test_zero_price_produk_handled_as_margin_zero(): void
    {
        MasterProduk::factory()->create([
            'kode_produk' => 'ZERO-1', 'avg_cost' => 500, 'harga_4' => 0, 'status' => 'active',
        ]);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals(0.0, (float) $items[0]['margin_percent']);
    }

    public function test_search_by_kode_or_nama(): void
    {
        MasterProduk::factory()->create(['kode_produk' => 'ABC-1', 'nama_produk' => 'Alpha', 'avg_cost' => 500, 'harga_4' => 1000]);
        MasterProduk::factory()->create(['kode_produk' => 'XYZ-1', 'nama_produk' => 'Beta', 'avg_cost' => 500, 'harga_4' => 1000]);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?search=Alpha')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('Alpha', $items[0]['nama_produk']);
    }

    public function test_pagination_structure(): void
    {
        MasterProduk::factory()->count(30)->create(['avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active']);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?per_page=10')
            ->assertOk();

        $response->assertJsonStructure([
            'data' => ['items', 'pagination' => ['current_page', 'last_page', 'per_page', 'total']],
        ]);

        $this->assertEquals(10, $response->json('data.pagination.per_page'));
        $this->assertEquals(30, $response->json('data.pagination.total'));
        $this->assertCount(10, $response->json('data.items'));
    }

    // ─── Tambahan edge-case galak ────────────────────────────────────────

    public function test_bucket_boundary_margin_tepat_10_persen_masuk_medium_bukan_low(): void
    {
        // avg 900, harga 1000 → margin 10% TEPAT. BETWEEN 10 AND 20 inklusif → medium.
        MasterProduk::factory()->create([
            'kode_produk' => 'M10', 'avg_cost' => 900, 'harga_4' => 1000, 'status' => 'active',
        ]);

        $low = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?margin_bucket=low')
            ->assertOk()->json('data.items');
        $this->assertCount(0, $low, 'Margin 10% tidak boleh masuk bucket low (< 10)');

        $medium = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?margin_bucket=medium')
            ->assertOk()->json('data.items');
        $this->assertCount(1, $medium);
        $this->assertEquals('M10', $medium[0]['kode_produk']);
        $this->assertEquals(10.0, (float) $medium[0]['margin_percent']);
    }

    public function test_bucket_boundary_margin_tepat_20_persen_masuk_medium_bukan_high(): void
    {
        // avg 800, harga 1000 → margin 20% TEPAT. BETWEEN 10 AND 20 inklusif → medium, bukan high (> 20).
        MasterProduk::factory()->create([
            'kode_produk' => 'M20', 'avg_cost' => 800, 'harga_4' => 1000, 'status' => 'active',
        ]);

        $high = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?margin_bucket=high')
            ->assertOk()->json('data.items');
        $this->assertCount(0, $high, 'Margin 20% tidak boleh masuk bucket high (> 20)');

        $medium = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?margin_bucket=medium')
            ->assertOk()->json('data.items');
        $this->assertCount(1, $medium);
        $this->assertEquals('M20', $medium[0]['kode_produk']);
        $this->assertEquals(20.0, (float) $medium[0]['margin_percent']);
    }

    public function test_margin_negatif_dihitung_dan_masuk_bucket_low(): void
    {
        // avg 1200, harga 1000 → margin -20%. Masuk low (< 10).
        MasterProduk::factory()->create([
            'kode_produk' => 'RUGI', 'avg_cost' => 1200, 'harga_4' => 1000, 'status' => 'active',
        ]);

        $items = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?margin_bucket=low')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('RUGI', $items[0]['kode_produk']);
        $this->assertEquals(-20.0, (float) $items[0]['margin_percent']);
        $this->assertEquals(-200.0, (float) $items[0]['margin_nominal']);
    }

    public function test_sort_margin_asc_menempatkan_terendah_dulu(): void
    {
        MasterProduk::factory()->create(['kode_produk' => 'A-LOW', 'avg_cost' => 950, 'harga_4' => 1000, 'status' => 'active']);
        MasterProduk::factory()->create(['kode_produk' => 'A-HIGH', 'avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active']);

        $items = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?sort=margin_asc')
            ->assertOk()->json('data.items');

        $this->assertCount(2, $items);
        $this->assertEquals('A-LOW', $items[0]['kode_produk']);  // 5% dulu
        $this->assertEquals('A-HIGH', $items[1]['kode_produk']); // 50% belakangan
    }

    public function test_status_filter_membatasi_index(): void
    {
        MasterProduk::factory()->create(['kode_produk' => 'AKTIF', 'avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active']);
        MasterProduk::factory()->create(['kode_produk' => 'NONAKTIF', 'avg_cost' => 500, 'harga_4' => 1000, 'status' => 'inactive']);

        $items = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?status=inactive')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('NONAKTIF', $items[0]['kode_produk']);
    }

    public function test_soft_deleted_produk_dikecualikan(): void
    {
        $deleted = MasterProduk::factory()->create([
            'kode_produk' => 'DELETED', 'avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active',
        ]);
        MasterProduk::factory()->create([
            'kode_produk' => 'HIDUP', 'avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active',
        ]);
        $deleted->delete(); // soft delete

        $items = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('HIDUP', $items[0]['kode_produk']);
    }

    public function test_price_field_param_mengubah_basis_margin(): void
    {
        // harga_1 = 2000 (margin 75%), harga_4 = 1000 (margin 50%) dengan avg 500.
        MasterProduk::factory()->create([
            'kode_produk' => 'PF1', 'avg_cost' => 500,
            'harga_1' => 2000, 'harga_4' => 1000, 'status' => 'active',
        ]);

        $defaultItem = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang')
            ->assertOk()->json('data.items.0');
        $this->assertEquals(50.0, (float) $defaultItem['margin_percent']); // harga_4 default

        $pf1 = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang?price_field=harga_1')
            ->assertOk()->json('data.items.0');
        $this->assertEquals(75.0, (float) $pf1['margin_percent']); // (2000-500)/2000
        $this->assertEquals(1500.0, (float) $pf1['margin_nominal']);
    }

    public function test_summary_hanya_hitung_produk_active(): void
    {
        // 2 active + 1 inactive. total_produk harus 2 (summary where status=active).
        MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active']);
        MasterProduk::factory()->create(['avg_cost' => 900, 'harga_4' => 1000, 'status' => 'active']);
        MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 1000, 'status' => 'inactive']);

        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang/summary')
            ->assertOk()->json('data');

        $this->assertEquals(2, $data['total_produk']);
        $this->assertEquals(1, $data['margin_tinggi']); // 50%
        $this->assertEquals(1, $data['margin_sedang']); // 10%
    }

    public function test_summary_tanpa_harga_dihitung(): void
    {
        MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 0, 'status' => 'active']);
        MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active']);

        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang/summary')
            ->assertOk()->json('data');

        $this->assertEquals(2, $data['total_produk']);
        $this->assertEquals(1, $data['tanpa_harga']);
        $this->assertEquals(1, $data['margin_tinggi']);
    }

    public function test_summary_kosong_struktur_tetap_valid_nol(): void
    {
        // Boundary: tidak ada produk → semua 0.
        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/margin-per-barang/summary')
            ->assertOk()->json('data');

        $this->assertEquals(0, $data['total_produk']);
        $this->assertEquals(0, $data['margin_rendah']);
        $this->assertEquals(0, $data['margin_sedang']);
        $this->assertEquals(0, $data['margin_tinggi']);
        $this->assertEquals(0, $data['rugi_margin']);
        $this->assertEquals(0, $data['tanpa_harga']);
    }
}
