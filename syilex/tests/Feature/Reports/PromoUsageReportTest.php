<?php

namespace Tests\Feature\Reports;

use App\Models\DocPromo;
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

class PromoUsageReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;
    protected int $terminalId;
    protected int $shiftId;
    protected int $customerId;
    protected int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'laporan.promo', 'guard_name' => 'web']);

        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo('laporan.promo');

        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->viewer->id]);
        $this->warehouseId = $warehouse->id;

        $cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'jenis' => null,
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-PU',
            'nama_terminal' => 'Kasir PromoUsage',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $this->viewer->id,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);
        $this->terminalId = $terminal->id;

        $shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminal->id,
            'user_id' => $this->viewer->id,
            'started_at' => now(),
        ]);
        $this->shiftId = $shift->id;

        $this->customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-PU',
            'nama' => 'Walk-in',
            'telepon' => '08000',
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makePromo(string $kode, string $nama, string $status = 'approved'): DocPromo
    {
        return DocPromo::create([
            'ulid' => (string) Str::ulid(),
            'kode_promo' => $kode,
            'nama_promo' => $nama,
            'tanggal_mulai' => now()->subDays(10)->toDateString(),
            'tanggal_selesai' => now()->addDays(10)->toDateString(),
            'status' => $status,
            'created_by' => $this->viewer->id,
        ]);
    }

    private function makeSaleWithPromoItem(int $promoId, float $qty, float $harga, float $diskonTotal, string $status = 'completed'): int
    {
        $product = MasterProduk::factory()->create([
            'avg_cost' => 500, 'harga_4' => $harga, 'unit_4' => 'PCS', 'konversi_4' => 1,
        ]);

        $jumlah = ($qty * $harga) - $diskonTotal;

        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('######'),
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => $qty * $harga,
            'total_setelah_diskon' => $jumlah,
            'total_diskon' => $diskonTotal,
            'grand_total' => $jumlah,
            'total_bayar' => $jumlah,
            'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => $status,
            'created_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId,
            'product_id' => $product->id,
            'unit' => 'PCS',
            'konversi' => 1,
            'qty' => $qty,
            'qty_base' => $qty,
            'harga_satuan' => $harga,
            'diskon_total' => $diskonTotal,
            'jumlah' => $jumlah,
            'hpp_at_time' => 500,
            'promo_id' => $promoId,
        ]);

        return $salesId;
    }

    public function test_summary_requires_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/promo-usage/summary')
            ->assertForbidden();
    }

    public function test_summary_aggregates_usage(): void
    {
        $promo = $this->makePromo('PRM-A', 'Promo A');
        $this->makeSaleWithPromoItem($promo->id, 5, 1000, 500);  // qty 5, diskon 500, rev 4500
        $this->makeSaleWithPromoItem($promo->id, 3, 1000, 300);  // qty 3, diskon 300, rev 2700

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage/summary')
            ->assertOk();

        $data = $response->json('data');
        $this->assertEquals(1, $data['promo_used']);
        $this->assertEquals(1, $data['total_promos_approved']);
        $this->assertEquals(2, $data['trx_count']);
        $this->assertEquals(8, $data['qty_total']);
        $this->assertEquals(800, $data['diskon_total']);
        $this->assertEquals(7200, $data['revenue_net']);
    }

    public function test_summary_ignores_voided_and_non_promo_sales(): void
    {
        $promo = $this->makePromo('PRM-A', 'Promo A');
        $this->makeSaleWithPromoItem($promo->id, 5, 1000, 500);
        $this->makeSaleWithPromoItem($promo->id, 5, 1000, 500, status: 'voided');

        // Sale tanpa promo (promo_id null — skip)
        $product = MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 1000]);
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-PLAIN',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 5000, 'total_setelah_diskon' => 5000, 'total_diskon' => 0,
            'grand_total' => 5000, 'total_bayar' => 5000, 'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId, 'product_id' => $product->id,
            'unit' => 'PCS', 'konversi' => 1, 'qty' => 5, 'qty_base' => 5,
            'harga_satuan' => 1000, 'diskon_total' => 0, 'jumlah' => 5000,
            'hpp_at_time' => 500, 'promo_id' => null,
        ]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage/summary')
            ->assertOk();

        $data = $response->json('data');
        // Only completed + has promo_id
        $this->assertEquals(1, $data['trx_count']);
        $this->assertEquals(5, $data['qty_total']);
        $this->assertEquals(500, $data['diskon_total']);
    }

    public function test_index_returns_per_promo_breakdown(): void
    {
        $promoA = $this->makePromo('PRM-A', 'Promo A');
        $promoB = $this->makePromo('PRM-B', 'Promo B');

        $this->makeSaleWithPromoItem($promoA->id, 10, 1000, 1000);  // disc 1000
        $this->makeSaleWithPromoItem($promoB->id, 5, 1000, 300);    // disc 300

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(2, $items);

        // Default sort diskon_desc → A first (1000 > 300)
        $this->assertEquals('PRM-A', $items[0]['kode_promo']);
        $this->assertEquals(1000, $items[0]['diskon_total']);
        $this->assertEquals('PRM-B', $items[1]['kode_promo']);
    }

    public function test_index_include_unused_shows_zero_usage(): void
    {
        $promoUsed = $this->makePromo('PRM-U', 'Promo Used');
        $promoUnused = $this->makePromo('PRM-X', 'Promo Unused');

        $this->makeSaleWithPromoItem($promoUsed->id, 5, 1000, 500);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage?include_unused=1')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('kode_promo');
        $this->assertCount(2, $items);
        $this->assertEquals(0, $items->get('PRM-X')['trx_count']);
        $this->assertEquals(0, $items->get('PRM-X')['diskon_total']);
    }

    public function test_index_excludes_unused_by_default(): void
    {
        $promoUsed = $this->makePromo('PRM-U', 'Promo Used');
        $this->makePromo('PRM-X', 'Unused');

        $this->makeSaleWithPromoItem($promoUsed->id, 5, 1000, 500);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('PRM-U', $items[0]['kode_promo']);
    }

    public function test_show_returns_top_products_and_customers(): void
    {
        $promo = $this->makePromo('PRM-DETAIL', 'Detail Promo');
        $this->makeSaleWithPromoItem($promo->id, 10, 1000, 1000);
        $this->makeSaleWithPromoItem($promo->id, 5, 1000, 500);

        $response = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/promo-usage/{$promo->ulid}")
            ->assertOk();

        $response->assertJsonStructure([
            'data' => [
                'promo' => ['kode_promo', 'nama_promo', 'periode', 'status'],
                'top_products',
                'top_customers',
            ],
        ]);

        $this->assertEquals('PRM-DETAIL', $response->json('data.promo.kode_promo'));
        $this->assertNotEmpty($response->json('data.top_products'));
    }

    public function test_show_404_for_unknown_promo(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage/01HXFAKE')
            ->assertNotFound();
    }

    // ─── EDGE CASES (galak) ──────────────────────────────────────────────

    /**
     * Helper: buat sale dengan promo pada tanggal spesifik (untuk uji batas rentang).
     */
    private function makeSaleOnDate(int $promoId, float $qty, float $harga, float $diskonTotal, string $tanggal): int
    {
        $product = MasterProduk::factory()->create([
            'avg_cost' => 500, 'harga_4' => $harga, 'unit_4' => 'PCS', 'konversi_4' => 1,
        ]);
        $jumlah = ($qty * $harga) - $diskonTotal;

        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('######'),
            'tanggal' => $tanggal,
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => $qty * $harga,
            'total_setelah_diskon' => $jumlah,
            'total_diskon' => $diskonTotal,
            'grand_total' => $jumlah,
            'total_bayar' => $jumlah,
            'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => $tanggal,
            'updated_at' => $tanggal,
        ]);

        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId,
            'product_id' => $product->id,
            'unit' => 'PCS', 'konversi' => 1,
            'qty' => $qty, 'qty_base' => $qty,
            'harga_satuan' => $harga,
            'diskon_total' => $diskonTotal,
            'jumlah' => $jumlah,
            'hpp_at_time' => 500,
            'promo_id' => $promoId,
        ]);

        return $salesId;
    }

    /**
     * Boundary rentang tanggal: record di jam>0 pada hari date_to (23:30) HARUS ikut (batas atas 23:59:59);
     * record di luar rentang (sehari setelah date_to) TIDAK ikut.
     */
    public function test_summary_batas_rentang_tanggal_inklusif(): void
    {
        $promo = $this->makePromo('PRM-DT', 'Promo DT');

        // Tepat di awal hari date_from (00:00:00) → ikut
        $this->makeSaleOnDate($promo->id, 1, 1000, 100, '2026-06-10 00:00:00');
        // Jam 23:30 di hari date_to → ikut (batas atas 23:59:59)
        $this->makeSaleOnDate($promo->id, 2, 1000, 200, '2026-06-15 23:30:00');
        // Sehari setelah date_to → TIDAK ikut
        $this->makeSaleOnDate($promo->id, 99, 1000, 9999, '2026-06-16 08:00:00');
        // Sehari sebelum date_from → TIDAK ikut
        $this->makeSaleOnDate($promo->id, 88, 1000, 8888, '2026-06-09 23:59:59');

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage/summary?date_from=2026-06-10&date_to=2026-06-15')
            ->assertOk()->json('data');

        // Hanya 2 trx dalam rentang (qty 1 + 2 = 3, diskon 100 + 200 = 300)
        $this->assertEquals(2, $data['trx_count']);
        $this->assertEquals(3, $data['qty_total']);
        $this->assertEquals(300, $data['diskon_total']);
        $this->assertEquals('2026-06-10', $data['period']['from']);
        $this->assertEquals('2026-06-15', $data['period']['to']);
    }

    /**
     * Boundary kosong: tanpa sales sama sekali → semua agregat 0, struktur valid, tak bagi-nol.
     */
    public function test_summary_kosong_semua_nol(): void
    {
        $this->makePromo('PRM-Z', 'Approved tak dipakai');

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage/summary')
            ->assertOk()->json('data');

        $this->assertEquals(0, $data['promo_used']);
        $this->assertEquals(1, $data['total_promos_approved']); // denominator tetap hitung approved
        $this->assertEquals(0, $data['trx_count']);
        $this->assertEquals(0.0, $data['qty_total']);
        $this->assertEquals(0.0, $data['diskon_total']);
        $this->assertEquals(0.0, $data['revenue_net']);
    }

    /**
     * promo_used menghitung DISTINCT promo_id walau dipakai di banyak transaksi.
     */
    public function test_summary_promo_used_distinct(): void
    {
        $promoA = $this->makePromo('PRM-A', 'A');
        $promoB = $this->makePromo('PRM-B', 'B');

        $this->makeSaleWithPromoItem($promoA->id, 1, 1000, 100);
        $this->makeSaleWithPromoItem($promoA->id, 1, 1000, 100); // promo A lagi
        $this->makeSaleWithPromoItem($promoB->id, 1, 1000, 100);

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage/summary')
            ->assertOk()->json('data');

        $this->assertEquals(2, $data['promo_used']);  // distinct: A & B
        $this->assertEquals(2, $data['total_promos_approved']);
        $this->assertEquals(3, $data['trx_count']);
    }

    /**
     * Filter terminal_id: hanya transaksi pada terminal tsb yang dihitung.
     */
    public function test_summary_filter_terminal_id(): void
    {
        $promo = $this->makePromo('PRM-T', 'Promo T');
        $this->makeSaleWithPromoItem($promo->id, 5, 1000, 500); // terminal default

        // Terminal lain
        $otherTerminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-OTHER',
            'nama_terminal' => 'Other',
            'warehouse_id' => $this->warehouseId,
            'default_metode_pembayaran_id' => MasterMetodePembayaran::first()->id,
            'active_user_id' => $this->viewer->id,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $product = MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 1000]);
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-OTHER',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $otherTerminal->id,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 3000, 'total_setelah_diskon' => 2700, 'total_diskon' => 300,
            'grand_total' => 2700, 'total_bayar' => 2700, 'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId, 'product_id' => $product->id,
            'unit' => 'PCS', 'konversi' => 1, 'qty' => 3, 'qty_base' => 3,
            'harga_satuan' => 1000, 'diskon_total' => 300, 'jumlah' => 2700,
            'hpp_at_time' => 500, 'promo_id' => $promo->id,
        ]);

        // Filter ke terminal default → hanya 1 trx (qty 5, diskon 500)
        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/promo-usage/summary?terminal_id={$this->terminalId}")
            ->assertOk()->json('data');
        $this->assertEquals(1, $data['trx_count']);
        $this->assertEquals(5, $data['qty_total']);
        $this->assertEquals(500, $data['diskon_total']);

        // Filter ke terminal lain → hanya 1 trx (qty 3, diskon 300)
        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/promo-usage/summary?terminal_id={$otherTerminal->id}")
            ->assertOk()->json('data');
        $this->assertEquals(1, $data['trx_count']);
        $this->assertEquals(3, $data['qty_total']);
        $this->assertEquals(300, $data['diskon_total']);

        // Tanpa filter → 2 trx
        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage/summary')
            ->assertOk()->json('data');
        $this->assertEquals(2, $data['trx_count']);
        $this->assertEquals(8, $data['qty_total']);
    }

    /**
     * index sort=trx_desc: urut berdasar jumlah transaksi, bukan diskon.
     */
    public function test_index_sort_trx_desc(): void
    {
        $promoA = $this->makePromo('PRM-A', 'A'); // 1 trx diskon besar
        $promoB = $this->makePromo('PRM-B', 'B'); // 3 trx diskon kecil

        $this->makeSaleWithPromoItem($promoA->id, 1, 1000, 900);
        $this->makeSaleWithPromoItem($promoB->id, 1, 1000, 50);
        $this->makeSaleWithPromoItem($promoB->id, 1, 1000, 50);
        $this->makeSaleWithPromoItem($promoB->id, 1, 1000, 50);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage?sort=trx_desc')
            ->assertOk()->json('data.items');

        $this->assertEquals('PRM-B', $items[0]['kode_promo']); // 3 trx
        $this->assertEquals(3, $items[0]['trx_count']);
        $this->assertEquals('PRM-A', $items[1]['kode_promo']); // 1 trx
    }

    /**
     * index sort=diskon_asc: diskon menaik (kecil dulu).
     */
    public function test_index_sort_diskon_asc(): void
    {
        $promoA = $this->makePromo('PRM-A', 'A');
        $promoB = $this->makePromo('PRM-B', 'B');

        $this->makeSaleWithPromoItem($promoA->id, 1, 1000, 800); // diskon besar
        $this->makeSaleWithPromoItem($promoB->id, 1, 1000, 100); // diskon kecil

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage?sort=diskon_asc')
            ->assertOk()->json('data.items');

        $this->assertEquals('PRM-B', $items[0]['kode_promo']); // 100 < 800
        $this->assertEquals(100, $items[0]['diskon_total']);
        $this->assertEquals('PRM-A', $items[1]['kode_promo']);
    }

    /**
     * index sort=revenue_desc: berdasar revenue_net (jumlah setelah diskon).
     */
    public function test_index_sort_revenue_desc(): void
    {
        $promoA = $this->makePromo('PRM-A', 'A');
        $promoB = $this->makePromo('PRM-B', 'B');

        // A: qty 2 × 1000 - 100 = 1900 revenue
        $this->makeSaleWithPromoItem($promoA->id, 2, 1000, 100);
        // B: qty 10 × 1000 - 500 = 9500 revenue
        $this->makeSaleWithPromoItem($promoB->id, 10, 1000, 500);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage?sort=revenue_desc')
            ->assertOk()->json('data.items');

        $this->assertEquals('PRM-B', $items[0]['kode_promo']); // 9500 > 1900
        $this->assertEquals(9500, $items[0]['revenue_net']);
    }

    /**
     * index: voided sales tidak masuk agregasi index per-promo.
     */
    public function test_index_kecualikan_voided(): void
    {
        $promo = $this->makePromo('PRM-V', 'V');
        $this->makeSaleWithPromoItem($promo->id, 5, 1000, 500);
        $this->makeSaleWithPromoItem($promo->id, 99, 1000, 9999, status: 'voided');

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/promo-usage')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals(1, $items[0]['trx_count']);
        $this->assertEquals(5, $items[0]['qty_total']);
        $this->assertEquals(500, $items[0]['diskon_total']);
    }

    /**
     * show: top_products diurut diskon DESC dan limit dihormati.
     */
    public function test_show_top_products_urut_diskon_dan_limit(): void
    {
        $promo = $this->makePromo('PRM-TP', 'Top Products');

        // 3 produk berbeda dengan diskon berbeda via helper (tiap call buat produk baru)
        $this->makeSaleWithPromoItem($promo->id, 1, 1000, 100); // produk diskon 100
        $this->makeSaleWithPromoItem($promo->id, 1, 1000, 900); // produk diskon 900
        $this->makeSaleWithPromoItem($promo->id, 1, 1000, 500); // produk diskon 500

        // limit=2 → hanya 2 teratas (900, 500)
        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/promo-usage/{$promo->ulid}?limit=2")
            ->assertOk()->json('data');

        $this->assertCount(2, $data['top_products']);
        $this->assertEquals(900, (float) $data['top_products'][0]['diskon']);
        $this->assertEquals(500, (float) $data['top_products'][1]['diskon']);
    }

    /**
     * show: top_customers agregasi per customer (trx_count distinct, diskon SUM).
     */
    public function test_show_top_customers_agregasi(): void
    {
        $promo = $this->makePromo('PRM-TC', 'Top Cust');

        // 2 transaksi customer yang sama (CUST-PU dari setUp)
        $this->makeSaleWithPromoItem($promo->id, 1, 1000, 200);
        $this->makeSaleWithPromoItem($promo->id, 1, 1000, 300);

        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/promo-usage/{$promo->ulid}")
            ->assertOk()->json('data');

        $this->assertCount(1, $data['top_customers']);
        $this->assertEquals('CUST-PU', $data['top_customers'][0]['kode_customer']);
        $this->assertEquals(2, (int) $data['top_customers'][0]['trx_count']);
        $this->assertEquals(500, (float) $data['top_customers'][0]['diskon_total']);
    }

    /**
     * show: promo ada tapi belum dipakai → top_products & top_customers kosong, tak error.
     */
    public function test_show_promo_belum_dipakai_kosong(): void
    {
        $promo = $this->makePromo('PRM-IDLE', 'Idle');

        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/promo-usage/{$promo->ulid}")
            ->assertOk()->json('data');

        $this->assertEquals('PRM-IDLE', $data['promo']['kode_promo']);
        $this->assertCount(0, $data['top_products']);
        $this->assertCount(0, $data['top_customers']);
    }
}
