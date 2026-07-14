<?php

namespace Tests\Feature\Reports;

use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterWarehouse;
use App\Models\PosTerminalShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class KasirPerformanceReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;
    protected User $kasirA;
    protected User $kasirB;
    protected int $terminalId;
    protected int $cashMethodId;
    protected int $customerId;
    protected int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'laporan.performa', 'guard_name' => 'web']);

        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo('laporan.performa');

        $this->kasirA = User::factory()->create(['name' => 'Kasir A']);
        $this->kasirB = User::factory()->create(['name' => 'Kasir B']);

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
        $this->cashMethodId = $cash->id;

        $terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-KP',
            'nama_terminal' => 'Kasir Performance',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $this->kasirA->id,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);
        $this->terminalId = $terminal->id;

        $this->customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-KP',
            'nama' => 'Walk-in',
            'telepon' => '08000',
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeShift(int $userId, bool $force = false): int
    {
        $shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminalId,
            'user_id' => $userId,
            'started_at' => now(),
            'ended_at' => now(),
            'ended_by_force' => $force,
            'selisih' => $force ? -10_000 : 0,
        ]);
        return $shift->id;
    }

    private function makeSale(int $userId, int $shiftId, float $grandTotal, float $totalDiskon, string $status = 'completed'): int
    {
        return DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('######'),
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => $grandTotal + $totalDiskon,
            'total_setelah_diskon' => $grandTotal,
            'total_diskon' => $totalDiskon,
            'grand_total' => $grandTotal,
            'total_bayar' => $grandTotal,
            'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => $status,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeRetur(int $userId, int $salesId, int $shiftId, float $grandTotal): int
    {
        return DB::table('doc_sales_returns')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-' . fake()->unique()->numerify('######'),
            'tanggal' => now()->toDateTimeString(),
            'sales_id' => $salesId,
            'terminal_id' => $this->terminalId,
            'shift_id' => $shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'refund_method' => 'cash',
            'grand_total' => $grandTotal,
            'created_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/kasir-performance')
            ->assertForbidden();
    }

    public function test_aggregates_per_kasir(): void
    {
        $shiftA = $this->makeShift($this->kasirA->id);
        $shiftB = $this->makeShift($this->kasirB->id);

        // Kasir A: 3 completed, 1 voided, diskon_total 5000 per completed
        $this->makeSale($this->kasirA->id, $shiftA, 100_000, 5_000);
        $this->makeSale($this->kasirA->id, $shiftA, 150_000, 5_000);
        $salesAVoid = $this->makeSale($this->kasirA->id, $shiftA, 50_000, 5_000, 'voided');
        $salesAForRetur = $this->makeSale($this->kasirA->id, $shiftA, 80_000, 0);
        $this->makeRetur($this->kasirA->id, $salesAForRetur, $shiftA, 20_000);

        // Kasir B: 1 completed
        $this->makeSale($this->kasirB->id, $shiftB, 50_000, 0);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/kasir-performance?sort=omzet_desc')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('user_id');

        // Kasir A aggregate
        $a = $items->get($this->kasirA->id);
        $this->assertEquals(3, $a['trx_completed']);
        $this->assertEquals(1, $a['trx_voided']);
        $this->assertEquals(330_000, $a['omzet']); // 100 + 150 + 80
        $this->assertEquals(110_000, $a['avg_per_trx']); // 330/3
        $this->assertEquals(10_000, $a['diskon_total']); // 5+5 (voided excluded)
        $this->assertEquals(1, $a['retur_count']);
        $this->assertEquals(20_000, $a['retur_nominal']);
        $this->assertEquals(1, $a['shift_total']);
        $this->assertEquals(0, $a['shift_paksa']);

        // Kasir B
        $b = $items->get($this->kasirB->id);
        $this->assertEquals(1, $b['trx_completed']);
        $this->assertEquals(50_000, $b['omzet']);
        $this->assertEquals(50_000, $b['avg_per_trx']);

        // Sort DESC: Kasir A (330k) harus sebelum Kasir B (50k)
        $list = collect($response->json('data.items'));
        $this->assertEquals($this->kasirA->id, $list[0]['user_id']);
        $this->assertEquals($this->kasirB->id, $list[1]['user_id']);
    }

    public function test_shift_paksa_tracked(): void
    {
        $shiftA = $this->makeShift($this->kasirA->id, force: false);
        $shiftB = $this->makeShift($this->kasirA->id, force: true);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/kasir-performance')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('user_id');
        $a = $items->get($this->kasirA->id);
        $this->assertEquals(2, $a['shift_total']);
        $this->assertEquals(1, $a['shift_paksa']);
        $this->assertEquals(-10_000, $a['shift_selisih']);
    }

    public function test_filter_by_user_id(): void
    {
        $shiftA = $this->makeShift($this->kasirA->id);
        $shiftB = $this->makeShift($this->kasirB->id);

        $this->makeSale($this->kasirA->id, $shiftA, 100_000, 0);
        $this->makeSale($this->kasirB->id, $shiftB, 50_000, 0);

        $response = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/kasir-performance?user_id={$this->kasirA->id}")
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals($this->kasirA->id, $items[0]['user_id']);
    }

    public function test_empty_when_no_data(): void
    {
        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/kasir-performance')
            ->assertOk();

        $this->assertEquals([], $response->json('data.items'));
    }

    // ─── Tambahan edge-case galak ────────────────────────────────────────

    public function test_diskon_total_gabung_nota_dan_line(): void
    {
        // Sale dengan total_diskon (nota) 5000 + line discount 2000 = 7000.
        $shiftA = $this->makeShift($this->kasirA->id);
        $salesId = $this->makeSale($this->kasirA->id, $shiftA, 100_000, 5_000);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId,
            'product_id' => $this->makeProduk(),
            'unit' => 'PCS', 'konversi' => 1,
            'qty' => 1, 'qty_base' => 1,
            'harga_satuan' => 100_000, 'diskon_total' => 2_000, 'jumlah' => 98_000,
            'hpp_at_time' => 0,
        ]);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/kasir-performance')
                ->assertOk()->json('data.items')
        )->keyBy('user_id');

        $this->assertEquals(7_000, $items->get($this->kasirA->id)['diskon_total']);
    }

    public function test_avg_per_trx_nol_saat_hanya_ada_void(): void
    {
        // Kasir hanya punya transaksi voided → trx_completed 0, avg_per_trx 0 (pembagian nol aman).
        $shiftA = $this->makeShift($this->kasirA->id);
        $this->makeSale($this->kasirA->id, $shiftA, 50_000, 0, 'voided');

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/kasir-performance')
                ->assertOk()->json('data.items')
        )->keyBy('user_id');

        $a = $items->get($this->kasirA->id);
        $this->assertEquals(0, $a['trx_completed']);
        $this->assertEquals(1, $a['trx_voided']);
        $this->assertEquals(0, $a['omzet']);
        $this->assertEquals(0, $a['avg_per_trx']);
    }

    public function test_retur_diatribusikan_ke_creator_retur_bukan_creator_sale(): void
    {
        // Sale dibuat Kasir A, retur dibuat Kasir B → retur muncul di Kasir B.
        $shiftA = $this->makeShift($this->kasirA->id);
        $salesId = $this->makeSale($this->kasirA->id, $shiftA, 80_000, 0);
        $this->makeRetur($this->kasirB->id, $salesId, $shiftA, 25_000);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/kasir-performance')
                ->assertOk()->json('data.items')
        )->keyBy('user_id');

        // Kasir A: punya sale, retur_count 0.
        $this->assertEquals(0, $items->get($this->kasirA->id)['retur_count']);
        // Kasir B: muncul lewat merge (cuma retur), retur_count 1, omzet 0.
        $b = $items->get($this->kasirB->id);
        $this->assertEquals(1, $b['retur_count']);
        $this->assertEquals(25_000, $b['retur_nominal']);
        $this->assertEquals(0, $b['omzet']);
        $this->assertEquals(0, $b['trx_completed']);
        $this->assertEquals($this->kasirB->name, $b['user_name']);
    }

    public function test_batas_atas_tanggal_inklusif_jam_2350_ikut(): void
    {
        $shiftA = $this->makeShift($this->kasirA->id);
        $salesId = $this->makeSale($this->kasirA->id, $shiftA, 40_000, 0);
        DB::table('doc_sales')->where('id', $salesId)
            ->update(['tanggal' => now()->setTime(23, 50, 0)->toDateTimeString()]);

        $hariIni = now()->toDateString();
        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson("/api/v1/reports/kasir-performance?date_from={$hariIni}&date_to={$hariIni}")
                ->assertOk()->json('data.items')
        )->keyBy('user_id');

        $this->assertEquals(40_000, $items->get($this->kasirA->id)['omzet']);
        $this->assertEquals(1, $items->get($this->kasirA->id)['trx_completed']);
    }

    public function test_record_di_luar_rentang_tanggal_dibuang(): void
    {
        $shiftA = $this->makeShift($this->kasirA->id);
        $salesId = $this->makeSale($this->kasirA->id, $shiftA, 60_000, 0);
        DB::table('doc_sales')->where('id', $salesId)
            ->update(['tanggal' => now()->addDay()->setTime(9, 0, 0)->toDateTimeString()]);

        $hariIni = now()->toDateString();
        $items = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/kasir-performance?date_from={$hariIni}&date_to={$hariIni}")
            ->assertOk()->json('data.items');

        // Sale di luar rentang; shift masih di hari ini → kasir muncul tapi omzet 0.
        $byUser = collect($items)->keyBy('user_id');
        $a = $byUser->get($this->kasirA->id);
        $this->assertNotNull($a);
        $this->assertEquals(0, $a['omzet']);
        $this->assertEquals(0, $a['trx_completed']);
    }

    public function test_sort_void_desc(): void
    {
        $shiftA = $this->makeShift($this->kasirA->id);
        $shiftB = $this->makeShift($this->kasirB->id);

        // Kasir A: 2 void; Kasir B: omzet besar tapi 0 void.
        $this->makeSale($this->kasirA->id, $shiftA, 10_000, 0, 'voided');
        $this->makeSale($this->kasirA->id, $shiftA, 10_000, 0, 'voided');
        $this->makeSale($this->kasirB->id, $shiftB, 500_000, 0);

        $list = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/kasir-performance?sort=void_desc')
            ->assertOk()->json('data.items');

        // Kasir A (2 void) harus di atas walau omzet 0.
        $this->assertEquals($this->kasirA->id, $list[0]['user_id']);
        $this->assertEquals(2, $list[0]['trx_voided']);
    }

    public function test_sort_retur_desc(): void
    {
        $shiftA = $this->makeShift($this->kasirA->id);
        $shiftB = $this->makeShift($this->kasirB->id);
        $saleA = $this->makeSale($this->kasirA->id, $shiftA, 100_000, 0);
        $saleB = $this->makeSale($this->kasirB->id, $shiftB, 100_000, 0);

        // Kasir B: 2 retur; Kasir A: 1 retur.
        $this->makeRetur($this->kasirA->id, $saleA, $shiftA, 5_000);
        $this->makeRetur($this->kasirB->id, $saleB, $shiftB, 5_000);
        $this->makeRetur($this->kasirB->id, $saleB, $shiftB, 5_000);

        $list = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/kasir-performance?sort=retur_desc')
            ->assertOk()->json('data.items');

        $this->assertEquals($this->kasirB->id, $list[0]['user_id']);
        $this->assertEquals(2, $list[0]['retur_count']);
    }

    public function test_shift_di_luar_rentang_tanggal_tidak_dihitung(): void
    {
        // Shift mulai bulan lalu → tidak masuk filter bulan ini.
        $oldShift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminalId,
            'user_id' => $this->kasirA->id,
            'started_at' => now()->subMonths(2),
            'ended_at' => now()->subMonths(2),
            'ended_by_force' => false,
            'selisih' => 0,
        ]);
        // Shift bulan ini.
        $this->makeShift($this->kasirA->id);

        $hariIni = now();
        $from = $hariIni->copy()->startOfMonth()->toDateString();
        $to = $hariIni->toDateString();

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson("/api/v1/reports/kasir-performance?date_from={$from}&date_to={$to}")
                ->assertOk()->json('data.items')
        )->keyBy('user_id');

        $this->assertEquals(1, $items->get($this->kasirA->id)['shift_total']);
    }

    private function makeProduk(): int
    {
        return \App\Models\MasterProduk::factory()->create([
            'avg_cost' => 0,
            'harga_4' => 100_000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ])->id;
    }
}
