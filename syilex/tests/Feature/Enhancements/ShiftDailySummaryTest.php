<?php

namespace Tests\Feature\Enhancements;

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

/**
 * E5 — Shift Daily Summary konsolidasi per tanggal + terminal.
 */
class ShiftDailySummaryTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;
    protected int $terminalId;
    protected int $warehouseId;
    protected int $cashId;
    protected int $customerId;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'terminal.view', 'guard_name' => 'web']);
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo('terminal.view');

        $wh = MasterWarehouse::factory()->create(['created_by' => $this->viewer->id]);
        $this->warehouseId = $wh->id;

        $cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH', 'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai', 'jenis' => null,
            'biaya_tambahan_tipe' => 'none', 'biaya_tambahan_nilai' => 0,
            'status' => 'active', 'created_by' => $this->viewer->id,
        ]);
        $this->cashId = $cash->id;

        $terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-SD', 'nama_terminal' => 'Kasir Daily',
            'warehouse_id' => $wh->id,
            'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $this->viewer->id,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);
        $this->terminalId = $terminal->id;

        $this->customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-SD', 'nama' => 'Walk',
            'telepon' => '08000',
            'status' => 'active',
            'created_by' => $this->viewer->id, 'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makeShift(string $startedAt, bool $force = false, float $selisih = 0): PosTerminalShift
    {
        return PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminalId,
            'user_id' => $this->viewer->id,
            'started_at' => $startedAt,
            'ended_at' => $startedAt,
            'ended_by_force' => $force,
            'selisih' => $selisih,
        ]);
    }

    private function makeCompletedSale(int $shiftId, float $grandTotal, string $tanggal): void
    {
        DB::table('doc_sales')->insert([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . fake()->unique()->numerify('######'),
            'tanggal' => $tanggal,
            'terminal_id' => $this->terminalId,
            'shift_id' => $shiftId,
            'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => $grandTotal, 'total_setelah_diskon' => $grandTotal,
            'grand_total' => $grandTotal, 'total_bayar' => $grandTotal, 'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => $tanggal, 'updated_at' => $tanggal,
        ]);
    }

    public function test_requires_terminal_view_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertForbidden();
    }

    public function test_aggregates_per_tanggal_terminal(): void
    {
        $today = now()->toDateTimeString();
        $yesterday = now()->subDay()->toDateTimeString();

        $s1 = $this->makeShift($today, selisih: 2000);
        $s2 = $this->makeShift($today, selisih: -1000);
        $s3 = $this->makeShift($yesterday, selisih: 0);

        $this->makeCompletedSale($s1->id, 1_500_000, $today);
        $this->makeCompletedSale($s2->id, 800_000, $today);
        $this->makeCompletedSale($s3->id, 500_000, $yesterday);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('tanggal');
        $this->assertCount(2, $items);

        $today_row = $items->get(now()->toDateString());
        $this->assertEquals(2, $today_row['shift_count']);
        $this->assertEquals(0, $today_row['shift_paksa_count']);
        $this->assertEquals(2_300_000, $today_row['omzet_total']);
        $this->assertEquals(1_150_000, $today_row['omzet_per_shift']);
        $this->assertEquals(1000, $today_row['total_selisih']); // 2000 + (-1000)

        $y_row = $items->get(now()->subDay()->toDateString());
        $this->assertEquals(1, $y_row['shift_count']);
        $this->assertEquals(500_000, $y_row['omzet_total']);
    }

    public function test_tracks_shift_paksa_count(): void
    {
        $today = now()->toDateTimeString();
        $this->makeShift($today, force: false);
        $this->makeShift($today, force: true);
        $this->makeShift($today, force: true);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk();

        $row = $response->json('data.items.0');
        $this->assertEquals(3, $row['shift_count']);
        $this->assertEquals(2, $row['shift_paksa_count']);
    }

    public function test_voided_sales_excluded_from_omzet(): void
    {
        $today = now()->toDateTimeString();
        $shift = $this->makeShift($today);

        $this->makeCompletedSale($shift->id, 500_000, $today);
        // Voided sales
        DB::table('doc_sales')->insert([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-VOID', 'tanggal' => $today,
            'terminal_id' => $this->terminalId,
            'shift_id' => $shift->id, 'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 100_000, 'total_setelah_diskon' => 100_000,
            'grand_total' => 100_000, 'total_bayar' => 100_000,
            'kembalian' => 0, 'total_biaya_pembayaran' => 0,
            'status' => 'voided',
            'created_by' => $this->viewer->id,
            'created_at' => $today, 'updated_at' => $today,
        ]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk();

        $this->assertEquals(500_000, $response->json('data.items.0.omzet_total'));
    }

    public function test_filter_by_terminal_id(): void
    {
        $wh = MasterWarehouse::factory()->create(['created_by' => $this->viewer->id]);
        $otherTerminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-OTHER', 'nama_terminal' => 'Other',
            'warehouse_id' => $wh->id,
            'default_metode_pembayaran_id' => $this->cashId,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $this->makeShift(now()->toDateTimeString());
        PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $otherTerminal->id,
            'user_id' => $this->viewer->id,
            'started_at' => now(),
            'ended_at' => now(),
            'ended_by_force' => false,
            'selisih' => 0,
        ]);

        $response = $this->actingAs($this->viewer)
            ->getJson("/api/v1/shifts/daily-summary?terminal_id={$this->terminalId}")
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('TRM-SD', $items[0]['kode_terminal']);
    }

    public function test_empty_returns_no_items(): void
    {
        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk();

        $this->assertEmpty($response->json('data.items'));
    }

    // ==================== EDGE CASES (galak, assertion eksak) ====================

    /**
     * omzet_per_shift = round(omzet / shift_count, 2) EKSAK termasuk hasil desimal
     * berulang. 3 shift, omzet 1.000.000 → 333.333,33.
     */
    public function test_omzet_per_shift_pembulatan_eksak(): void
    {
        $today = now()->toDateTimeString();
        $s1 = $this->makeShift($today);
        $s2 = $this->makeShift($today);
        $s3 = $this->makeShift($today);

        // Total omzet 1.000.000 dibagi 3 shift
        $this->makeCompletedSale($s1->id, 400_000, $today);
        $this->makeCompletedSale($s2->id, 300_000, $today);
        $this->makeCompletedSale($s3->id, 300_000, $today);

        $row = $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk()
            ->json('data.items.0');

        $this->assertEquals(3, $row['shift_count']);
        $this->assertEquals(1_000_000, $row['omzet_total']);
        $this->assertEquals(333_333.33, $row['omzet_per_shift']); // round 2 desimal
    }

    /**
     * Banyak completed sale dalam satu shift dijumlahkan eksak ke omzet_total.
     */
    public function test_multi_sale_per_shift_dijumlah_eksak(): void
    {
        $today = now()->toDateTimeString();
        $shift = $this->makeShift($today);

        $this->makeCompletedSale($shift->id, 125_000, $today);
        $this->makeCompletedSale($shift->id, 75_500, $today);
        $this->makeCompletedSale($shift->id, 200_000, $today);

        $row = $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk()
            ->json('data.items.0');

        $this->assertEquals(1, $row['shift_count']);
        $this->assertEquals(400_500, $row['omzet_total']); // 125k + 75.5k + 200k
        $this->assertEquals(400_500, $row['omzet_per_shift']);
    }

    /**
     * Hanya status 'completed' yang masuk omzet (enum doc_sales = completed|voided,
     * tidak ada draft di POS). Campuran 2 completed + 2 voided → hanya completed
     * dijumlahkan eksak.
     */
    public function test_hanya_completed_yang_masuk_omzet(): void
    {
        $today = now()->toDateTimeString();
        $shift = $this->makeShift($today);

        $this->makeCompletedSale($shift->id, 600_000, $today);
        $this->makeCompletedSale($shift->id, 150_000, $today);

        foreach ([999_000, 111_000] as $i => $voidTotal) {
            DB::table('doc_sales')->insert([
                'ulid' => (string) Str::ulid(),
                'nomor_dokumen' => 'INV-VOID-' . $i, 'tanggal' => $today,
                'terminal_id' => $this->terminalId,
                'shift_id' => $shift->id, 'warehouse_id' => $this->warehouseId,
                'customer_id' => $this->customerId,
                'subtotal' => $voidTotal, 'total_setelah_diskon' => $voidTotal,
                'grand_total' => $voidTotal, 'total_bayar' => $voidTotal,
                'kembalian' => 0, 'total_biaya_pembayaran' => 0,
                'status' => 'voided',
                'created_by' => $this->viewer->id,
                'created_at' => $today, 'updated_at' => $today,
            ]);
        }

        $row = $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk()
            ->json('data.items.0');

        $this->assertEquals(750_000, $row['omzet_total']); // 600k + 150k, voided diabaikan
        $this->assertEquals(750_000, $row['omzet_per_shift']);
    }

    /**
     * omzet di-bucket berdasarkan DATE(shift.started_at), BUKAN doc_sales.tanggal.
     * Sale dibuat dengan tanggal berbeda dari started_at shift; omzet tetap masuk
     * ke tanggal shift.
     */
    public function test_omzet_dibucket_per_tanggal_shift_bukan_tanggal_sale(): void
    {
        $shiftDay = now()->subDay()->startOfDay();
        $shift = $this->makeShift($shiftDay->toDateTimeString());

        // Sale dicatat dengan tanggal HARI INI walau shift dimulai KEMARIN
        $this->makeCompletedSale($shift->id, 450_000, now()->toDateTimeString());

        $items = collect($this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk()
            ->json('data.items'))->keyBy('tanggal');

        $shiftDateKey = $shiftDay->toDateString();
        $this->assertTrue($items->has($shiftDateKey));
        $this->assertEquals(450_000, $items->get($shiftDateKey)['omzet_total']);
        // Tidak ada baris untuk tanggal hari ini (tidak ada shift di hari ini)
        $this->assertFalse($items->has(now()->toDateString()));
    }

    /**
     * total_selisih menjumlahkan selisih semua shift di (tanggal, terminal) dengan
     * benar termasuk nilai negatif. 3 shift: +5000, -2000, +1000 → +4000.
     */
    public function test_total_selisih_termasuk_negatif_eksak(): void
    {
        $today = now()->toDateTimeString();
        $this->makeShift($today, selisih: 5000);
        $this->makeShift($today, selisih: -2000);
        $this->makeShift($today, selisih: 1000);

        $row = $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk()
            ->json('data.items.0');

        $this->assertEquals(3, $row['shift_count']);
        $this->assertEquals(4000, $row['total_selisih']); // 5000 - 2000 + 1000
    }

    /**
     * Dua terminal di hari yang sama → dua baris terpisah dengan agregasi
     * masing-masing eksak (tidak tercampur).
     */
    public function test_dua_terminal_hari_sama_terpisah(): void
    {
        $today = now()->toDateTimeString();
        $wh = MasterWarehouse::factory()->create(['created_by' => $this->viewer->id]);
        $terminal2 = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-2', 'nama_terminal' => 'Kasir 2',
            'warehouse_id' => $wh->id,
            'default_metode_pembayaran_id' => $this->cashId,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $s1 = $this->makeShift($today); // terminal utama
        $s2 = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminal2->id,
            'user_id' => $this->viewer->id,
            'started_at' => now(), 'ended_at' => now(),
            'ended_by_force' => false, 'selisih' => 0,
        ]);

        $this->makeCompletedSale($s1->id, 300_000, $today);
        DB::table('doc_sales')->insert([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-T2', 'tanggal' => $today,
            'terminal_id' => $terminal2->id,
            'shift_id' => $s2->id, 'warehouse_id' => $this->warehouseId,
            'customer_id' => $this->customerId,
            'subtotal' => 700_000, 'total_setelah_diskon' => 700_000,
            'grand_total' => 700_000, 'total_bayar' => 700_000,
            'kembalian' => 0, 'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => $today, 'updated_at' => $today,
        ]);

        $items = collect($this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk()
            ->json('data.items'))->keyBy('kode_terminal');

        $this->assertCount(2, $items);
        $this->assertEquals(300_000, $items->get('TRM-SD')['omzet_total']);
        $this->assertEquals(700_000, $items->get('TRM-2')['omzet_total']);
    }

    /**
     * Period default = bulan berjalan. Shift bulan lalu TIDAK ikut tanpa filter.
     */
    public function test_default_period_bulan_berjalan_kecualikan_bulan_lalu(): void
    {
        $this->makeShift(now()->toDateTimeString()); // bulan ini
        $this->makeShift(now()->subMonthNoOverflow()->startOfMonth()->toDateTimeString()); // bulan lalu

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(now()->startOfMonth()->toDateString(), $data['period']['from']);
        $this->assertEquals(now()->toDateString(), $data['period']['to']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals(now()->toDateString(), $data['items'][0]['tanggal']);
    }

    /**
     * date_to < date_from ditolak validasi (after_or_equal) → 422.
     */
    public function test_date_to_sebelum_date_from_ditolak(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts/daily-summary?date_from=2024-02-01&date_to=2024-01-01')
            ->assertStatus(422);
    }
}
