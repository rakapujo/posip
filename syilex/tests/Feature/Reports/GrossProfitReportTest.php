<?php

namespace Tests\Feature\Reports;

use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\PosTerminalShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GrossProfitReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $userWithPerm;
    protected User $userNoHpp;
    protected User $userNoAny;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['laporan.keuangan', 'stok.view_hpp'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $this->userWithPerm = User::factory()->create();
        $this->userWithPerm->givePermissionTo(['laporan.keuangan', 'stok.view_hpp']);

        $this->userNoHpp = User::factory()->create();
        $this->userNoHpp->givePermissionTo('laporan.keuangan');

        $this->userNoAny = User::factory()->create();
    }

    /**
     * Seed minimal data: 1 sale completed dengan 1 produk, plus 1 return parsial.
     * Revenue sale: 10 × 1000 = 10.000, HPP: 10 × 600 = 6.000 → profit 4.000 (40%)
     * Return 2 × 1000 = 2.000 revenue, HPP 2 × 600 = 1.200 → profit net 3.200 / rev net 8.000 = 40%.
     */
    private function seedData(?string $salesTanggal = null, ?string $returnTanggal = null): array
    {
        $salesTanggal ??= now()->toDateTimeString();
        $returnTanggal ??= now()->toDateTimeString();

        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->userWithPerm->id]);

        $kategoriId = DB::table('master_kategori')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_kategori' => 'KAT-TEST',
            'nama_kategori' => 'Kategori Test',
            'tipe_id' => DB::table('master_tipe')->insertGetId([
                'ulid' => (string) Str::ulid(),
                'kode_tipe' => 'TIP-T',
                'nama_tipe' => 'Tipe Test',
                'status' => 'active',
                'created_by' => $this->userWithPerm->id,
                'updated_by' => $this->userWithPerm->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]),
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
            'updated_by' => $this->userWithPerm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $product = MasterProduk::factory()->create([
            'kategori_id' => $kategoriId,
            'avg_cost' => 600,
            'harga_4' => 1000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);

        $cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'jenis' => null,
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
        ]);

        $terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-GP',
            'nama_terminal' => 'Kasir Profit',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $this->userWithPerm->id,
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
        ]);

        $shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminal->id,
            'user_id' => $this->userWithPerm->id,
            'started_at' => now(),
        ]);

        // Customer (required FK)
        $customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-T',
            'nama' => 'Walk-in Test',
            'telepon' => '0800000000',
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
            'updated_by' => $this->userWithPerm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Sale
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-TEST-001',
            'tanggal' => $salesTanggal,
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customerId,
            'subtotal' => 10000,
            'total_setelah_diskon' => 10000,
            'grand_total' => 10000,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->userWithPerm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $salesDetailId = DB::table('doc_sales_detail')->insertGetId([
            'sales_id' => $salesId,
            'product_id' => $product->id,
            'unit' => 'PCS',
            'konversi' => 1,
            'qty' => 10,
            'qty_base' => 10,
            'harga_satuan' => 1000,
            'diskon_total' => 0,
            'jumlah' => 10000,
            'hpp_at_time' => 600,
        ]);

        // Return 2 units → revenue 2000, hpp 1200
        $returnId = DB::table('doc_sales_returns')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-TEST-001',
            'tanggal' => $returnTanggal,
            'sales_id' => $salesId,
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customerId,
            'refund_method' => 'cash',
            'grand_total' => 2000,
            'created_by' => $this->userWithPerm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('doc_sales_return_detail')->insert([
            'return_id' => $returnId,
            'sales_detail_id' => $salesDetailId,
            'product_id' => $product->id,
            'unit' => 'PCS',
            'konversi' => 1,
            'qty' => 2,
            'qty_base' => 2,
            'harga_satuan' => 1000,
            'jumlah' => 2000,
            'hpp_at_time' => 600,
        ]);

        return compact('product', 'terminal', 'shift', 'kategoriId');
    }

    public function test_summary_requires_laporan_keuangan_permission(): void
    {
        $this->actingAs($this->userNoAny)
            ->getJson('/api/v1/reports/gross-profit/summary')
            ->assertForbidden();
    }

    public function test_all_gross_profit_endpoints_require_stok_view_hpp(): void
    {
        $endpoints = [
            '/api/v1/reports/gross-profit/summary',
            '/api/v1/reports/gross-profit/daily',
            '/api/v1/reports/gross-profit/by-kategori',
            '/api/v1/reports/gross-profit/top-products?limit=5',
        ];

        foreach ($endpoints as $uri) {
            $this->actingAs($this->userNoHpp)
                ->getJson($uri)
                ->assertForbidden();
        }
    }

    public function test_summary_computes_net_revenue_hpp_profit_margin(): void
    {
        $this->seedData();

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/summary')
            ->assertOk();

        $data = $response->json('data');

        $this->assertEquals(10000, $data['revenue_gross']);
        $this->assertEquals(2000, $data['revenue_return']);
        $this->assertEquals(8000, $data['revenue_net']);
        $this->assertEquals(6000, $data['hpp_gross']);
        $this->assertEquals(1200, $data['hpp_return']);
        $this->assertEquals(4800, $data['hpp_net']);
        $this->assertEquals(3200, $data['gross_profit']);
        $this->assertEquals(40.0, $data['margin_percent']);
        $this->assertEquals(1, $data['trx_count']);
    }

    public function test_summary_zero_when_no_sales_in_period(): void
    {
        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/summary')
            ->assertOk();

        $data = $response->json('data');
        $this->assertEquals(0, $data['revenue_net']);
        $this->assertEquals(0, $data['gross_profit']);
        $this->assertEquals(0, $data['margin_percent']);
    }

    public function test_summary_ignores_voided_sales(): void
    {
        $this->seedData();
        DB::table('doc_sales')->update(['status' => 'voided']);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/summary')
            ->assertOk();

        // Only completed counted
        $this->assertEquals(0, $response->json('data.revenue_gross'));
    }

    public function test_summary_respects_date_range(): void
    {
        $this->seedData('2020-01-15 10:00:00', '2020-01-15 14:00:00');

        // Current month should be empty (default filter)
        $empty = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/summary')
            ->assertOk();
        $this->assertEquals(0, $empty->json('data.revenue_gross'));

        // Jan 2020 filter should match
        $match = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/summary?date_from=2020-01-01&date_to=2020-01-31')
            ->assertOk();
        $this->assertEquals(10000, $match->json('data.revenue_gross'));
    }

    public function test_daily_returns_one_row_per_date(): void
    {
        $this->seedData();

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/daily')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals(8000, $items[0]['revenue']);
        $this->assertEquals(3200, $items[0]['profit']);
    }

    public function test_by_kategori_aggregates_per_kategori(): void
    {
        $seeded = $this->seedData();

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/by-kategori')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals($seeded['kategoriId'], $items[0]['kategori_id']);
        $this->assertEquals(8000, $items[0]['revenue']);
        $this->assertEquals(3200, $items[0]['profit']);
    }

    public function test_top_products_ordered_by_profit_desc(): void
    {
        $this->seedData();

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/top-products?limit=5')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals(10000, $items[0]['revenue']);
        $this->assertEquals(6000, $items[0]['hpp']);
        $this->assertEquals(4000, $items[0]['profit']);
    }

    public function test_terminal_filter_limits_scope(): void
    {
        $this->seedData();
        $nonExistentTerminalId = 99999;

        $response = $this->actingAs($this->userWithPerm)
            ->getJson("/api/v1/reports/gross-profit/summary?terminal_id={$nonExistentTerminalId}")
            ->assertOk();

        $this->assertEquals(0, $response->json('data.revenue_gross'));
    }

    // ─── Tambahan edge-case galak ────────────────────────────────────────

    public function test_summary_batas_atas_tanggal_inklusif_jam_2330_ikut(): void
    {
        // Sale & return jam 23:30 di hari date_to HARUS ikut (batas 23:59:59 inklusif).
        $this->seedData('2025-03-10 23:30:00', '2025-03-10 23:45:00');

        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/summary?date_from=2025-03-01&date_to=2025-03-10')
            ->assertOk()
            ->json('data');

        $this->assertEquals(10000, $data['revenue_gross']);
        $this->assertEquals(2000, $data['revenue_return']);
        $this->assertEquals(8000, $data['revenue_net']);
        $this->assertEquals(3200, $data['gross_profit']);
    }

    public function test_summary_record_persis_setelah_date_to_dibuang(): void
    {
        // Sale tepat di hari berikutnya (00:00:01) → di luar rentang.
        $this->seedData('2025-03-11 00:00:01', '2025-03-11 00:00:01');

        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/summary?date_from=2025-03-01&date_to=2025-03-10')
            ->assertOk()
            ->json('data');

        $this->assertEquals(0, $data['revenue_gross']);
        $this->assertEquals(0, $data['revenue_return']);
    }

    public function test_summary_return_di_periode_berbeda_tidak_mengurangi(): void
    {
        // Sale Maret, return April. Filter hanya Maret → return tidak dikurangkan.
        $this->seedData('2025-03-05 10:00:00', '2025-04-05 10:00:00');

        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/summary?date_from=2025-03-01&date_to=2025-03-31')
            ->assertOk()
            ->json('data');

        // Sale ikut (Maret), return TIDAK (April).
        $this->assertEquals(10000, $data['revenue_gross']);
        $this->assertEquals(0, $data['revenue_return']);
        $this->assertEquals(10000, $data['revenue_net']);
        $this->assertEquals(6000, $data['hpp_net']);
        $this->assertEquals(4000, $data['gross_profit']);
        $this->assertEquals(40.0, $data['margin_percent']);
    }

    public function test_margin_percent_pembagian_nol_aman_saat_revenue_nol(): void
    {
        // Tanpa data → margin 0, bukan error/NaN.
        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(0, $data['margin_percent']);
        $this->assertEquals(0, $data['trx_count']);
    }

    public function test_top_products_urut_profit_desc_dengan_dua_produk(): void
    {
        $seeded = $this->seedData();

        // Produk kedua: profit lebih besar (qty 20 × (2000-500) = 30.000 profit) → harus di posisi 0.
        $product2 = MasterProduk::factory()->create([
            'kategori_id' => $seeded['kategoriId'],
            'kode_produk' => 'BIG-PROFIT',
            'avg_cost' => 500,
            'harga_4' => 2000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);

        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-TEST-002',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $seeded['terminal']->id,
            'shift_id' => $seeded['shift']->id,
            'warehouse_id' => MasterWarehouse::query()->first()->id,
            'customer_id' => DB::table('master_customer')->first()->id,
            'subtotal' => 40000, 'total_setelah_diskon' => 40000, 'grand_total' => 40000,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->userWithPerm->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId,
            'product_id' => $product2->id,
            'unit' => 'PCS', 'konversi' => 1,
            'qty' => 20, 'qty_base' => 20,
            'harga_satuan' => 2000, 'diskon_total' => 0, 'jumlah' => 40000,
            'hpp_at_time' => 500,
        ]);

        $items = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/top-products?limit=5')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(2, $items);
        // BIG-PROFIT (profit 30.000) di atas; produk asli (profit 4.000) di bawah.
        $this->assertEquals('BIG-PROFIT', $items[0]['kode_produk']);
        $this->assertEquals(40000, $items[0]['revenue']);
        $this->assertEquals(10000, $items[0]['hpp']);
        $this->assertEquals(30000, $items[0]['profit']);
        $this->assertEquals(75.0, $items[0]['margin_percent']);
        $this->assertEquals(4000, $items[1]['profit']);
    }

    public function test_top_products_limit_dibatasi(): void
    {
        $seeded = $this->seedData();
        // limit=1 → hanya 1 item walau cuma ada 1 produk; verifikasi field limit dikembalikan.
        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/top-products?limit=1')
            ->assertOk();

        $this->assertCount(1, $response->json('data.items'));
        $this->assertEquals(1, $response->json('data.limit'));
    }

    public function test_by_kategori_produk_tanpa_kategori_jadi_label_fallback(): void
    {
        // Produk tanpa kategori (kategori_id null) → label '(Tanpa Kategori)'.
        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->userWithPerm->id]);
        $product = MasterProduk::factory()->create([
            'kategori_id' => null,
            'avg_cost' => 400,
            'harga_4' => 1000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);
        $cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(), 'kode_pembayaran' => 'CASHK', 'nama_pembayaran' => 'Tunai K',
            'metode' => 'tunai', 'jenis' => null, 'biaya_tambahan_tipe' => 'none', 'biaya_tambahan_nilai' => 0,
            'status' => 'active', 'created_by' => $this->userWithPerm->id,
        ]);
        $terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(), 'kode_terminal' => 'TRM-NK', 'nama_terminal' => 'Kasir NK',
            'warehouse_id' => $warehouse->id, 'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $this->userWithPerm->id, 'status' => 'active', 'created_by' => $this->userWithPerm->id,
        ]);
        $shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(), 'terminal_id' => $terminal->id,
            'user_id' => $this->userWithPerm->id, 'started_at' => now(),
        ]);
        $customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(), 'kode_customer' => 'CUST-NK', 'nama' => 'Walk-in NK',
            'telepon' => '0800', 'status' => 'active', 'created_by' => $this->userWithPerm->id,
            'updated_by' => $this->userWithPerm->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(), 'nomor_dokumen' => 'INV-NK-1', 'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $terminal->id, 'shift_id' => $shift->id, 'warehouse_id' => $warehouse->id,
            'customer_id' => $customerId, 'subtotal' => 5000, 'total_setelah_diskon' => 5000, 'grand_total' => 5000,
            'total_biaya_pembayaran' => 0, 'status' => 'completed', 'created_by' => $this->userWithPerm->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId, 'product_id' => $product->id, 'unit' => 'PCS', 'konversi' => 1,
            'qty' => 5, 'qty_base' => 5, 'harga_satuan' => 1000, 'diskon_total' => 0, 'jumlah' => 5000,
            'hpp_at_time' => 400,
        ]);

        $items = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/gross-profit/by-kategori')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertNull($items[0]['kategori_id']);
        $this->assertEquals('(Tanpa Kategori)', $items[0]['nama_kategori']);
        $this->assertEquals(5000, $items[0]['revenue']);
        $this->assertEquals(2000, $items[0]['hpp']); // 5 × 400
        $this->assertEquals(3000, $items[0]['profit']);
    }
}
