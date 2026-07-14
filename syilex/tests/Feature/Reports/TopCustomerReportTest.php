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

class TopCustomerReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;
    protected int $terminalId;
    protected int $shiftId;
    protected int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'laporan.performa', 'guard_name' => 'web']);
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo('laporan.performa');

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
            'kode_terminal' => 'TRM-TC',
            'nama_terminal' => 'Kasir TopCust',
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
    }

    private function makeCustomer(string $kode, string $nama): int
    {
        return DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => $kode,
            'nama' => $nama,
            'telepon' => '08000',
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeSale(int $customerId, float $grandTotal, float $qty = 5, string $status = 'completed', ?string $tanggal = null): int
    {
        $tanggal ??= now()->toDateTimeString();
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('######'),
            'tanggal' => $tanggal,
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $customerId,
            'subtotal' => $grandTotal, 'total_setelah_diskon' => $grandTotal,
            'grand_total' => $grandTotal, 'total_bayar' => $grandTotal, 'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => $status,
            'created_by' => $this->viewer->id,
            'created_at' => $tanggal, 'updated_at' => $tanggal,
        ]);

        $product = MasterProduk::factory()->create(['avg_cost' => 500, 'harga_4' => $grandTotal / $qty]);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId,
            'product_id' => $product->id,
            'unit' => 'PCS', 'konversi' => 1,
            'qty' => $qty, 'qty_base' => $qty,
            'harga_satuan' => $grandTotal / $qty,
            'diskon_total' => 0,
            'jumlah' => $grandTotal,
            'hpp_at_time' => 500,
        ]);
        return $salesId;
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/customer/top')
            ->assertForbidden();
    }

    public function test_ranks_customers_by_omzet_desc(): void
    {
        $big = $this->makeCustomer('BIG', 'Big Customer');
        $mid = $this->makeCustomer('MID', 'Mid');
        $small = $this->makeCustomer('SML', 'Small');

        // Big: 3 trx × 1jt = 3jt
        $this->makeSale($big, 1_000_000);
        $this->makeSale($big, 1_000_000);
        $this->makeSale($big, 1_000_000);
        // Mid: 2 trx × 500k = 1jt
        $this->makeSale($mid, 500_000);
        $this->makeSale($mid, 500_000);
        // Small: 1 trx × 100k
        $this->makeSale($small, 100_000);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(3, $items);

        $this->assertEquals(1, $items[0]['rank']);
        $this->assertEquals('BIG', $items[0]['kode_customer']);
        $this->assertEquals(3, $items[0]['trx_count']);
        $this->assertEquals(3_000_000, $items[0]['omzet']);
        $this->assertEquals(1_000_000, $items[0]['avg_per_trx']);

        $this->assertEquals('MID', $items[1]['kode_customer']);
        $this->assertEquals(1_000_000, $items[1]['omzet']);

        $this->assertEquals('SML', $items[2]['kode_customer']);
    }

    public function test_limit_caps_result(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            $c = $this->makeCustomer("C{$i}", "Cust {$i}");
            $this->makeSale($c, $i * 100_000);
        }

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top?limit=3')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(3, $items);
        // Top 3 by omzet: C10 (1jt), C9 (900k), C8 (800k)
        $this->assertEquals('C10', $items[0]['kode_customer']);
        $this->assertEquals('C9', $items[1]['kode_customer']);
        $this->assertEquals('C8', $items[2]['kode_customer']);
    }

    public function test_sort_by_trx_count(): void
    {
        $a = $this->makeCustomer('A', 'A');
        $b = $this->makeCustomer('B', 'B');

        // A: 1 trx besar (10jt)
        $this->makeSale($a, 10_000_000);
        // B: 5 trx kecil (total 1jt)
        for ($i = 0; $i < 5; $i++) $this->makeSale($b, 200_000);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top?sort=trx_desc')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertEquals('B', $items[0]['kode_customer']); // 5 trx > 1 trx
        $this->assertEquals('A', $items[1]['kode_customer']);
    }

    public function test_voided_sales_excluded(): void
    {
        $c = $this->makeCustomer('C', 'C');
        $this->makeSale($c, 100_000, status: 'voided');

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top')
            ->assertOk();

        $this->assertEmpty($response->json('data.items'));
    }

    public function test_last_trx_at_returned(): void
    {
        $c = $this->makeCustomer('C', 'C');
        $oldTanggal = now()->subDays(5)->toDateTimeString();
        $newTanggal = now()->toDateTimeString();

        $this->makeSale($c, 100_000, tanggal: $oldTanggal);
        $this->makeSale($c, 200_000, tanggal: $newTanggal);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertEquals(2, $items[0]['trx_count']);
        $this->assertStringStartsWith(now()->toDateString(), $items[0]['last_trx_at']);
    }

    // ─── EDGE CASES (galak) ──────────────────────────────────────────────

    private function makeTipeCustomer(string $kode, string $nama): int
    {
        return DB::table('master_tipe_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_tipe' => $kode,
            'nama_tipe' => $nama,
            'diskon_tipe' => 'none',
            'diskon_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeCustomerWithTipe(string $kode, string $nama, int $tipeId): int
    {
        return DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => $kode,
            'nama' => $nama,
            'telepon' => '08000',
            'tipe_customer_id' => $tipeId,
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Boundary kosong: tanpa transaksi → items kosong, struktur valid (period & limit ada).
     */
    public function test_kosong_struktur_valid(): void
    {
        $resp = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top')
            ->assertOk();

        $this->assertCount(0, $resp->json('data.items'));
        $this->assertArrayHasKey('period', $resp->json('data'));
        $this->assertEquals(50, $resp->json('data.limit')); // default limit
    }

    /**
     * Boundary tanggal: transaksi jam 23:30 di hari date_to ikut; sehari setelah date_to tidak.
     * Omzet & trx eksak hanya dari yang dalam rentang.
     */
    public function test_batas_rentang_tanggal_inklusif(): void
    {
        $c = $this->makeCustomer('DT', 'DateTest');

        $this->makeSale($c, 100_000, tanggal: '2026-06-10 00:00:00'); // awal hari from → ikut
        $this->makeSale($c, 200_000, tanggal: '2026-06-15 23:30:00'); // jam>0 hari to → ikut
        $this->makeSale($c, 999_000, tanggal: '2026-06-16 08:00:00'); // luar (setelah to)
        $this->makeSale($c, 888_000, tanggal: '2026-06-09 23:59:59'); // luar (sebelum from)

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top?date_from=2026-06-10&date_to=2026-06-15')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals(2, $items[0]['trx_count']);       // hanya 2 dalam rentang
        $this->assertEquals(300_000, $items[0]['omzet']);     // 100k + 200k
        $this->assertEquals(150_000, $items[0]['avg_per_trx']);
    }

    /**
     * sort=avg_desc: rank by rata-rata per transaksi, berbeda dari omzet/trx.
     */
    public function test_sort_avg_desc(): void
    {
        $hi = $this->makeCustomer('HI', 'HighAvg'); // 1 trx besar → avg 5jt
        $lo = $this->makeCustomer('LO', 'LowAvg');  // 5 trx kecil → avg 1jt (omzet 5jt)

        $this->makeSale($hi, 5_000_000);
        for ($i = 0; $i < 5; $i++) $this->makeSale($lo, 1_000_000);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top?sort=avg_desc')
            ->assertOk()->json('data.items');

        // avg HI = 5jt > avg LO = 1jt, meski omzet sama (5jt)
        $this->assertEquals('HI', $items[0]['kode_customer']);
        $this->assertEquals(5_000_000, $items[0]['avg_per_trx']);
        $this->assertEquals('LO', $items[1]['kode_customer']);
        $this->assertEquals(1_000_000, $items[1]['avg_per_trx']);
    }

    /**
     * sort=last_desc: rank by transaksi terbaru.
     */
    public function test_sort_last_desc(): void
    {
        $old = $this->makeCustomer('OLD', 'Old');
        $recent = $this->makeCustomer('NEW', 'Recent');

        $this->makeSale($old, 1_000_000, tanggal: now()->subDays(10)->toDateTimeString());
        $this->makeSale($recent, 100_000, tanggal: now()->subDay()->toDateTimeString());

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top?date_from=' . now()->subDays(30)->toDateString()
                . '&date_to=' . now()->toDateString() . '&sort=last_desc')
            ->assertOk()->json('data.items');

        // NEW transaksi lebih baru → rank 1 walau omzet lebih kecil
        $this->assertEquals('NEW', $items[0]['kode_customer']);
        $this->assertEquals('OLD', $items[1]['kode_customer']);
    }

    /**
     * Filter tipe_customer_id: hanya customer tipe tsb yang masuk ranking.
     */
    public function test_filter_tipe_customer_id(): void
    {
        $tipeA = $this->makeTipeCustomer('TA', 'Tipe A');
        $tipeB = $this->makeTipeCustomer('TB', 'Tipe B');

        $cA = $this->makeCustomerWithTipe('CA', 'Cust A', $tipeA);
        $cB = $this->makeCustomerWithTipe('CB', 'Cust B', $tipeB);

        $this->makeSale($cA, 500_000);
        $this->makeSale($cB, 999_000);

        $items = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/customer/top?tipe_customer_id={$tipeA}")
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('CA', $items[0]['kode_customer']);
        $this->assertEquals('Tipe A', $items[0]['tipe']);
    }

    /**
     * qty_total dijumlahkan eksak per customer dari semua transaksi dalam rentang.
     */
    public function test_qty_total_dijumlahkan_eksak(): void
    {
        $c = $this->makeCustomer('Q', 'QtyCust');

        // makeSale($c, grand, qty) — 2 trx: qty 5 + qty 3 = 8
        $this->makeSale($c, 500_000, qty: 5);
        $this->makeSale($c, 300_000, qty: 3);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top')
            ->assertOk()->json('data.items');

        $this->assertEquals(2, $items[0]['trx_count']);
        $this->assertEquals(8, $items[0]['qty_total']);
        $this->assertEquals(800_000, $items[0]['omzet']);
    }

    /**
     * rank diberikan berurutan 1,2,3 sesuai sort; tidak ada gap/duplikat.
     */
    public function test_rank_berurutan(): void
    {
        $a = $this->makeCustomer('A', 'A');
        $b = $this->makeCustomer('B', 'B');
        $cc = $this->makeCustomer('C', 'C');

        $this->makeSale($a, 3_000_000);
        $this->makeSale($b, 2_000_000);
        $this->makeSale($cc, 1_000_000);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top')
            ->assertOk()->json('data.items');

        $this->assertEquals([1, 2, 3], collect($items)->pluck('rank')->all());
        $this->assertEquals(['A', 'B', 'C'], collect($items)->pluck('kode_customer')->all());
    }

    /**
     * avg_per_trx dibulatkan 2 desimal. 100.000 / 3 trx = 33333.33.
     */
    public function test_avg_per_trx_pembulatan(): void
    {
        $c = $this->makeCustomer('AVG', 'AvgRound');

        // 3 transaksi total 100.000 (qty bebas)
        $this->makeSale($c, 40_000);
        $this->makeSale($c, 30_000);
        $this->makeSale($c, 30_000);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top')
            ->assertOk()->json('data.items');

        $this->assertEquals(3, $items[0]['trx_count']);
        $this->assertEquals(100_000, $items[0]['omzet']);
        $this->assertEquals(33333.33, $items[0]['avg_per_trx']); // round(100000/3, 2)
    }

    /**
     * Sale completed dan voided pada customer sama: hanya completed yang dihitung.
     */
    public function test_voided_dikecualikan_walau_ada_completed(): void
    {
        $c = $this->makeCustomer('MIX', 'Mixed');

        $this->makeSale($c, 500_000);                       // completed
        $this->makeSale($c, 999_000, status: 'voided');     // voided → diabaikan

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals(1, $items[0]['trx_count']);
        $this->assertEquals(500_000, $items[0]['omzet']);
    }

    /**
     * limit=1 mengambil hanya peringkat teratas (omzet terbesar).
     */
    public function test_limit_satu_ambil_teratas(): void
    {
        $a = $this->makeCustomer('A', 'A');
        $b = $this->makeCustomer('B', 'B');

        $this->makeSale($a, 1_000_000);
        $this->makeSale($b, 5_000_000);

        $resp = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer/top?limit=1')
            ->assertOk();

        $items = $resp->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('B', $items[0]['kode_customer']); // omzet terbesar
        $this->assertEquals(1, $resp->json('data.limit'));
    }
}
