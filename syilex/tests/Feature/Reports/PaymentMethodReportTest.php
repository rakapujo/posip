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

class PaymentMethodReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;
    protected int $terminalId;
    protected int $shiftId;
    protected int $cashId;
    protected int $qrisId;
    protected int $customerId;
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
        $this->cashId = $cash->id;

        $qris = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'QRIS',
            'nama_pembayaran' => 'QRIS BCA',
            'metode' => 'non_tunai',
            'jenis' => 'qris',
            'biaya_tambahan_tipe' => 'percent',
            'biaya_tambahan_nilai' => 0.7,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);
        $this->qrisId = $qris->id;

        $terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-PM',
            'nama_terminal' => 'Kasir PM',
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
            'kode_customer' => 'CUST-PM',
            'nama' => 'Walk-in',
            'telepon' => '08000',
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeSale(array $payments, string $status = 'completed'): int
    {
        $total = array_sum(array_column($payments, 'nominal'));
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('######'),
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => $total, 'total_setelah_diskon' => $total,
            'grand_total' => $total, 'total_bayar' => $total, 'kembalian' => 0,
            'total_biaya_pembayaran' => array_sum(array_column($payments, 'biaya_tambahan')),
            'status' => $status,
            'created_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach ($payments as $p) {
            DB::table('doc_sales_payments')->insert([
                'sales_id' => $salesId,
                'metode_pembayaran_id' => $p['method_id'],
                'nominal' => $p['nominal'],
                'biaya_tambahan' => $p['biaya_tambahan'] ?? 0,
            ]);
        }
        return $salesId;
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/payment-method/breakdown')
            ->assertForbidden();
    }

    public function test_aggregates_per_method(): void
    {
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 100_000]]);
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 50_000]]);
        $this->makeSale([['method_id' => $this->qrisId, 'nominal' => 200_000, 'biaya_tambahan' => 1_400]]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/payment-method/breakdown')
            ->assertOk();

        $data = $response->json('data');

        // Grand total = 100k + 50k + 200k = 350k
        $this->assertEquals(350_000, $data['grand_total']);

        // 2 items
        $items = collect($data['items'])->keyBy('kode_pembayaran');

        // QRIS (200k) di atas karena nominal terbesar
        $this->assertEquals('QRIS', $data['items'][0]['kode_pembayaran']);
        $this->assertEquals(200_000, $items->get('QRIS')['nominal_total']);
        $this->assertEquals(1_400, $items->get('QRIS')['biaya_total']);
        $this->assertEqualsWithDelta(57.14, $items->get('QRIS')['percent'], 0.1);

        // Cash (150k, 2 trx)
        $this->assertEquals(150_000, $items->get('CASH')['nominal_total']);
        $this->assertEquals(2, $items->get('CASH')['trx_count']);
    }

    public function test_summary_splits_tunai_vs_non_tunai(): void
    {
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 100_000]]);
        $this->makeSale([['method_id' => $this->qrisId, 'nominal' => 300_000, 'biaya_tambahan' => 2_100]]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/payment-method/breakdown')
            ->assertOk();

        $summary = $response->json('data.summary');
        $this->assertEquals(100_000, $summary['tunai_nominal']);
        $this->assertEquals(1, $summary['tunai_trx']);
        $this->assertEquals(300_000, $summary['non_tunai_nominal']);
        $this->assertEquals(1, $summary['non_tunai_trx']);
        $this->assertEquals(2_100, $summary['biaya_total']);
    }

    public function test_split_payment_counted_distinct_trx(): void
    {
        // 1 sale pakai 2 metode (split payment) — trx_count harus 1 per metode
        $this->makeSale([
            ['method_id' => $this->cashId, 'nominal' => 50_000],
            ['method_id' => $this->qrisId, 'nominal' => 50_000, 'biaya_tambahan' => 350],
        ]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/payment-method/breakdown')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('kode_pembayaran');
        $this->assertEquals(1, $items->get('CASH')['trx_count']);
        $this->assertEquals(1, $items->get('QRIS')['trx_count']);
    }

    public function test_voided_sales_excluded(): void
    {
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 100_000]]);
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 50_000]], status: 'voided');

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/payment-method/breakdown')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('kode_pembayaran');
        $this->assertEquals(100_000, $items->get('CASH')['nominal_total']);
        $this->assertEquals(1, $items->get('CASH')['trx_count']);
    }

    // ─── Tambahan edge-case galak ────────────────────────────────────────

    public function test_sale_tanpa_payment_line_tidak_muncul(): void
    {
        // Sale completed tanpa baris payment → tidak menambah metode apa pun.
        // (enum doc_sales.status hanya completed|voided — tidak ada 'draft' di skema POS)
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 80_000]]);
        DB::table('doc_sales')->insert([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-NOPAY-' . fake()->unique()->numerify('#####'),
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 20_000, 'total_setelah_diskon' => 20_000, 'grand_total' => 20_000,
            'total_bayar' => 0, 'kembalian' => 0, 'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/payment-method/breakdown')
                ->assertOk()->json('data.items')
        )->keyBy('kode_pembayaran');

        $this->assertEquals(80_000, $items->get('CASH')['nominal_total']);
        $this->assertEquals(1, $items->get('CASH')['trx_count']);
        $this->assertEquals(80_000, collect($items)->sum('nominal_total'));
    }

    public function test_data_kosong_struktur_tetap_valid(): void
    {
        // Boundary: tanpa data → grand_total 0, items kosong, percent tidak dibagi nol.
        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/payment-method/breakdown')
            ->assertOk()->json('data');

        $this->assertEquals(0, $data['grand_total']);
        $this->assertSame([], $data['items']);
        $this->assertEquals(0, $data['summary']['tunai_nominal']);
        $this->assertEquals(0, $data['summary']['non_tunai_nominal']);
        $this->assertEquals(0, $data['summary']['biaya_total']);
    }

    public function test_percent_terdistribusi_eksak_dan_total_100(): void
    {
        // Cash 250k + QRIS 750k = 1000k → 25% & 75%.
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 250_000]]);
        $this->makeSale([['method_id' => $this->qrisId, 'nominal' => 750_000, 'biaya_tambahan' => 5_250]]);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/payment-method/breakdown')
                ->assertOk()->json('data.items')
        )->keyBy('kode_pembayaran');

        $this->assertEquals(25.0, $items->get('CASH')['percent']);
        $this->assertEquals(75.0, $items->get('QRIS')['percent']);
        $this->assertEquals(100.0, $items->get('CASH')['percent'] + $items->get('QRIS')['percent']);
    }

    public function test_urut_nominal_desc(): void
    {
        // QRIS terkecil, CASH terbesar → CASH harus di index 0.
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 900_000]]);
        $this->makeSale([['method_id' => $this->qrisId, 'nominal' => 100_000, 'biaya_tambahan' => 700]]);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/payment-method/breakdown')
            ->assertOk()->json('data.items');

        $this->assertEquals('CASH', $items[0]['kode_pembayaran']);
        $this->assertEquals('QRIS', $items[1]['kode_pembayaran']);
    }

    public function test_batas_atas_tanggal_inklusif_dan_record_luar_rentang_dibuang(): void
    {
        $hariIni = now()->toDateString();

        // Sale jam 23:50 hari ini → ikut.
        $s1 = $this->makeSale([['method_id' => $this->cashId, 'nominal' => 30_000]]);
        DB::table('doc_sales')->where('id', $s1)
            ->update(['tanggal' => now()->setTime(23, 50, 0)->toDateTimeString()]);

        // Sale besok pagi → tidak ikut.
        $s2 = $this->makeSale([['method_id' => $this->cashId, 'nominal' => 70_000]]);
        DB::table('doc_sales')->where('id', $s2)
            ->update(['tanggal' => now()->addDay()->setTime(8, 0, 0)->toDateTimeString()]);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson("/api/v1/reports/payment-method/breakdown?date_from={$hariIni}&date_to={$hariIni}")
                ->assertOk()->json('data.items')
        )->keyBy('kode_pembayaran');

        $this->assertEquals(30_000, $items->get('CASH')['nominal_total']);
        $this->assertEquals(1, $items->get('CASH')['trx_count']);
    }

    public function test_terminal_filter_membatasi_scope(): void
    {
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 50_000]]);

        // Filter terminal yang tidak ada → kosong.
        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/payment-method/breakdown?terminal_id=99999')
            ->assertOk()->json('data');

        $this->assertEquals(0, $data['grand_total']);
        $this->assertSame([], $data['items']);
    }

    public function test_biaya_total_summary_jumlahkan_tunai_dan_non_tunai(): void
    {
        // CASH biaya 100 + QRIS biaya 700 → biaya_total summary = 800.
        $this->makeSale([['method_id' => $this->cashId, 'nominal' => 50_000, 'biaya_tambahan' => 100]]);
        $this->makeSale([['method_id' => $this->qrisId, 'nominal' => 100_000, 'biaya_tambahan' => 700]]);

        $summary = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/payment-method/breakdown')
            ->assertOk()->json('data.summary');

        $this->assertEquals(800, $summary['biaya_total']);
        $this->assertEquals(50_000, $summary['tunai_nominal']);
        $this->assertEquals(100_000, $summary['non_tunai_nominal']);
    }
}
