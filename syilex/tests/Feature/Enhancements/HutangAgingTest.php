<?php

namespace Tests\Feature\Enhancements;

use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * E3 — Hutang Supplier Aging Bucket endpoint.
 */
class HutangAgingTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewerWithNominal;
    protected User $viewerNoNominal;
    protected int $supplierId;
    protected int $warehouseId;
    protected int $poId;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['hutang.view', 'hutang.view_nominal'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        Permission::firstOrCreate(['name' => 'laporan.export', 'guard_name' => 'web']);
        $this->viewerWithNominal = User::factory()->create();
        $this->viewerWithNominal->givePermissionTo(['hutang.view', 'hutang.view_nominal']);
        $this->viewerNoNominal = User::factory()->create();
        $this->viewerNoNominal->givePermissionTo('hutang.view');

        $this->supplierId = DB::table('master_supplier')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-AG',
            'nama_supplier' => 'Supplier Aging',
            'nama_pic' => 'PIC Test',
            'telepon' => '08000',
            'tempo_default' => 30,
            'status' => 'active',
            'created_by' => $this->viewerWithNominal->id,
            'updated_by' => $this->viewerWithNominal->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $wh = MasterWarehouse::factory()->create(['created_by' => $this->viewerWithNominal->id]);
        $this->warehouseId = $wh->id;
        $this->poId = $this->createPo($this->supplierId);
    }

    private function createPo(int $supplierId): int
    {
        return DB::table('doc_purchase_order')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PO-' . fake()->unique()->numerify('######'),
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $supplierId,
            'warehouse_id' => $this->warehouseId,
            'status' => 'approved',
            'created_by' => $this->viewerWithNominal->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeHutang(?string $jatuhTempo, float $sisa, string $status = 'unpaid', ?int $supplierId = null, ?int $poId = null): void
    {
        DB::table('supplier_hutang')->insert([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $supplierId ?? $this->supplierId,
            'po_id' => $poId ?? $this->poId,
            'tanggal' => now()->toDateString(),
            'tanggal_jatuh_tempo' => $jatuhTempo,
            'nominal_awal' => $sisa,
            'nominal_terbayar' => 0,
            'sisa_hutang' => $sisa,
            'status' => $status,
            'created_at' => now(),
        ]);
    }

    public function test_requires_hutang_view_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertForbidden();
    }

    public function test_requires_hutang_view_nominal_permission(): void
    {
        $this->actingAs($this->viewerNoNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertForbidden();
    }

    public function test_buckets_hutang_by_aging(): void
    {
        // Belum tempo (due tomorrow)
        $this->makeHutang(now()->addDay()->toDateString(), 1_000_000);
        // b1_30: due 15 days ago
        $this->makeHutang(now()->subDays(15)->toDateString(), 500_000);
        // b31_60: due 45 days ago
        $this->makeHutang(now()->subDays(45)->toDateString(), 250_000);
        // b61_90: due 75 days ago
        $this->makeHutang(now()->subDays(75)->toDateString(), 100_000);
        // above_90: due 120 days ago
        $this->makeHutang(now()->subDays(120)->toDateString(), 50_000);
        // null tempo → belum_tempo
        $this->makeHutang(null, 200_000);

        $response = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk();

        $data = $response->json('data');
        $buckets = $data['buckets'];

        // belum_tempo: 1jt (future date) + 200k (null) = 1.2jt, 2 rows
        $this->assertEquals(2, $buckets['belum_tempo']['count']);
        $this->assertEquals(1_200_000, $buckets['belum_tempo']['nominal']);

        $this->assertEquals(1, $buckets['b1_30']['count']);
        $this->assertEquals(500_000, $buckets['b1_30']['nominal']);

        $this->assertEquals(1, $buckets['b31_60']['count']);
        $this->assertEquals(250_000, $buckets['b31_60']['nominal']);

        $this->assertEquals(1, $buckets['b61_90']['count']);
        $this->assertEquals(100_000, $buckets['b61_90']['nominal']);

        $this->assertEquals(1, $buckets['above_90']['count']);
        $this->assertEquals(50_000, $buckets['above_90']['nominal']);

        $this->assertEquals(2_100_000, $data['total_hutang_outstanding']);
        $this->assertEquals(6, $data['total_count']);
    }

    public function test_excludes_paid_hutang(): void
    {
        $this->makeHutang(now()->subDays(60)->toDateString(), 0, 'paid'); // sisa 0 → excluded
        $this->makeHutang(now()->subDays(60)->toDateString(), 100_000); // unpaid

        $response = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk();

        $this->assertEquals(100_000, $response->json('data.total_hutang_outstanding'));
        $this->assertEquals(1, $response->json('data.total_count'));
    }

    public function test_percent_calculation(): void
    {
        $this->makeHutang(now()->subDays(15)->toDateString(), 600_000); // b1_30
        $this->makeHutang(now()->subDays(45)->toDateString(), 400_000); // b31_60

        $response = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk();

        $buckets = $response->json('data.buckets');
        $this->assertEquals(60.0, $buckets['b1_30']['percent']);
        $this->assertEquals(40.0, $buckets['b31_60']['percent']);
    }

    public function test_empty_returns_zero(): void
    {
        $response = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk();

        $this->assertEquals(0, $response->json('data.total_hutang_outstanding'));
        $this->assertEquals(0, $response->json('data.total_count'));
    }

    public function test_filter_by_supplier_id(): void
    {
        $otherSupplierId = DB::table('master_supplier')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-B',
            'nama_supplier' => 'Other',
            'nama_pic' => 'PIC B',
            'telepon' => '08001',
            'tempo_default' => 30,
            'status' => 'active',
            'created_by' => $this->viewerWithNominal->id,
            'updated_by' => $this->viewerWithNominal->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->makeHutang(now()->subDays(15)->toDateString(), 100_000);
        $otherPoId = $this->createPo($otherSupplierId);
        $this->makeHutang(now()->subDays(15)->toDateString(), 999_000, supplierId: $otherSupplierId, poId: $otherPoId);

        $response = $this->actingAs($this->viewerWithNominal)
            ->getJson("/api/v1/supplier-hutangs/aging-summary?supplier_id={$this->supplierId}")
            ->assertOk();

        $this->assertEquals(100_000, $response->json('data.total_hutang_outstanding'));
    }

    // ==================== EDGE CASES (galak, assertion eksak) ====================

    /**
     * Boundary aging: jatuh tempo TEPAT hari ini (overdue = 0) → belum_tempo,
     * BUKAN b1_30. Logic: overdue <= 0 masuk belum_tempo.
     */
    public function test_jatuh_tempo_hari_ini_masuk_belum_tempo(): void
    {
        $this->makeHutang(now()->toDateString(), 123_456); // due hari ini

        $buckets = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk()
            ->json('data.buckets');

        $this->assertEquals(1, $buckets['belum_tempo']['count']);
        $this->assertEquals(123_456, $buckets['belum_tempo']['nominal']);
        $this->assertEquals(0, $buckets['b1_30']['count']);
        $this->assertEquals(0.0, $buckets['b1_30']['nominal']);
    }

    /**
     * Boundary TEPAT di batas tiap bucket. overdue diukur dari now()->startOfDay().
     *   - 30 hari overdue → masih b1_30 (overdue <= 30)
     *   - 31 hari overdue → b31_60 (overdue > 30)
     *   - 60 hari overdue → masih b31_60 (overdue <= 60)
     *   - 61 hari overdue → b61_90 (overdue > 60)
     *   - 90 hari overdue → masih b61_90 (overdue <= 90)
     *   - 91 hari overdue → above_90 (overdue > 90)
     * Nominal sengaja unik agar tidak ada bucket yang tertukar diam-diam.
     */
    public function test_boundary_tepat_di_batas_setiap_bucket(): void
    {
        $this->makeHutang(now()->subDays(30)->toDateString(), 10_000); // b1_30
        $this->makeHutang(now()->subDays(31)->toDateString(), 20_000); // b31_60
        $this->makeHutang(now()->subDays(60)->toDateString(), 40_000); // b31_60
        $this->makeHutang(now()->subDays(61)->toDateString(), 80_000); // b61_90
        $this->makeHutang(now()->subDays(90)->toDateString(), 160_000); // b61_90
        $this->makeHutang(now()->subDays(91)->toDateString(), 320_000); // above_90

        $buckets = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk()
            ->json('data.buckets');

        $this->assertEquals(0, $buckets['belum_tempo']['count']);

        $this->assertEquals(1, $buckets['b1_30']['count']);
        $this->assertEquals(10_000, $buckets['b1_30']['nominal']);

        $this->assertEquals(2, $buckets['b31_60']['count']); // 31 + 60 hari
        $this->assertEquals(60_000, $buckets['b31_60']['nominal']); // 20k + 40k

        $this->assertEquals(2, $buckets['b61_90']['count']); // 61 + 90 hari
        $this->assertEquals(240_000, $buckets['b61_90']['nominal']); // 80k + 160k

        $this->assertEquals(1, $buckets['above_90']['count']); // 91 hari
        $this->assertEquals(320_000, $buckets['above_90']['nominal']);
    }

    /**
     * Boundary jatuh tempo BESOK (overdue = -1) → belum_tempo. Memastikan tanggal
     * future tidak salah jatuh ke b1_30.
     */
    public function test_jatuh_tempo_besok_belum_tempo(): void
    {
        $this->makeHutang(now()->addDay()->toDateString(), 77_000);

        $buckets = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk()
            ->json('data.buckets');

        $this->assertEquals(1, $buckets['belum_tempo']['count']);
        $this->assertEquals(77_000, $buckets['belum_tempo']['nominal']);
        $this->assertEquals(0, $buckets['b1_30']['count']);
    }

    /**
     * Boundary overdue = 1 hari TEPAT → b1_30 (batas bawah bucket pertama).
     */
    public function test_overdue_satu_hari_masuk_b1_30(): void
    {
        $this->makeHutang(now()->subDay()->toDateString(), 55_000);

        $buckets = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk()
            ->json('data.buckets');

        $this->assertEquals(0, $buckets['belum_tempo']['count']);
        $this->assertEquals(1, $buckets['b1_30']['count']);
        $this->assertEquals(55_000, $buckets['b1_30']['nominal']);
    }

    /**
     * Persen tiap bucket harus berjumlah 100% eksak + nominal dibulatkan 2 desimal.
     * Pakai pembagian yang menghasilkan desimal berulang (3 bucket sama besar).
     */
    public function test_percent_berjumlah_100_persen(): void
    {
        $this->makeHutang(now()->subDays(15)->toDateString(), 100_000); // b1_30
        $this->makeHutang(now()->subDays(45)->toDateString(), 100_000); // b31_60
        $this->makeHutang(now()->subDays(75)->toDateString(), 100_000); // b61_90

        $data = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk()
            ->json('data');

        $buckets = $data['buckets'];
        // 100000/300000 = 33.33 (round 2 desimal)
        $this->assertEquals(33.33, $buckets['b1_30']['percent']);
        $this->assertEquals(33.33, $buckets['b31_60']['percent']);
        $this->assertEquals(33.33, $buckets['b61_90']['percent']);
        $this->assertEquals(0, $buckets['belum_tempo']['percent']);
        $this->assertEquals(300_000, $data['total_hutang_outstanding']);
        $this->assertEquals(3, $data['total_count']);
    }

    /**
     * Bucket kosong tetap muncul dengan struktur lengkap (count=0, nominal=0,
     * percent=0). Cegah regresi bila ada bucket yang hilang dari response.
     */
    public function test_struktur_bucket_lengkap_saat_kosong(): void
    {
        $data = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk()
            ->json('data');

        foreach (['belum_tempo', 'b1_30', 'b31_60', 'b61_90', 'above_90'] as $key) {
            $this->assertArrayHasKey($key, $data['buckets'], "bucket {$key} hilang");
            $this->assertEquals(0, $data['buckets'][$key]['count']);
            $this->assertEquals(0.0, $data['buckets'][$key]['nominal']);
            $this->assertEquals(0, $data['buckets'][$key]['percent']);
        }
        $this->assertEquals(0, $data['total_hutang_outstanding']);
        $this->assertEquals(0, $data['total_count']);
    }

    /**
     * Hutang dengan sisa_hutang = 0 dikecualikan walau status masih 'unpaid'
     * (filter pakai sisa_hutang > 0, bukan status). Hanya yang sisa > 0 ikut.
     */
    public function test_sisa_nol_dikecualikan_walau_status_unpaid(): void
    {
        $this->makeHutang(now()->subDays(15)->toDateString(), 0, 'unpaid'); // sisa 0 → out
        $this->makeHutang(now()->subDays(15)->toDateString(), 250_000, 'unpaid'); // in

        $data = $this->actingAs($this->viewerWithNominal)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(250_000, $data['total_hutang_outstanding']);
        $this->assertEquals(1, $data['total_count']);
        $this->assertEquals(1, $data['buckets']['b1_30']['count']);
    }

    public function test_index_masks_nominal_without_view_nominal_permission(): void
    {
        $this->makeHutang(now()->addDays(7)->toDateString(), 125_000);

        $item = $this->actingAs($this->viewerNoNominal)
            ->getJson('/api/v1/supplier-hutangs')
            ->assertOk()
            ->json('data.items.0');

        $this->assertArrayNotHasKey('nominal_awal', $item);
        $this->assertArrayNotHasKey('nominal_terbayar', $item);
        $this->assertArrayNotHasKey('sisa_hutang', $item);
    }

    public function test_show_masks_nominal_without_view_nominal_permission(): void
    {
        $this->makeHutang(now()->addDays(7)->toDateString(), 88_000);
        $ulid = DB::table('supplier_hutang')->orderByDesc('id')->value('ulid');

        $hutang = $this->actingAs($this->viewerNoNominal)
            ->getJson("/api/v1/supplier-hutangs/{$ulid}")
            ->assertOk()
            ->json('data.hutang');

        $this->assertArrayNotHasKey('nominal_awal', $hutang);
        $this->assertArrayNotHasKey('sisa_hutang', $hutang);
    }

    public function test_summary_nulls_totals_without_view_nominal_permission(): void
    {
        $this->makeHutang(now()->addDays(7)->toDateString(), 200_000);

        $summary = $this->actingAs($this->viewerNoNominal)
            ->getJson('/api/v1/supplier-hutangs/summary')
            ->assertOk()
            ->json('data.summary');

        $this->assertNull($summary['total_hutang']);
        $this->assertNull($summary['total_overdue_amount']);
        $this->assertGreaterThan(0, $summary['total_unpaid']);
    }

    public function test_by_supplier_masks_nominal_without_view_nominal_permission(): void
    {
        $this->makeHutang(now()->addDays(7)->toDateString(), 150_000);

        $item = $this->actingAs($this->viewerNoNominal)
            ->getJson('/api/v1/supplier-hutangs/by-supplier?supplier_id='.$this->supplierId)
            ->assertOk()
            ->json('data.items.0');

        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('sisa_hutang', $item);
    }

    public function test_export_ok_without_view_nominal_when_laporan_export_granted(): void
    {
        Permission::firstOrCreate(['name' => 'laporan.export', 'guard_name' => 'web']);
        $exporter = User::factory()->create();
        $exporter->givePermissionTo(['hutang.view', 'laporan.export']);

        $this->makeHutang(now()->addDays(7)->toDateString(), 99_000);

        $this->actingAs($exporter)
            ->get('/api/v1/supplier-hutangs/export')
            ->assertOk();
    }
}
