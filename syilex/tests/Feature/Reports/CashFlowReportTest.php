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

class CashFlowReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $userWithPerm;
    protected int $terminalId;
    protected int $shiftId;
    protected int $cashMethodId;
    protected int $customerId;
    protected int $warehouseId;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'laporan.keuangan', 'guard_name' => 'web']);

        $this->userWithPerm = User::factory()->create();
        $this->userWithPerm->givePermissionTo('laporan.keuangan');

        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->userWithPerm->id]);
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
            'created_by' => $this->userWithPerm->id,
        ]);
        $this->cashMethodId = $cash->id;

        $terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-CF',
            'nama_terminal' => 'Kasir CashFlow',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $this->userWithPerm->id,
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
        ]);
        $this->terminalId = $terminal->id;

        $shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminal->id,
            'user_id' => $this->userWithPerm->id,
            'started_at' => now(),
        ]);
        $this->shiftId = $shift->id;

        $this->customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-CF',
            'nama' => 'Walk-in',
            'telepon' => '08000',
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
            'updated_by' => $this->userWithPerm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeCashTx(string $tipe, float $nominal, ?string $keterangan = null, ?string $createdAt = null): void
    {
        DB::table('pos_cash_transactions')->insert([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'tipe' => $tipe,
            'nominal' => $nominal,
            'keterangan' => $keterangan,
            'created_by' => $this->userWithPerm->id,
            'created_at' => $createdAt ?? now(),
            'updated_at' => $createdAt ?? now(),
        ]);
    }

    private function makeSaleWithCashPayment(float $grandTotal, float $cashReceived, float $kembalian, ?string $tanggal = null): int
    {
        $tanggal ??= now()->toDateTimeString();
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('#####'),
            'tanggal' => $tanggal,
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => $grandTotal,
            'total_setelah_diskon' => $grandTotal,
            'grand_total' => $grandTotal,
            'total_bayar' => $cashReceived,
            'kembalian' => $kembalian,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->userWithPerm->id,
            'created_at' => $tanggal,
            'updated_at' => $tanggal,
        ]);

        DB::table('doc_sales_payments')->insert([
            'sales_id' => $salesId,
            'metode_pembayaran_id' => $this->cashMethodId,
            'nominal' => $cashReceived,
            'biaya_tambahan' => 0,
        ]);

        return $salesId;
    }

    public function test_summary_requires_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/cash-flow/summary')
            ->assertForbidden();
    }

    public function test_summary_computes_net_cash_flow(): void
    {
        $this->makeCashTx('setor_awal', 100_000);
        $this->makeCashTx('kas_masuk', 50_000, 'Injeksi kas');
        $this->makeCashTx('kas_keluar', 20_000, 'Beli galon');
        $this->makeCashTx('kas_keluar', 15_000, 'Refund retur INV-001');

        // Sale: grand_total 50k, customer bayar 60k (cash), kembalian 10k → net contribution 50k
        $this->makeSaleWithCashPayment(50_000, 60_000, 10_000);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/summary')
            ->assertOk();

        $data = $response->json('data');

        $this->assertEquals(100_000, $data['setor_awal']);
        $this->assertEquals(50_000, $data['kas_masuk']);
        $this->assertEquals(50_000, $data['penjualan_tunai_net']); // 60 - 10
        $this->assertEquals(20_000, $data['kas_keluar_manual']);
        $this->assertEquals(15_000, $data['refund_tunai']);
        // Net = 100 + 50 + 50 - 20 - 15 = 165
        $this->assertEquals(165_000, $data['net_cash_flow']);
    }

    public function test_refund_retur_detected_by_keterangan_prefix(): void
    {
        $this->makeCashTx('kas_keluar', 10_000, 'Biaya listrik');   // manual
        $this->makeCashTx('kas_keluar', 7_000, 'Refund retur XYZ');  // refund
        $this->makeCashTx('kas_keluar', 3_000, null);                 // manual (null keterangan)

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/summary')
            ->assertOk();

        $data = $response->json('data');
        $this->assertEquals(13_000, $data['kas_keluar_manual']);
        $this->assertEquals(7_000, $data['refund_tunai']);
    }

    public function test_non_cash_payment_excluded(): void
    {
        // Buat metode non-tunai
        $nonCash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'TRF',
            'nama_pembayaran' => 'Transfer',
            'metode' => 'non_tunai',
            'jenis' => 'bank',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
        ]);

        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-NONCASH',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 100_000,
            'total_setelah_diskon' => 100_000,
            'grand_total' => 100_000,
            'total_bayar' => 100_000,
            'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->userWithPerm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('doc_sales_payments')->insert([
            'sales_id' => $salesId,
            'metode_pembayaran_id' => $nonCash->id,
            'nominal' => 100_000,
            'biaya_tambahan' => 0,
        ]);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/summary')
            ->assertOk();

        $this->assertEquals(0, $response->json('data.penjualan_tunai_net'));
    }

    public function test_daily_returns_per_date_rows(): void
    {
        $today = now()->toDateTimeString();
        $yesterday = now()->subDay()->toDateTimeString();

        $this->makeCashTx('setor_awal', 100_000, null, $today);
        $this->makeCashTx('setor_awal', 200_000, null, $yesterday);
        $this->makeSaleWithCashPayment(50_000, 50_000, 0, $today);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/daily')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('tanggal');
        $this->assertCount(2, $items);

        $todayRow = $items->get(now()->toDateString());
        $this->assertEquals(100_000, $todayRow['setor_awal']);
        $this->assertEquals(50_000, $todayRow['penjualan_tunai_net']);
        $this->assertEquals(150_000, $todayRow['net_cash_flow']);
    }

    public function test_voided_sales_excluded_from_cash_flow(): void
    {
        $salesId = $this->makeSaleWithCashPayment(50_000, 50_000, 0);
        DB::table('doc_sales')->where('id', $salesId)->update(['status' => 'voided']);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/summary')
            ->assertOk();

        $this->assertEquals(0, $response->json('data.penjualan_tunai_net'));
    }

    // ─── Tambahan edge-case galak ────────────────────────────────────────

    public function test_sale_completed_tanpa_payment_tunai_tidak_menambah_penjualan_tunai(): void
    {
        // Sale completed tapi TANPA payment line tunai sama sekali → 0 penjualan tunai.
        // (catatan: enum doc_sales.status hanya completed|voided — tidak ada 'draft' di skema POS)
        DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-NOPAY',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 70_000, 'total_setelah_diskon' => 70_000, 'grand_total' => 70_000,
            'total_bayar' => 0, 'kembalian' => 0, 'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->userWithPerm->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/summary')
            ->assertOk();

        $this->assertEquals(0, $response->json('data.penjualan_tunai_net'));
        $this->assertEquals(0, $response->json('data.net_cash_flow'));
    }

    public function test_data_kosong_mengembalikan_nol_di_semua_komponen(): void
    {
        // Boundary: tidak ada transaksi sama sekali → struktur tetap valid, semua 0.
        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(0, $data['setor_awal']);
        $this->assertEquals(0, $data['kas_masuk']);
        $this->assertEquals(0, $data['penjualan_tunai_net']);
        $this->assertEquals(0, $data['kas_keluar_manual']);
        $this->assertEquals(0, $data['refund_tunai']);
        $this->assertEquals(0, $data['net_cash_flow']);
    }

    public function test_daily_kosong_mengembalikan_items_array_kosong(): void
    {
        $items = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/daily')
            ->assertOk()
            ->json('data.items');

        $this->assertSame([], $items);
    }

    public function test_batas_atas_tanggal_inklusif_jam_lebih_dari_nol_ikut(): void
    {
        // Sales jam 23:30:00 di hari date_to HARUS ikut (batas atas 23:59:59 inklusif).
        $dateTo = now()->toDateString();
        $lateToday = now()->setTime(23, 30, 0)->toDateTimeString();
        $this->makeSaleWithCashPayment(40_000, 40_000, 0, $lateToday);

        $data = $this->actingAs($this->userWithPerm)
            ->getJson("/api/v1/reports/cash-flow/summary?date_from={$dateTo}&date_to={$dateTo}")
            ->assertOk()
            ->json('data');

        $this->assertEquals(40_000, $data['penjualan_tunai_net']);
    }

    public function test_record_di_luar_rentang_tanggal_tidak_ikut(): void
    {
        // Sale 1 hari setelah date_to → tidak boleh ikut.
        $besok = now()->addDay()->setTime(8, 0, 0)->toDateTimeString();
        $this->makeSaleWithCashPayment(99_000, 99_000, 0, $besok);

        $today = now()->toDateString();
        $data = $this->actingAs($this->userWithPerm)
            ->getJson("/api/v1/reports/cash-flow/summary?date_from={$today}&date_to={$today}")
            ->assertOk()
            ->json('data');

        $this->assertEquals(0, $data['penjualan_tunai_net']);
    }

    public function test_terminal_filter_membatasi_kas_dan_penjualan(): void
    {
        // Terminal kedua dengan setor awal + sale tunai sendiri.
        $cash2 = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH2',
            'nama_pembayaran' => 'Tunai 2',
            'metode' => 'tunai',
            'jenis' => null,
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
        ]);
        $terminal2 = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-CF2',
            'nama_terminal' => 'Kasir CF2',
            'warehouse_id' => $this->warehouseId,
            'default_metode_pembayaran_id' => $cash2->id,
            'active_user_id' => $this->userWithPerm->id,
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
        ]);

        // Terminal 1 (default): setor awal 100k + sale 50k.
        $this->makeCashTx('setor_awal', 100_000);
        $this->makeSaleWithCashPayment(50_000, 50_000, 0);

        // Terminal 2: setor awal 999k + sale 999k (TIDAK boleh muncul saat filter T1).
        DB::table('pos_cash_transactions')->insert([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminal2->id,
            'shift_id' => $this->shiftId,
            'tipe' => 'setor_awal',
            'nominal' => 999_000,
            'keterangan' => null,
            'created_by' => $this->userWithPerm->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $s2 = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-T2',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $terminal2->id,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 999_000, 'total_setelah_diskon' => 999_000, 'grand_total' => 999_000,
            'total_bayar' => 999_000, 'kembalian' => 0, 'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->userWithPerm->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('doc_sales_payments')->insert([
            'sales_id' => $s2,
            'metode_pembayaran_id' => $cash2->id,
            'nominal' => 999_000,
            'biaya_tambahan' => 0,
        ]);

        $data = $this->actingAs($this->userWithPerm)
            ->getJson("/api/v1/reports/cash-flow/summary?terminal_id={$this->terminalId}")
            ->assertOk()
            ->json('data');

        $this->assertEquals(100_000, $data['setor_awal']);
        $this->assertEquals(50_000, $data['penjualan_tunai_net']);
        $this->assertEquals(150_000, $data['net_cash_flow']);
    }

    public function test_split_payment_hanya_porsi_tunai_yang_dihitung(): void
    {
        // 1 sale grand 100k = 60k tunai + 40k non-tunai. Hanya 60k masuk cash flow.
        $nonCash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'TRF',
            'nama_pembayaran' => 'Transfer',
            'metode' => 'non_tunai',
            'jenis' => 'bank',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->userWithPerm->id,
        ]);

        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-SPLIT',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $this->terminalId,
            'shift_id' => $this->shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 100_000, 'total_setelah_diskon' => 100_000, 'grand_total' => 100_000,
            'total_bayar' => 100_000, 'kembalian' => 0, 'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->userWithPerm->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('doc_sales_payments')->insert([
            ['sales_id' => $salesId, 'metode_pembayaran_id' => $this->cashMethodId, 'nominal' => 60_000, 'biaya_tambahan' => 0],
            ['sales_id' => $salesId, 'metode_pembayaran_id' => $nonCash->id, 'nominal' => 40_000, 'biaya_tambahan' => 0],
        ]);

        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(60_000, $data['penjualan_tunai_net']);
    }

    public function test_daily_batas_atas_inklusif_dan_record_luar_rentang_dibuang(): void
    {
        $hariIni = now()->toDateString();
        // Setor jam 23:45 hari date_to → ikut.
        $this->makeCashTx('setor_awal', 80_000, null, now()->setTime(23, 45, 0)->toDateTimeString());
        // Setor besok pagi → tidak ikut.
        $this->makeCashTx('setor_awal', 500_000, null, now()->addDay()->setTime(7, 0, 0)->toDateTimeString());

        $items = collect(
            $this->actingAs($this->userWithPerm)
                ->getJson("/api/v1/reports/cash-flow/daily?date_from={$hariIni}&date_to={$hariIni}")
                ->assertOk()
                ->json('data.items')
        )->keyBy('tanggal');

        $this->assertCount(1, $items);
        $this->assertEquals(80_000, $items->get($hariIni)['setor_awal']);
    }

    public function test_multiple_cash_sales_kembalian_diakumulasi_benar(): void
    {
        // 3 sale tunai: (50k bayar 50k kembali 0), (30k bayar 50k kembali 20k), (10k bayar 20k kembali 10k)
        // cash_received = 50+50+20 = 120k; kembalian = 0+20+10 = 30k; net = 90k.
        $this->makeSaleWithCashPayment(50_000, 50_000, 0);
        $this->makeSaleWithCashPayment(30_000, 50_000, 20_000);
        $this->makeSaleWithCashPayment(10_000, 20_000, 10_000);

        $data = $this->actingAs($this->userWithPerm)
            ->getJson('/api/v1/reports/cash-flow/summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(90_000, $data['penjualan_tunai_net']);
        $this->assertEquals(90_000, $data['net_cash_flow']);
    }
}
