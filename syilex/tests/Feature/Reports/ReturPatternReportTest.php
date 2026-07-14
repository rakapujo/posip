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

class ReturPatternReportTest extends TestCase
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
        Permission::firstOrCreate(['name' => 'laporan.inventory', 'guard_name' => 'web']);
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo('laporan.inventory');

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
            'kode_terminal' => 'TRM-RP',
            'nama_terminal' => 'Kasir Retur',
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
            'kode_customer' => 'CUST-RP',
            'nama' => 'Walk-in',
            'telepon' => '08000',
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeSaleWithReturn(MasterProduk $product, float $saleQty, float $returnQty, float $harga): void
    {
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('######'),
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => $saleQty * $harga,
            'total_setelah_diskon' => $saleQty * $harga,
            'grand_total' => $saleQty * $harga,
            'total_bayar' => $saleQty * $harga, 'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $salesDetailId = DB::table('doc_sales_detail')->insertGetId([
            'sales_id' => $salesId,
            'product_id' => $product->id,
            'unit' => 'PCS', 'konversi' => 1,
            'qty' => $saleQty, 'qty_base' => $saleQty,
            'harga_satuan' => $harga,
            'diskon_total' => 0,
            'jumlah' => $saleQty * $harga,
            'hpp_at_time' => 500,
        ]);

        if ($returnQty > 0) {
            $returnId = DB::table('doc_sales_returns')->insertGetId([
                'ulid' => (string) Str::ulid(),
                'nomor_dokumen' => 'RTR-' . fake()->unique()->numerify('######'),
                'tanggal' => now()->toDateTimeString(),
                'sales_id' => $salesId,
                'terminal_id' => $this->terminalId,
                'shift_id' => $this->shiftId,
                'warehouse_id' => $this->warehouseId,
                'customer_id' => $this->customerId,
                'refund_method' => 'cash',
                'grand_total' => $returnQty * $harga,
                'created_by' => $this->viewer->id,
                'created_at' => now(), 'updated_at' => now(),
            ]);

            DB::table('doc_sales_return_detail')->insert([
                'return_id' => $returnId,
                'sales_detail_id' => $salesDetailId,
                'product_id' => $product->id,
                'unit' => 'PCS', 'konversi' => 1,
                'qty' => $returnQty, 'qty_base' => $returnQty,
                'harga_satuan' => $harga,
                'jumlah' => $returnQty * $harga,
                'hpp_at_time' => 500,
            ]);
        }
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/retur/pattern')
            ->assertForbidden();
    }

    public function test_aggregates_per_product(): void
    {
        $bad = MasterProduk::factory()->create(['kode_produk' => 'BAD-1', 'avg_cost' => 500, 'harga_4' => 1000]);
        $ok = MasterProduk::factory()->create(['kode_produk' => 'OK-1', 'avg_cost' => 500, 'harga_4' => 2000]);

        // Bad: 3 retur (10 qty total, 10k nominal)
        $this->makeSaleWithReturn($bad, 10, 5, 1000);
        $this->makeSaleWithReturn($bad, 10, 3, 1000);
        $this->makeSaleWithReturn($bad, 10, 2, 1000);
        // Ok: 1 retur
        $this->makeSaleWithReturn($ok, 5, 1, 2000);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern')
            ->assertOk();

        $data = $response->json('data');
        $items = collect($data['items'])->keyBy('kode_produk');

        // BAD-1 di atas karena retur_count paling tinggi (3)
        $this->assertEquals('BAD-1', $data['items'][0]['kode_produk']);
        $this->assertEquals(3, $items->get('BAD-1')['retur_count']);
        $this->assertEquals(10, $items->get('BAD-1')['qty_total']);
        $this->assertEquals(10_000, $items->get('BAD-1')['nominal_total']);

        $this->assertEquals(1, $items->get('OK-1')['retur_count']);
        $this->assertEquals(1, $items->get('OK-1')['qty_total']);
    }

    public function test_summary_computes_retur_rate(): void
    {
        $product = MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 1000]);

        // 100 unit dijual, 10 diretur → rate 10%
        $this->makeSaleWithReturn($product, 100, 10, 1000);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern')
            ->assertOk();

        $summary = $response->json('data.summary');
        $this->assertEquals(1, $summary['retur_count']);
        $this->assertEquals(10, $summary['qty_total']);
        $this->assertEquals(10_000, $summary['nominal_total']);
        $this->assertEquals(100, $summary['sales_qty_total']);
        $this->assertEquals(10.0, $summary['retur_rate_percent']);
    }

    public function test_sort_by_qty(): void
    {
        $a = MasterProduk::factory()->create(['kode_produk' => 'A', 'avg_cost' => 500, 'harga_4' => 1000]);
        $b = MasterProduk::factory()->create(['kode_produk' => 'B', 'avg_cost' => 500, 'harga_4' => 1000]);

        // A: retur 3× dengan qty kecil (2 per trx = 6)
        $this->makeSaleWithReturn($a, 5, 2, 1000);
        $this->makeSaleWithReturn($a, 5, 2, 1000);
        $this->makeSaleWithReturn($a, 5, 2, 1000);
        // B: retur 1× tapi qty besar (50)
        $this->makeSaleWithReturn($b, 100, 50, 1000);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern?sort=qty_desc')
            ->assertOk();

        $items = $response->json('data.items');
        // B first (50 qty) > A (6 qty)
        $this->assertEquals('B', $items[0]['kode_produk']);
        $this->assertEquals(50, $items[0]['qty_total']);
    }

    public function test_limit_caps_results(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $p = MasterProduk::factory()->create(['kode_produk' => "P{$i}", 'avg_cost' => 500, 'harga_4' => 1000]);
            $this->makeSaleWithReturn($p, 10, 1, 1000);
        }

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern?limit=3')
            ->assertOk();

        $this->assertCount(3, $response->json('data.items'));
    }

    // ─── EDGE CASES (galak) ──────────────────────────────────────────────

    /**
     * Helper: buat retur dengan tanggal & unit/konversi/qty_base spesifik.
     * Memungkinkan qty != qty_base untuk menguji rumus nominal (harga × qty) vs qty_total (qty_base).
     */
    private function makeReturRaw(MasterProduk $product, array $opts): void
    {
        $tanggal = $opts['tanggal'] ?? now()->toDateTimeString();
        $unit = $opts['unit'] ?? 'PCS';
        $konversi = $opts['konversi'] ?? 1;
        $qty = $opts['qty'];
        $qtyBase = $opts['qty_base'] ?? $qty;
        $harga = $opts['harga'];

        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('######'),
            'tanggal' => $tanggal,
            'terminal_id' => $opts['terminal_id'] ?? $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => $qty * $harga, 'total_setelah_diskon' => $qty * $harga,
            'grand_total' => $qty * $harga, 'total_bayar' => $qty * $harga, 'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => $tanggal, 'updated_at' => $tanggal,
        ]);

        $salesDetailId = DB::table('doc_sales_detail')->insertGetId([
            'sales_id' => $salesId, 'product_id' => $product->id,
            'unit' => $unit, 'konversi' => $konversi,
            'qty' => $qty, 'qty_base' => $qtyBase,
            'harga_satuan' => $harga, 'diskon_total' => 0,
            'jumlah' => $qty * $harga, 'hpp_at_time' => 500,
        ]);

        $returnId = DB::table('doc_sales_returns')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-' . fake()->unique()->numerify('######'),
            'tanggal' => $tanggal,
            'sales_id' => $salesId,
            'terminal_id' => $opts['terminal_id'] ?? $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'refund_method' => 'cash',
            'grand_total' => $qty * $harga,
            'created_by' => $this->viewer->id,
            'created_at' => $tanggal, 'updated_at' => $tanggal,
        ]);

        DB::table('doc_sales_return_detail')->insert([
            'return_id' => $returnId, 'sales_detail_id' => $salesDetailId,
            'product_id' => $product->id,
            'unit' => $unit, 'konversi' => $konversi,
            'qty' => $qty, 'qty_base' => $qtyBase,
            'harga_satuan' => $harga,
            'jumlah' => $qty * $harga, 'hpp_at_time' => 500,
        ]);
    }

    /**
     * Boundary kosong: tanpa retur → items kosong, summary semua 0, rate 0 (tak bagi-nol).
     */
    public function test_kosong_summary_nol_dan_rate_aman(): void
    {
        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern')
            ->assertOk()->json('data');

        $this->assertCount(0, $data['items']);
        $this->assertEquals(0, $data['summary']['retur_count']);
        $this->assertEquals(0.0, $data['summary']['qty_total']);
        $this->assertEquals(0.0, $data['summary']['nominal_total']);
        $this->assertEquals(0.0, $data['summary']['sales_qty_total']);
        $this->assertEquals(0, $data['summary']['retur_rate_percent']); // tak bagi-nol
    }

    /**
     * Ada penjualan tapi NOL retur → rate 0 walau sales_qty > 0.
     */
    public function test_ada_sales_tanpa_retur_rate_nol(): void
    {
        $product = MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 1000]);
        // saleQty 50, returnQty 0 → tidak buat retur sama sekali
        $this->makeSaleWithReturn($product, 50, 0, 1000);

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern')
            ->assertOk()->json('data');

        $this->assertCount(0, $data['items']);
        $this->assertEquals(0, $data['summary']['retur_count']);
        $this->assertEquals(0, $data['summary']['retur_rate_percent']);
        // sales_qty_total tetap menghitung penjualan
        $this->assertEquals(50, $data['summary']['sales_qty_total']);
    }

    /**
     * Rumus nominal_total memakai (harga_satuan × qty), bukan qty_base.
     * qty_total memakai qty_base. Uji eksak ketika qty != qty_base.
     */
    public function test_nominal_pakai_qty_sedangkan_qty_total_pakai_qty_base(): void
    {
        $product = MasterProduk::factory()->create(['kode_produk' => 'CONV', 'avg_cost' => 500, 'harga_4' => 1000]);

        // Retur 2 BOX, konversi 6 → qty_base 12. harga_satuan 9000 (per BOX).
        $this->makeReturRaw($product, [
            'unit' => 'BOX', 'konversi' => 6,
            'qty' => 2, 'qty_base' => 12,
            'harga' => 9000,
        ]);

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern')
            ->assertOk()->json('data');

        $item = collect($data['items'])->firstWhere('kode_produk', 'CONV');
        // qty_total = qty_base = 12
        $this->assertEquals(12, $item['qty_total']);
        // nominal_total = harga_satuan × qty = 9000 × 2 = 18000 (BUKAN 9000 × 12)
        $this->assertEquals(18000, $item['nominal_total']);

        $this->assertEquals(12, $data['summary']['qty_total']);
        $this->assertEquals(18000, $data['summary']['nominal_total']);
    }

    /**
     * retur_count = DISTINCT return_id. Satu retur dengan 2 baris detail produk sama
     * tetap dihitung 1 retur (bukan 2), tapi qty_total menjumlahkan keduanya.
     */
    public function test_retur_count_distinct_return_id(): void
    {
        $product = MasterProduk::factory()->create(['kode_produk' => 'MULTI', 'avg_cost' => 500, 'harga_4' => 1000]);

        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-MULTI',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId, 'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId, 'customer_id' => $this->customerId,
            'subtotal' => 10000, 'total_setelah_diskon' => 10000,
            'grand_total' => 10000, 'total_bayar' => 10000, 'kembalian' => 0,
            'total_biaya_pembayaran' => 0, 'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $sd = DB::table('doc_sales_detail')->insertGetId([
            'sales_id' => $salesId, 'product_id' => $product->id,
            'unit' => 'PCS', 'konversi' => 1, 'qty' => 10, 'qty_base' => 10,
            'harga_satuan' => 1000, 'diskon_total' => 0, 'jumlah' => 10000, 'hpp_at_time' => 500,
        ]);

        $returnId = DB::table('doc_sales_returns')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-MULTI', 'tanggal' => now()->toDateTimeString(),
            'sales_id' => $salesId, 'terminal_id' => $this->terminalId, 'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId, 'customer_id' => $this->customerId,
            'refund_method' => 'cash', 'grand_total' => 3000,
            'created_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        // 2 baris detail di retur YANG SAMA, produk sama
        DB::table('doc_sales_return_detail')->insert([
            ['return_id' => $returnId, 'sales_detail_id' => $sd, 'product_id' => $product->id,
             'unit' => 'PCS', 'konversi' => 1, 'qty' => 2, 'qty_base' => 2,
             'harga_satuan' => 1000, 'jumlah' => 2000, 'hpp_at_time' => 500],
            ['return_id' => $returnId, 'sales_detail_id' => $sd, 'product_id' => $product->id,
             'unit' => 'PCS', 'konversi' => 1, 'qty' => 1, 'qty_base' => 1,
             'harga_satuan' => 1000, 'jumlah' => 1000, 'hpp_at_time' => 500],
        ]);

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern')
            ->assertOk()->json('data');

        $item = collect($data['items'])->firstWhere('kode_produk', 'MULTI');
        $this->assertEquals(1, $item['retur_count']);   // distinct return_id = 1
        $this->assertEquals(3, $item['qty_total']);      // 2 + 1
        $this->assertEquals(3000, $item['nominal_total']); // 1000×2 + 1000×1
        $this->assertEquals(1, $data['summary']['retur_count']);
    }

    /**
     * Boundary tanggal: retur jam 23:30 di hari date_to ikut; retur sehari setelah date_to tidak.
     */
    public function test_batas_rentang_tanggal_inklusif(): void
    {
        $product = MasterProduk::factory()->create(['kode_produk' => 'DT', 'avg_cost' => 500, 'harga_4' => 1000]);

        $this->makeReturRaw($product, ['tanggal' => '2026-06-10 00:00:00', 'qty' => 1, 'harga' => 1000]);
        $this->makeReturRaw($product, ['tanggal' => '2026-06-15 23:30:00', 'qty' => 2, 'harga' => 1000]);
        $this->makeReturRaw($product, ['tanggal' => '2026-06-16 08:00:00', 'qty' => 99, 'harga' => 1000]); // luar
        $this->makeReturRaw($product, ['tanggal' => '2026-06-09 23:59:59', 'qty' => 88, 'harga' => 1000]); // luar

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern?date_from=2026-06-10&date_to=2026-06-15')
            ->assertOk()->json('data');

        // Hanya 2 retur dalam rentang: qty 1 + 2 = 3
        $this->assertEquals(2, $data['summary']['retur_count']);
        $this->assertEquals(3, $data['summary']['qty_total']);
        $this->assertEquals('2026-06-10', $data['period']['from']);
        $this->assertEquals('2026-06-15', $data['period']['to']);
    }

    /**
     * sort=nominal_desc: urut berdasar nominal (harga × qty), bisa berbeda dari urutan count/qty.
     */
    public function test_sort_nominal_desc(): void
    {
        $cheap = MasterProduk::factory()->create(['kode_produk' => 'CHEAP', 'avg_cost' => 500, 'harga_4' => 100]);
        $pricey = MasterProduk::factory()->create(['kode_produk' => 'PRICEY', 'avg_cost' => 500, 'harga_4' => 50000]);

        // CHEAP: 3 retur tapi murah → count tinggi, nominal rendah (3 × 100 = 300)
        $this->makeSaleWithReturn($cheap, 10, 1, 100);
        $this->makeSaleWithReturn($cheap, 10, 1, 100);
        $this->makeSaleWithReturn($cheap, 10, 1, 100);
        // PRICEY: 1 retur tapi mahal → nominal tinggi (1 × 50000 = 50000)
        $this->makeSaleWithReturn($pricey, 10, 1, 50000);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern?sort=nominal_desc')
            ->assertOk()->json('data.items');

        $this->assertEquals('PRICEY', $items[0]['kode_produk']); // 50000 > 300
        $this->assertEquals(50000, $items[0]['nominal_total']);
        $this->assertEquals('CHEAP', $items[1]['kode_produk']);
    }

    /**
     * Filter kategori_id: hanya retur produk pada kategori tsb, dan rate dihitung
     * relatif terhadap penjualan kategori tsb saja.
     */
    public function test_filter_kategori_id(): void
    {
        // Buat kategori + produk di dalamnya
        $tipeId = DB::table('master_tipe')->insertGetId([
            'ulid' => (string) Str::ulid(), 'kode_tipe' => 'TP-RP', 'nama_tipe' => 'Tipe',
            'status' => 'active', 'created_by' => $this->viewer->id, 'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $katId = DB::table('master_kategori')->insertGetId([
            'ulid' => (string) Str::ulid(), 'kode_kategori' => 'KT-RP', 'nama_kategori' => 'Kat RP',
            'tipe_id' => $tipeId, 'status' => 'active', 'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $inKat = MasterProduk::factory()->create(['kode_produk' => 'INKAT', 'kategori_id' => $katId, 'avg_cost' => 500, 'harga_4' => 1000]);
        $outKat = MasterProduk::factory()->create(['kode_produk' => 'OUTKAT', 'kategori_id' => null, 'avg_cost' => 500, 'harga_4' => 1000]);

        $this->makeSaleWithReturn($inKat, 20, 5, 1000);  // dalam kategori
        $this->makeSaleWithReturn($outKat, 30, 10, 1000); // luar kategori

        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/retur/pattern?kategori_id={$katId}")
            ->assertOk()->json('data');

        $this->assertCount(1, $data['items']);
        $this->assertEquals('INKAT', $data['items'][0]['kode_produk']);
        $this->assertEquals(5, $data['summary']['qty_total']);
        $this->assertEquals(20, $data['summary']['sales_qty_total']); // hanya penjualan kategori
        $this->assertEquals(25.0, $data['summary']['retur_rate_percent']); // 5/20 × 100
    }

    /**
     * retur_rate dibulatkan 2 desimal (round). 1 retur dari 3 sales = 33.33%.
     */
    public function test_retur_rate_pembulatan_dua_desimal(): void
    {
        $product = MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => 1000]);
        // 3 sales, 1 retur → 33.333...% → 33.33
        $this->makeSaleWithReturn($product, 3, 1, 1000);

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/retur/pattern')
            ->assertOk()->json('data');

        $this->assertEquals(33.33, $data['summary']['retur_rate_percent']);
    }
}
