<?php

namespace Tests\Feature\Reports;

use App\Models\DocPromo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerPromoReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;
    protected int $tipeVipId;
    protected int $tipeRegularId;
    protected int $katGoldId;
    protected int $katSilverId;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'laporan.promo', 'guard_name' => 'web']);
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo('laporan.promo');

        $this->tipeVipId = $this->makeTipeCustomer('VIP', 'VIP', 'percent', 10);
        $this->tipeRegularId = $this->makeTipeCustomer('REG', 'Reguler', 'none', 0);
        $this->katGoldId = $this->makeKategoriCustomer('GOLD', 'Gold', 'percent', 5);
        $this->katSilverId = $this->makeKategoriCustomer('SILVER', 'Silver', 'none', 0);
    }

    private function makeTipeCustomer(string $kode, string $nama, string $diskonTipe, float $diskonNilai): int
    {
        return DB::table('master_tipe_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_tipe' => $kode,
            'nama_tipe' => $nama,
            'diskon_tipe' => $diskonTipe,
            'diskon_nilai' => $diskonNilai,
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeKategoriCustomer(string $kode, string $nama, string $diskonTipe, float $diskonNilai): int
    {
        return DB::table('master_kategori_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_kategori' => $kode,
            'nama_kategori' => $nama,
            'diskon_tipe' => $diskonTipe,
            'diskon_nilai' => $diskonNilai,
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeCustomer(string $kode, string $nama, ?int $tipeId = null, ?int $katId = null): int
    {
        return DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => $kode,
            'nama' => $nama,
            'telepon' => '08000',
            'tipe_customer_id' => $tipeId,
            'kategori_customer_id' => $katId,
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makePromo(string $kode, string $nama, ?int $tipeId, ?int $katId): DocPromo
    {
        return DocPromo::create([
            'ulid' => (string) Str::ulid(),
            'kode_promo' => $kode,
            'nama_promo' => $nama,
            'customer_type_id' => $tipeId,
            'customer_category_id' => $katId,
            'tanggal_mulai' => now()->subDays(5)->toDateString(),
            'tanggal_selesai' => now()->addDays(5)->toDateString(),
            'status' => 'approved',
            'created_by' => $this->viewer->id,
        ]);
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/customer-promo/summary')
            ->assertForbidden();
    }

    public function test_summary_counts_tipe_and_kategori_with_disc(): void
    {
        // 2 tipe dibuat, VIP has disc, REG tidak
        // 2 kategori, GOLD has disc, SILVER tidak

        $this->makeCustomer('C1', 'Customer 1', $this->tipeVipId);
        $this->makeCustomer('C2', 'Customer 2', $this->tipeRegularId);
        $this->makeCustomer('C3', 'Customer 3', null, $this->katGoldId);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/summary')
            ->assertOk();

        $data = $response->json('data');
        $this->assertEquals(2, $data['tipe_total']);
        $this->assertEquals(1, $data['tipe_with_disc']);
        $this->assertEquals(2, $data['kategori_total']);
        $this->assertEquals(1, $data['kategori_with_disc']);
        $this->assertEquals(3, $data['customer_total']);
        // C1 terjaring (VIP), C3 terjaring (Gold), C2 tidak
        $this->assertEquals(2, $data['customer_terjaring']);
    }

    public function test_by_tipe_shows_disc_nota_and_eligible_line_promos(): void
    {
        $this->makeCustomer('C1', 'Customer VIP 1', $this->tipeVipId);
        $this->makeCustomer('C2', 'Customer VIP 2', $this->tipeVipId);
        $this->makeCustomer('C3', 'Customer Reg', $this->tipeRegularId);

        // Promo global (semua customer dapat)
        $this->makePromo('PRM-GLOBAL', 'Global', null, null);
        // Promo khusus VIP
        $this->makePromo('PRM-VIP', 'VIP Only', $this->tipeVipId, null);
        // Promo khusus kategori Gold (harusnya tidak muncul di tab tipe)
        $this->makePromo('PRM-GOLD', 'Gold Only', null, $this->katGoldId);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/by-tipe')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('kode_tipe');

        // VIP → disc 10%, 2 customer, 2 promo eligible (global + VIP-only)
        $vip = $items->get('VIP');
        $this->assertTrue($vip['disc_nota']['has_disc']);
        $this->assertEquals('10%', $vip['disc_nota']['display']);
        $this->assertEquals(2, $vip['customer_count']);
        $this->assertEquals(2, $vip['promo_count']);
        $kodePromos = collect($vip['promos'])->pluck('kode_promo')->all();
        $this->assertContains('PRM-GLOBAL', $kodePromos);
        $this->assertContains('PRM-VIP', $kodePromos);
        $this->assertNotContains('PRM-GOLD', $kodePromos); // kategori, bukan tipe

        // Reguler → no disc, 1 customer, 1 promo eligible (hanya global)
        $reg = $items->get('REG');
        $this->assertFalse($reg['disc_nota']['has_disc']);
        $this->assertEquals(1, $reg['customer_count']);
        $this->assertEquals(1, $reg['promo_count']);
    }

    public function test_by_kategori_shows_eligible_line_promos(): void
    {
        $this->makeCustomer('G1', 'Customer Gold', null, $this->katGoldId);
        $this->makePromo('PRM-GOLD', 'Gold Only', null, $this->katGoldId);
        $this->makePromo('PRM-VIP', 'VIP Only', $this->tipeVipId, null);  // tidak relevan

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/by-kategori')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('kode_kategori');
        $gold = $items->get('GOLD');
        $this->assertEquals(1, $gold['customer_count']);
        $this->assertEquals(1, $gold['promo_count']);
        $this->assertEquals('PRM-GOLD', $gold['promos'][0]['kode_promo']);
    }

    public function test_by_customer_lists_all_with_terjaring_flag(): void
    {
        $c1 = $this->makeCustomer('C1', 'VIP Customer', $this->tipeVipId);
        $c2 = $this->makeCustomer('C2', 'Plain Customer', $this->tipeRegularId);
        $c3 = $this->makeCustomer('C3', 'Gold Customer', null, $this->katGoldId);

        $this->makePromo('PRM-GLOBAL', 'Global', null, null);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/by-customer')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('kode_customer');
        $this->assertCount(3, $items);

        $this->assertTrue($items->get('C1')['terjaring']); // VIP disc
        $this->assertTrue($items->get('C2')['terjaring']); // via promo global
        $this->assertTrue($items->get('C3')['terjaring']); // Gold disc

        // C1 has disc nota tipe (10%), C3 has disc nota kategori (5%)
        $this->assertTrue($items->get('C1')['disc_nota_tipe']['has_disc']);
        $this->assertFalse($items->get('C1')['disc_nota_kategori']['has_disc']);
        $this->assertTrue($items->get('C3')['disc_nota_kategori']['has_disc']);
    }

    public function test_by_customer_only_terjaring_filter(): void
    {
        $this->makeCustomer('C1', 'VIP', $this->tipeVipId);
        $this->makeCustomer('C2', 'Plain', $this->tipeRegularId);
        // No global promo, so C2 is NOT terjaring

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/by-customer?only_terjaring=1')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('C1', $items[0]['kode_customer']);
    }

    public function test_show_customer_returns_full_breakdown(): void
    {
        $c1Id = $this->makeCustomer('C1', 'Big Customer', $this->tipeVipId, $this->katGoldId);
        $c1Ulid = DB::table('master_customer')->where('id', $c1Id)->value('ulid');

        $this->makePromo('PRM-GLOBAL', 'Global', null, null);
        $this->makePromo('PRM-VIP', 'VIP', $this->tipeVipId, null);
        $this->makePromo('PRM-GOLD', 'Gold', null, $this->katGoldId);

        $response = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/customer-promo/customer/{$c1Ulid}")
            ->assertOk();

        $data = $response->json('data');

        $this->assertEquals('C1', $data['customer']['kode_customer']);
        $this->assertTrue($data['disc_nota']['via_tipe']['has_disc']);
        $this->assertTrue($data['disc_nota']['via_kategori']['has_disc']);
        $this->assertCount(1, $data['promo_line']['via_tipe']);
        $this->assertCount(1, $data['promo_line']['via_kategori']);
        $this->assertCount(1, $data['promo_line']['via_global']);
        $this->assertEquals(3, $data['total_promo_eligible']);
    }

    public function test_show_customer_404_on_unknown(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/customer/01HXFAKE')
            ->assertNotFound();
    }

    // ─── EDGE CASES (galak) ──────────────────────────────────────────────

    /**
     * Boundary: tanpa data sama sekali, struktur tetap valid & semua agregat 0.
     * Tidak boleh error / bagi-nol.
     */
    public function test_summary_kosong_semua_nol_struktur_valid(): void
    {
        // setUp membuat 2 tipe + 2 kategori, tapi belum ada customer.
        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(2, $data['tipe_total']);
        $this->assertEquals(1, $data['tipe_with_disc']);   // hanya VIP
        $this->assertEquals(2, $data['kategori_total']);
        $this->assertEquals(1, $data['kategori_with_disc']); // hanya GOLD
        $this->assertEquals(0, $data['customer_total']);
        $this->assertEquals(0, $data['customer_terjaring']);
        $this->assertEquals(0, $data['promo_aktif']);
    }

    /**
     * Disc nota dengan tipe percent vs none — has_disc HARUS bergantung pada nilai>0,
     * bukan hanya tipe. (Guard formatAutoDisc: tipe!='none' AND nilai>0.)
     */
    public function test_disc_nota_nilai_nol_dianggap_tidak_punya_diskon(): void
    {
        // Tipe baru: percent tapi nilai 0 → tidak boleh has_disc
        $tipeZero = $this->makeTipeCustomer('ZERO', 'Percent Nol', 'percent', 0);
        $this->makeCustomer('CZ', 'Zero Customer', $tipeZero);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/customer-promo/by-tipe')
                ->assertOk()
                ->json('data.items')
        )->keyBy('kode_tipe');

        $zero = $items->get('ZERO');
        $this->assertFalse($zero['disc_nota']['has_disc']);
        $this->assertEquals('-', $zero['disc_nota']['display']);
        $this->assertEquals(0, $zero['disc_nota']['nilai']);

        // tipe_with_disc di summary tetap 1 (cuma VIP) walau ZERO percent
        $summary = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/summary')
            ->assertOk()->json('data');
        $this->assertEquals(1, $summary['tipe_with_disc']);
        $this->assertEquals(3, $summary['tipe_total']); // VIP, REG, ZERO
    }

    /**
     * Display disc nota nominal (rupiah) harus diformat ribuan.
     */
    public function test_disc_nota_nominal_display_rupiah_terformat(): void
    {
        $tipeRp = $this->makeTipeCustomer('RP', 'Nominal', 'nominal', 25000);
        $this->makeCustomer('CR', 'Cust RP', $tipeRp);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/customer-promo/by-tipe')
                ->assertOk()->json('data.items')
        )->keyBy('kode_tipe');

        $rp = $items->get('RP');
        $this->assertTrue($rp['disc_nota']['has_disc']);
        $this->assertEquals('nominal', $rp['disc_nota']['tipe']);
        $this->assertEquals(25000, $rp['disc_nota']['nilai']);
        $this->assertEquals('Rp 25.000', $rp['disc_nota']['display']);
    }

    /**
     * status=approved_all menyertakan promo yang sudah lewat periode (expired),
     * sedangkan default active_now mengecualikannya.
     */
    public function test_by_tipe_status_approved_all_vs_active_now(): void
    {
        $this->makeCustomer('C1', 'VIP', $this->tipeVipId);

        // Promo VIP yang sudah expired (selesai kemarin)
        DocPromo::create([
            'ulid' => (string) Str::ulid(),
            'kode_promo' => 'PRM-EXP',
            'nama_promo' => 'Expired VIP',
            'customer_type_id' => $this->tipeVipId,
            'customer_category_id' => null,
            'tanggal_mulai' => now()->subDays(10)->toDateString(),
            'tanggal_selesai' => now()->subDay()->toDateString(),
            'status' => 'approved',
            'created_by' => $this->viewer->id,
        ]);

        // active_now → promo expired TIDAK muncul
        $vipNow = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/customer-promo/by-tipe')
                ->assertOk()->json('data.items')
        )->keyBy('kode_tipe')->get('VIP');
        $this->assertEquals(0, $vipNow['promo_count']);

        // approved_all → promo expired MUNCUL
        $vipAll = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/customer-promo/by-tipe?status=approved_all')
                ->assertOk()->json('data.items')
        )->keyBy('kode_tipe')->get('VIP');
        $this->assertEquals(1, $vipAll['promo_count']);
        $this->assertEquals('PRM-EXP', $vipAll['promos'][0]['kode_promo']);
    }

    /**
     * Promo draft (belum approved) TIDAK pernah dihitung eligible meski status=approved_all.
     */
    public function test_promo_draft_tidak_pernah_eligible(): void
    {
        $this->makeCustomer('C1', 'VIP', $this->tipeVipId);

        DocPromo::create([
            'ulid' => (string) Str::ulid(),
            'kode_promo' => 'PRM-DRAFT',
            'nama_promo' => 'Draft VIP',
            'customer_type_id' => $this->tipeVipId,
            'customer_category_id' => null,
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'tanggal_selesai' => now()->addDays(5)->toDateString(),
            'status' => 'draft',
            'created_by' => $this->viewer->id,
        ]);

        foreach (['', '?status=approved_all'] as $qs) {
            $vip = collect(
                $this->actingAs($this->viewer)
                    ->getJson("/api/v1/reports/customer-promo/by-tipe{$qs}")
                    ->assertOk()->json('data.items')
            )->keyBy('kode_tipe')->get('VIP');
            $this->assertEquals(0, $vip['promo_count'], "draft tidak boleh eligible (qs={$qs})");
        }
    }

    /**
     * Customer soft-deleted DIKECUALIKAN dari customer_total, customer_terjaring, dan list.
     */
    public function test_customer_soft_deleted_dikecualikan(): void
    {
        $cAlive = $this->makeCustomer('ALIVE', 'Alive', $this->tipeVipId);
        $cDead = $this->makeCustomer('DEAD', 'Dead', $this->tipeVipId);
        DB::table('master_customer')->where('id', $cDead)->update(['deleted_at' => now()]);

        $summary = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/summary')
            ->assertOk()->json('data');
        $this->assertEquals(1, $summary['customer_total']);
        $this->assertEquals(1, $summary['customer_terjaring']);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/customer-promo/by-customer')
                ->assertOk()->json('data.items')
        )->keyBy('kode_customer');
        $this->assertCount(1, $items);
        $this->assertTrue($items->has('ALIVE'));
        $this->assertFalse($items->has('DEAD'));
    }

    /**
     * Paginasi by-customer: per_page menghormati batas & metadata pagination eksak.
     */
    public function test_by_customer_paginasi_per_page(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeCustomer("PG{$i}", "Cust {$i}", $this->tipeRegularId);
        }

        $resp = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/by-customer?per_page=2')
            ->assertOk();

        $this->assertCount(2, $resp->json('data.items'));
        $pg = $resp->json('data.pagination');
        $this->assertEquals(1, $pg['current_page']);
        $this->assertEquals(2, $pg['per_page']);
        $this->assertEquals(5, $pg['total']);
        $this->assertEquals(3, $pg['last_page']); // ceil(5/2)
    }

    /**
     * Filter tipe_id pada by-customer hanya mengembalikan customer dengan tipe tsb.
     */
    public function test_by_customer_filter_tipe_id(): void
    {
        $this->makeCustomer('V1', 'VIP One', $this->tipeVipId);
        $this->makeCustomer('R1', 'Reg One', $this->tipeRegularId);

        $items = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/customer-promo/by-customer?tipe_id={$this->tipeVipId}")
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('V1', $items[0]['kode_customer']);
    }

    /**
     * Search by-customer cocok pada kode ATAU nama (case-insensitive substring).
     */
    public function test_by_customer_search_kode_atau_nama(): void
    {
        $this->makeCustomer('AAA', 'Toko Maju', $this->tipeRegularId);
        $this->makeCustomer('BBB', 'Warung Jaya', $this->tipeRegularId);

        // Search nama
        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/by-customer?search=Maju')
            ->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('AAA', $items[0]['kode_customer']);

        // Search kode
        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/customer-promo/by-customer?search=BBB')
            ->assertOk()->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('BBB', $items[0]['kode_customer']);
    }

    /**
     * Promo global (tipe & kategori null) eligible untuk SEMUA customer di by-customer.
     */
    public function test_promo_global_membuat_semua_customer_terjaring(): void
    {
        $this->makeCustomer('NOPE', 'Tanpa Disc', $this->tipeRegularId); // REG no disc
        $this->makePromo('PRM-GLOBAL', 'Global', null, null);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/customer-promo/by-customer')
                ->assertOk()->json('data.items')
        )->keyBy('kode_customer');

        $this->assertTrue($items->get('NOPE')['terjaring']);
        $this->assertEquals(1, $items->get('NOPE')['promo_line_count']);
    }

    /**
     * showCustomer: customer tanpa tipe & kategori → disc nota none, promo via_global saja.
     */
    public function test_show_customer_tanpa_tipe_kategori_hanya_global(): void
    {
        $cId = $this->makeCustomer('PLAIN', 'Plain', null, null);
        $cUlid = DB::table('master_customer')->where('id', $cId)->value('ulid');

        $this->makePromo('PRM-GLOBAL', 'Global', null, null);
        $this->makePromo('PRM-VIP', 'VIP', $this->tipeVipId, null);   // tidak relevan
        $this->makePromo('PRM-GOLD', 'Gold', null, $this->katGoldId); // tidak relevan

        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/customer-promo/customer/{$cUlid}")
            ->assertOk()->json('data');

        $this->assertFalse($data['disc_nota']['via_tipe']['has_disc']);
        $this->assertFalse($data['disc_nota']['via_kategori']['has_disc']);
        $this->assertCount(0, $data['promo_line']['via_tipe']);
        $this->assertCount(0, $data['promo_line']['via_kategori']);
        $this->assertCount(1, $data['promo_line']['via_global']);
        $this->assertEquals(1, $data['total_promo_eligible']);
    }
}
