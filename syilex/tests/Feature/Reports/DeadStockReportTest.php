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

class DeadStockReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;
    protected int $terminalId;
    protected int $shiftId;
    protected int $warehouseId;
    protected int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['laporan.inventory', 'stok.view_hpp'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->viewer = User::factory()->create();
        // viewer: akses inventory + lihat HPP (avg_cost/stock_value muncul)
        $this->viewer->givePermissionTo(['laporan.inventory', 'stok.view_hpp']);

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
            'kode_terminal' => 'TRM-DS',
            'nama_terminal' => 'Kasir DeadStock',
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
            'kode_customer' => 'CUST-DS',
            'nama' => 'Walk-in',
            'telepon' => '08000',
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeProductWithStock(string $kode, float $qty, float $avgCost): MasterProduk
    {
        $product = MasterProduk::factory()->create([
            'kode_produk' => $kode,
            'avg_cost' => $avgCost,
            'harga_4' => $avgCost * 2,
            'status' => 'active',
        ]);

        if ($qty > 0) {
            // MasterProdukObserver auto-create inventory_stock row per warehouse — update instead of insert
            DB::table('inventory_stock')
                ->where('product_id', $product->id)
                ->where('warehouse_id', $this->warehouseId)
                ->update(['qty' => $qty, 'updated_at' => now()]);
        }
        return $product;
    }

    private function recordSale(MasterProduk $product, string $tanggal): void
    {
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('######'),
            'tanggal' => $tanggal,
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 1000, 'total_setelah_diskon' => 1000,
            'grand_total' => 1000, 'total_bayar' => 1000, 'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => $tanggal, 'updated_at' => $tanggal,
        ]);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId,
            'product_id' => $product->id,
            'unit' => 'PCS', 'konversi' => 1,
            'qty' => 1, 'qty_base' => 1,
            'harga_satuan' => 1000, 'diskon_total' => 0, 'jumlah' => 1000,
            'hpp_at_time' => 500,
        ]);
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertForbidden();
    }

    public function test_tanpa_view_hpp_field_nilai_disembunyikan(): void
    {
        // User punya akses inventory TAPI tidak punya stok.view_hpp:
        // avg_cost & stock_value harus di-strip dari tiap item; total_value null; flag false.
        Permission::firstOrCreate(['name' => 'laporan.inventory', 'guard_name' => 'web']);
        $noHpp = User::factory()->create();
        $noHpp->givePermissionTo('laporan.inventory');

        $product = $this->makeProductWithStock('NOHPP', 20, 1000);
        $this->recordSale($product, now()->subDays(100)->toDateTimeString());

        $data = $this->actingAs($noHpp)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk()->json('data');

        $this->assertFalse($data['can_view_hpp']);
        $this->assertNull($data['total_value']);
        $this->assertCount(1, $data['items']);
        $this->assertArrayNotHasKey('avg_cost', $data['items'][0]);
        $this->assertArrayNotHasKey('stock_value', $data['items'][0]);
        // Field non-sensitif tetap ada
        $this->assertEquals('NOHPP', $data['items'][0]['kode_produk']);
        $this->assertEquals(20, $data['items'][0]['stock_qty']);
    }

    public function test_detects_products_not_sold_in_period(): void
    {
        // Live product: sold 10 days ago (NOT dead under default 60)
        $live = $this->makeProductWithStock('LIVE', 50, 500);
        $this->recordSale($live, now()->subDays(10)->toDateTimeString());

        // Dead product: sold 90 days ago (DEAD under default 60)
        $dead = $this->makeProductWithStock('DEAD', 20, 400);
        $this->recordSale($dead, now()->subDays(90)->toDateTimeString());

        // Never sold product (also dead, has stock)
        $this->makeProductWithStock('NEVER', 10, 300);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('kode_produk');

        $this->assertTrue($items->has('DEAD'));
        $this->assertTrue($items->has('NEVER'));
        $this->assertFalse($items->has('LIVE'), 'LIVE product (sold 10 days ago) tidak boleh muncul');

        // NEVER sold → days_idle null, never_sold true
        $this->assertNull($items->get('NEVER')['days_idle']);
        $this->assertTrue($items->get('NEVER')['never_sold']);

        // DEAD → days_idle ~90
        $this->assertGreaterThanOrEqual(89, $items->get('DEAD')['days_idle']);
    }

    public function test_exclude_never_sold_when_flag_false(): void
    {
        $this->makeProductWithStock('NEVER', 10, 300);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock?include_never_sold=0')
            ->assertOk();

        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_min_days_idle_filter(): void
    {
        $product = $this->makeProductWithStock('P1', 10, 500);
        $this->recordSale($product, now()->subDays(40)->toDateTimeString());

        // Default cutoff 60 — product (40 days) tidak dead
        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk();
        $this->assertCount(0, $response->json('data.items'));

        // Cutoff 30 — product (40 days) dead
        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock?min_days_idle=30')
            ->assertOk();
        $this->assertCount(1, $response->json('data.items'));
    }

    public function test_stock_value_computed_correctly(): void
    {
        $product = $this->makeProductWithStock('VAL', 20, 1000); // 20 × 1000 = 20k
        $this->recordSale($product, now()->subDays(100)->toDateTimeString());

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertEquals(20, $items[0]['stock_qty']);
        $this->assertEquals(1000, $items[0]['avg_cost']);
        $this->assertEquals(20_000, $items[0]['stock_value']);

        $this->assertEquals(20_000, $response->json('data.total_value'));
    }

    public function test_min_stock_filter_excludes_zero_stock(): void
    {
        // Product without stock record (no inventory_stock row)
        MasterProduk::factory()->create(['kode_produk' => 'NO-STOCK', 'status' => 'active']);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk();

        // Default min_stock 0.01 — produk dengan qty 0 tidak muncul
        $this->assertCount(0, $response->json('data.items'));
    }

    public function test_sort_by_value_desc(): void
    {
        $big = $this->makeProductWithStock('BIG', 10, 5000); // 50k value
        $small = $this->makeProductWithStock('SML', 50, 100); // 5k value
        $this->recordSale($big, now()->subDays(100)->toDateTimeString());
        $this->recordSale($small, now()->subDays(100)->toDateTimeString());

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock?sort=value_desc')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertEquals('BIG', $items[0]['kode_produk']);
        $this->assertEquals('SML', $items[1]['kode_produk']);
    }

    public function test_voided_sales_not_counted_as_last_sold(): void
    {
        $product = $this->makeProductWithStock('P-VOID', 10, 500);

        // Voided recently — should NOT count as "last sold"
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-VOID-' . fake()->numerify('####'),
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 1000, 'total_setelah_diskon' => 1000, 'grand_total' => 1000,
            'total_bayar' => 1000, 'kembalian' => 0, 'total_biaya_pembayaran' => 0,
            'status' => 'voided',
            'created_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId,
            'product_id' => $product->id,
            'unit' => 'PCS', 'konversi' => 1,
            'qty' => 1, 'qty_base' => 1,
            'harga_satuan' => 1000, 'diskon_total' => 0, 'jumlah' => 1000,
            'hpp_at_time' => 500,
        ]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk();

        // Product muncul sebagai dead/never_sold karena voided tidak count
        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('P-VOID', $items[0]['kode_produk']);
        $this->assertTrue($items[0]['never_sold']);
    }

    // ─── Tambahan edge-case galak ────────────────────────────────────────

    public function test_data_kosong_struktur_tetap_valid(): void
    {
        // Boundary: tidak ada produk → items kosong, total 0.
        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk()->json('data');

        $this->assertSame([], $data['items']);
        $this->assertEquals(0, $data['total_products']);
        $this->assertEquals(0, $data['total_value']);
        $this->assertEquals(60, $data['cutoff_days']);
    }

    public function test_last_sold_pakai_tanggal_terbaru_max(): void
    {
        // Produk dijual 100 hari & 5 hari lalu → MAX(5 hari) → NOT dead (default 60).
        $product = $this->makeProductWithStock('RECENT', 10, 500);
        $this->recordSale($product, now()->subDays(100)->toDateTimeString());
        $this->recordSale($product, now()->subDays(5)->toDateTimeString());

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk()->json('data.items');

        $this->assertCount(0, $items, 'Penjualan terbaru (5 hari) harus mencegah produk dianggap dead');
    }

    public function test_stok_dijumlahkan_lintas_warehouse(): void
    {
        // Warehouse kedua + tambah qty di gudang itu → total qty = WH1 + WH2.
        $warehouse2 = MasterWarehouse::factory()->create(['created_by' => $this->viewer->id]);

        $product = $this->makeProductWithStock('MULTI-WH', 10, 1000); // WH1 = 10
        // Observer auto-create row WH2 saat produk dibuat → update qty WH2 jadi 15.
        DB::table('inventory_stock')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse2->id)
            ->update(['qty' => 15, 'updated_at' => now()]);
        $this->recordSale($product, now()->subDays(100)->toDateTimeString());

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals(25, $items[0]['stock_qty']); // 10 + 15
        $this->assertEquals(25_000, $items[0]['stock_value']); // 25 × 1000
    }

    public function test_warehouse_filter_membatasi_stok_satu_gudang(): void
    {
        $warehouse2 = MasterWarehouse::factory()->create(['created_by' => $this->viewer->id]);

        $product = $this->makeProductWithStock('WH-FILTER', 10, 1000); // WH1 = 10
        DB::table('inventory_stock')
            ->where('product_id', $product->id)
            ->where('warehouse_id', $warehouse2->id)
            ->update(['qty' => 15, 'updated_at' => now()]);
        $this->recordSale($product, now()->subDays(100)->toDateTimeString());

        // Filter ke WH1 saja → qty 10, value 10k.
        $items = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/inventory/dead-stock?warehouse_id={$this->warehouseId}")
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals(10, $items[0]['stock_qty']);
        $this->assertEquals(10_000, $items[0]['stock_value']);
    }

    public function test_total_value_sama_dengan_jumlah_stock_value_item(): void
    {
        $p1 = $this->makeProductWithStock('TV1', 10, 1000); // 10k
        $p2 = $this->makeProductWithStock('TV2', 5, 2000);  // 10k
        $this->recordSale($p1, now()->subDays(100)->toDateTimeString());
        $this->recordSale($p2, now()->subDays(100)->toDateTimeString());

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk()->json('data');

        $this->assertEquals(2, $data['total_products']);
        $this->assertEquals(20_000, $data['total_value']);
        $jumlahItem = collect($data['items'])->sum('stock_value');
        $this->assertEquals(20_000, $jumlahItem);
    }

    public function test_min_stock_nol_menyertakan_stok_kosong(): void
    {
        // min_stock=0 → produk tanpa stok ikut (karena guard `$minStock > 0` mati).
        MasterProduk::factory()->create(['kode_produk' => 'ZERO-STK', 'status' => 'active']);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock?min_stock=0')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('ZERO-STK', $items[0]['kode_produk']);
        $this->assertEquals(0, $items[0]['stock_qty']);
        $this->assertTrue($items[0]['never_sold']);
    }

    public function test_sort_qty_desc(): void
    {
        $banyak = $this->makeProductWithStock('BANYAK', 100, 100);
        $sedikit = $this->makeProductWithStock('SEDIKIT', 5, 5000);
        $this->recordSale($banyak, now()->subDays(100)->toDateTimeString());
        $this->recordSale($sedikit, now()->subDays(100)->toDateTimeString());

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock?sort=qty_desc')
            ->assertOk()->json('data.items');

        $this->assertEquals('BANYAK', $items[0]['kode_produk']); // qty 100
        $this->assertEquals('SEDIKIT', $items[1]['kode_produk']); // qty 5
    }

    public function test_kategori_filter_membatasi_produk(): void
    {
        $kategoriId = DB::table('master_kategori')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_kategori' => 'KAT-DS',
            'nama_kategori' => 'Kategori DS',
            'tipe_id' => DB::table('master_tipe')->insertGetId([
                'ulid' => (string) Str::ulid(), 'kode_tipe' => 'TIP-DS', 'nama_tipe' => 'Tipe DS',
                'status' => 'active', 'created_by' => $this->viewer->id, 'updated_by' => $this->viewer->id,
                'created_at' => now(), 'updated_at' => now(),
            ]),
            'status' => 'active', 'created_by' => $this->viewer->id, 'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $inCat = MasterProduk::factory()->create([
            'kode_produk' => 'IN-CAT', 'kategori_id' => $kategoriId, 'avg_cost' => 500, 'harga_4' => 1000, 'status' => 'active',
        ]);
        DB::table('inventory_stock')->where('product_id', $inCat->id)
            ->where('warehouse_id', $this->warehouseId)->update(['qty' => 10, 'updated_at' => now()]);
        $this->makeProductWithStock('NO-CAT', 10, 500); // kategori null

        $items = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/inventory/dead-stock?kategori_id={$kategoriId}")
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('IN-CAT', $items[0]['kode_produk']);
    }

    public function test_days_idle_eksak_pada_cutoff_kustom(): void
    {
        // Dijual 70 hari lalu, cutoff 60 → dead, days_idle = 70.
        $product = $this->makeProductWithStock('IDLE70', 10, 500);
        $this->recordSale($product, now()->subDays(70)->toDateTimeString());

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals(70, $items[0]['days_idle']);
        $this->assertFalse($items[0]['never_sold']);
    }

    public function test_limit_membatasi_jumlah_item(): void
    {
        foreach (range(1, 5) as $i) {
            $p = $this->makeProductWithStock("LIM-{$i}", 10, 500);
            $this->recordSale($p, now()->subDays(100)->toDateTimeString());
        }

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/inventory/dead-stock?limit=3')
            ->assertOk()->json('data.items');

        $this->assertCount(3, $items);
    }
}
