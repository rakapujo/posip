<?php

namespace Tests\Feature\Promo;

use App\Models\DocPromo;
use App\Models\DocPromoDetail;
use App\Models\MasterPosTerminal;
use App\Models\MasterWarehouse;
use App\Models\User;
use App\Services\PromoService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Tests for PromoService: getActivePromos, simulatePromo, findBestPromo.
 *
 * Direct-layer tests — no HTTP, calls service methods directly.
 * Matches the pattern established in PromoCrudTest and CheckoutSalesActionTest.
 */
class PromoServiceTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Ensure promo feature is enabled for each test
        SettingService::set('promo.enabled', true, 'boolean');
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function makeApprovedPromo(array $overrides = []): DocPromo
    {
        return DocPromo::create(array_merge([
            'kode_promo'    => 'PM-' . Str::random(6),
            'nama_promo'    => 'Test Promo',
            // Use subDay() so stored '2026-04-13 00:00:00' is clearly <= today's date string
            // (SQLite stores date cast values as Y-m-d H:i:s, causing today() == today comparison to fail)
            'tanggal_mulai' => today()->subDay()->toDateString(),
            'status'        => 'approved',
            'approved_at'   => now(),
            'approved_by'   => $this->user->id,
            'created_by'    => $this->user->id,
        ], $overrides));
    }

    private function addDetail(DocPromo $promo, array $overrides = []): DocPromoDetail
    {
        return $promo->details()->create(array_merge([
            'target_type'    => 'semua',
            'min_qty'        => 1,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 10,
            'diskon_2_tipe'  => 'none',
            'diskon_2_nilai' => 0,
            'diskon_3_tipe'  => 'none',
            'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none',
            'diskon_4_nilai' => 0,
        ], $overrides));
    }

    private function makeTerminal(MasterWarehouse $warehouse, string $kode = 'TRM-X'): MasterPosTerminal
    {
        return MasterPosTerminal::create([
            'ulid'          => (string) Str::ulid(),
            'kode_terminal' => $kode,
            'nama_terminal' => $kode,
            'warehouse_id'  => $warehouse->id,
            'status'        => 'active',
            'created_by'    => $this->user->id,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // getActivePromos
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function get_active_promos_returns_only_effective_promos(): void
    {
        // Approved + starts today → should appear
        $active = $this->makeApprovedPromo(['nama_promo' => 'Active']);
        $this->addDetail($active);

        // Draft → should not appear
        DocPromo::create([
            'kode_promo'    => 'PM-DRAFT',
            'nama_promo'    => 'Draft',
            'tanggal_mulai' => today()->toDateString(),
            'status'        => 'draft',
            'created_by'    => $this->user->id,
        ]);

        // Approved but starts tomorrow → upcoming, not effective
        $upcoming = $this->makeApprovedPromo([
            'nama_promo'    => 'Upcoming',
            'tanggal_mulai' => today()->addDay()->toDateString(),
        ]);
        $this->addDetail($upcoming);

        // Approved but expired yesterday → should not appear
        $expired = $this->makeApprovedPromo([
            'nama_promo'      => 'Expired',
            'tanggal_mulai'   => today()->subDays(5)->toDateString(),
            'tanggal_selesai' => today()->subDay()->toDateString(),
        ]);
        $this->addDetail($expired);

        $result = PromoService::getActivePromos(null, null);

        $this->assertCount(1, $result);
        $this->assertEquals('Active', $result->first()->nama_promo);
    }
    #[Test]
    public function get_active_promos_filters_by_terminal_id(): void
    {
        $warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $terminal1 = $this->makeTerminal($warehouse, 'TRM-1A');
        $terminal2 = $this->makeTerminal($warehouse, 'TRM-1B');

        $promoT1 = $this->makeApprovedPromo(['nama_promo' => 'T1 Promo', 'terminal_id' => $terminal1->id]);
        $this->addDetail($promoT1);

        $promoT2 = $this->makeApprovedPromo(['nama_promo' => 'T2 Promo', 'terminal_id' => $terminal2->id]);
        $this->addDetail($promoT2);

        $result = PromoService::getActivePromos($terminal1->id, null);

        $names = $result->pluck('nama_promo')->all();
        $this->assertContains('T1 Promo', $names);
        $this->assertNotContains('T2 Promo', $names);
    }
    #[Test]
    public function get_active_promos_includes_global_null_terminal(): void
    {
        $warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $terminal = $this->makeTerminal($warehouse, 'TRM-2A');

        // Global promo (terminal_id = null) must be visible to every terminal
        $global = $this->makeApprovedPromo(['nama_promo' => 'Global', 'terminal_id' => null]);
        $this->addDetail($global);

        // Terminal-specific promo
        $specific = $this->makeApprovedPromo(['nama_promo' => 'Specific', 'terminal_id' => $terminal->id]);
        $this->addDetail($specific);

        $result = PromoService::getActivePromos($terminal->id, null);

        $names = $result->pluck('nama_promo')->all();
        $this->assertContains('Global', $names);
        $this->assertContains('Specific', $names);
    }
    #[Test]
    public function get_active_promos_filters_by_customer_category_id(): void
    {
        $gold = \App\Models\MasterKategoriCustomer::create([
            'ulid'          => (string) Str::ulid(),
            'kode_kategori' => 'GOLD',
            'nama_kategori' => 'Gold Member',
            'status'        => 'active',
        ]);
        $silver = \App\Models\MasterKategoriCustomer::create([
            'ulid'          => (string) Str::ulid(),
            'kode_kategori' => 'SILVER',
            'nama_kategori' => 'Silver Member',
            'status'        => 'active',
        ]);

        $promoGold = $this->makeApprovedPromo(['nama_promo' => 'Gold Promo', 'customer_category_id' => $gold->id]);
        $this->addDetail($promoGold);

        $promoSilver = $this->makeApprovedPromo(['nama_promo' => 'Silver Promo', 'customer_category_id' => $silver->id]);
        $this->addDetail($promoSilver);

        // Gold customer should only see Gold Promo
        $result = PromoService::getActivePromos(null, null, null, $gold->id);

        $names = $result->pluck('nama_promo')->all();
        $this->assertContains('Gold Promo', $names);
        $this->assertNotContains('Silver Promo', $names);
    }
    #[Test]
    public function get_active_promos_includes_global_null_customer_category(): void
    {
        $gold = \App\Models\MasterKategoriCustomer::create([
            'ulid'          => (string) Str::ulid(),
            'kode_kategori' => 'GOLD',
            'nama_kategori' => 'Gold Member',
            'status'        => 'active',
        ]);

        // Global promo (customer_category_id = null) must be visible to every category
        $global = $this->makeApprovedPromo(['nama_promo' => 'Global', 'customer_category_id' => null]);
        $this->addDetail($global);

        // Category-specific promo
        $specific = $this->makeApprovedPromo(['nama_promo' => 'Gold Only', 'customer_category_id' => $gold->id]);
        $this->addDetail($specific);

        $result = PromoService::getActivePromos(null, null, null, $gold->id);

        $names = $result->pluck('nama_promo')->all();
        $this->assertContains('Global', $names);
        $this->assertContains('Gold Only', $names);
    }
    #[Test]
    public function get_active_promos_excludes_category_specific_promo_when_customer_has_no_category(): void
    {
        $gold = \App\Models\MasterKategoriCustomer::create([
            'ulid'          => (string) Str::ulid(),
            'kode_kategori' => 'GOLD',
            'nama_kategori' => 'Gold Member',
            'status'        => 'active',
        ]);

        $global = $this->makeApprovedPromo(['nama_promo' => 'Global', 'customer_category_id' => null]);
        $this->addDetail($global);

        $categorySpecific = $this->makeApprovedPromo(['nama_promo' => 'Gold Only', 'customer_category_id' => $gold->id]);
        $this->addDetail($categorySpecific);

        // Walk-in / customer without category → only global promo visible
        $result = PromoService::getActivePromos(null, null, null, null);

        $names = $result->pluck('nama_promo')->all();
        $this->assertContains('Global', $names);
        $this->assertNotContains('Gold Only', $names);
    }
    #[Test]
    public function get_active_promos_includes_promo_with_jam_window_covering_now(): void
    {
        // Happy Hour 00:00-23:59 → should always be active
        $allDay = $this->makeApprovedPromo([
            'nama_promo'  => 'Happy 24h',
            'jam_mulai'   => '00:00:00',
            'jam_selesai' => '23:59:00',
        ]);
        $this->addDetail($allDay);

        $result = PromoService::getActivePromos(null, null);

        $this->assertContains('Happy 24h', $result->pluck('nama_promo')->all());
    }
    #[Test]
    public function get_active_promos_excludes_promo_with_jam_window_not_covering_now(): void
    {
        $now = \Illuminate\Support\Carbon::parse(today()->toDateString() . ' 14:00:00');

        // Morning Happy Hour 08:00-12:00 → at 14:00 should NOT be active
        $morningOnly = $this->makeApprovedPromo([
            'nama_promo'  => 'Morning Only',
            'jam_mulai'   => '08:00:00',
            'jam_selesai' => '12:00:00',
        ]);
        $this->addDetail($morningOnly);

        // Afternoon Happy Hour 13:00-15:00 → at 14:00 SHOULD be active
        $afternoon = $this->makeApprovedPromo([
            'nama_promo'  => 'Afternoon',
            'jam_mulai'   => '13:00:00',
            'jam_selesai' => '15:00:00',
        ]);
        $this->addDetail($afternoon);

        $result = PromoService::getActivePromos(null, null, $now);

        $names = $result->pluck('nama_promo')->all();
        $this->assertNotContains('Morning Only', $names, 'Promo 08-12 should not apply at 14:00');
        $this->assertContains('Afternoon', $names, 'Promo 13-15 should apply at 14:00');
    }
    #[Test]
    public function get_active_promos_includes_promo_with_null_jam_regardless_of_time(): void
    {
        // No jam restriction → always active during date range
        $anytime = $this->makeApprovedPromo([
            'nama_promo'  => 'Anytime',
            'jam_mulai'   => null,
            'jam_selesai' => null,
        ]);
        $this->addDetail($anytime);

        // Test at an arbitrary hour — should still be active
        $lateNight = \Illuminate\Support\Carbon::parse(today()->toDateString() . ' 03:00:00');
        $result = PromoService::getActivePromos(null, null, $lateNight);

        $this->assertContains('Anytime', $result->pluck('nama_promo')->all());
    }
    #[Test]
    public function get_active_promos_returns_empty_when_setting_disabled(): void
    {
        SettingService::set('promo.enabled', false, 'boolean');

        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo);

        $result = PromoService::getActivePromos(null, null);

        $this->assertTrue($result->isEmpty());
    }

    // ──────────────────────────────────────────────────────────────────
    // simulatePromo
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function simulate_promo_percent_discount_on_semua_target(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);
        $promo->load('details');

        // qty=2, harga=50000 → bruto=100000, 10% = 10000
        $result = PromoService::simulatePromo($promo, 1, null, null, 2, 50000);

        $this->assertNotNull($result);
        $this->assertEquals(10000, $result['total_diskon']);
        $this->assertEquals('percent', $result['diskon_1_tipe']);
        $this->assertEquals(10, $result['diskon_1_nilai']);
        $this->assertEquals($promo->id, $result['promo_id']);
        $this->assertEquals($promo->nama_promo, $result['nama_promo']);
    }
    #[Test]
    public function simulate_promo_nominal_discount(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, ['diskon_1_tipe' => 'nominal', 'diskon_1_nilai' => 5000]);
        $promo->load('details');

        $result = PromoService::simulatePromo($promo, 1, null, null, 1, 50000);

        $this->assertNotNull($result);
        $this->assertEquals(5000, $result['total_diskon']);
        $this->assertEquals('nominal', $result['diskon_1_tipe']);
        $this->assertEquals(5000, $result['diskon_1_nilai']);
    }
    #[Test]
    public function simulate_promo_max_of_slot_takes_highest_rupiah(): void
    {
        // bruto = 100000
        // Detail A slot-1: 5%  = 5000 rupiah
        // Detail B slot-1: nominal 8000 = 8000 rupiah → B wins
        $promo = $this->makeApprovedPromo();

        $this->addDetail($promo, [
            'target_type'    => 'semua',
            'min_qty'        => 1,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 5,
            'diskon_2_tipe'  => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe'  => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none', 'diskon_4_nilai' => 0,
        ]);

        $this->addDetail($promo, [
            'target_type'    => 'semua',
            'min_qty'        => 1,
            'diskon_1_tipe'  => 'nominal',
            'diskon_1_nilai' => 8000,
            'diskon_2_tipe'  => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe'  => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none', 'diskon_4_nilai' => 0,
        ]);

        $promo->load('details');

        $result = PromoService::simulatePromo($promo, 1, null, null, 1, 100000);

        $this->assertNotNull($result);
        $this->assertEquals('nominal', $result['diskon_1_tipe'], 'Nominal 8000 > percent 5% (5000) should win');
        $this->assertEquals(8000, $result['diskon_1_nilai']);
        $this->assertEquals(8000, $result['total_diskon']);
    }
    #[Test]
    public function simulate_promo_min_qty_not_met_returns_null(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, ['min_qty' => 5, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);
        $promo->load('details');

        // qty=3 < min_qty=5 → no qualifying detail
        $result = PromoService::simulatePromo($promo, 1, null, null, 3, 50000);

        $this->assertNull($result);
    }
    #[Test]
    public function simulate_promo_target_produk_match_and_mismatch(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, [
            'target_type'    => 'produk',
            'target_id'      => 7,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 15,
        ]);
        $promo->load('details');

        // Exact product match
        $match = PromoService::simulatePromo($promo, 7, null, null, 1, 100000);
        $this->assertNotNull($match);
        $this->assertEquals(15000, $match['total_diskon']);

        // Different product — no match
        $noMatch = PromoService::simulatePromo($promo, 99, null, null, 1, 100000);
        $this->assertNull($noMatch);
    }
    #[Test]
    public function simulate_promo_target_grup_match_and_mismatch(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, [
            'target_type'    => 'grup',
            'target_id'      => 3,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 10,
        ]);
        $promo->load('details');

        $match = PromoService::simulatePromo($promo, 1, 3, null, 1, 100000);
        $this->assertNotNull($match);

        $noMatch = PromoService::simulatePromo($promo, 1, 99, null, 1, 100000);
        $this->assertNull($noMatch);
    }
    #[Test]
    public function simulate_promo_target_kategori_match_and_mismatch(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, [
            'target_type'    => 'kategori',
            'target_id'      => 5,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 10,
        ]);
        $promo->load('details');

        $match = PromoService::simulatePromo($promo, 1, null, 5, 1, 100000);
        $this->assertNotNull($match);

        $noMatch = PromoService::simulatePromo($promo, 1, null, 99, 1, 100000);
        $this->assertNull($noMatch);
    }
    #[Test]
    public function simulate_promo_recursive_mode_applies_discount_on_running_balance(): void
    {
        // slot-1: 10% of 100000 = 10000, running = 90000
        // slot-2: 10% of 90000  =  9000 (recursive)
        // total = 19000
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, [
            'diskon_1_tipe'  => 'percent', 'diskon_1_nilai' => 10,
            'diskon_2_tipe'  => 'percent', 'diskon_2_nilai' => 10,
            'diskon_3_tipe'  => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none',    'diskon_4_nilai' => 0,
        ]);
        $promo->load('details');

        $result = PromoService::simulatePromo($promo, 1, null, null, 1, 100000, 'recursive');

        $this->assertNotNull($result);
        $this->assertEquals(19000, $result['total_diskon']);
    }
    #[Test]
    public function simulate_promo_sum_mode_applies_all_discounts_on_bruto(): void
    {
        // slot-1: 10% of 100000 = 10000
        // slot-2: 10% of 100000 = 10000 (sum — both applied to original bruto)
        // total = 20000
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, [
            'diskon_1_tipe'  => 'percent', 'diskon_1_nilai' => 10,
            'diskon_2_tipe'  => 'percent', 'diskon_2_nilai' => 10,
            'diskon_3_tipe'  => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none',    'diskon_4_nilai' => 0,
        ]);
        $promo->load('details');

        $result = PromoService::simulatePromo($promo, 1, null, null, 1, 100000, 'sum');

        $this->assertNotNull($result);
        $this->assertEquals(20000, $result['total_diskon']);
    }
    #[Test]
    public function simulate_promo_returns_null_when_bruto_is_zero(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo);
        $promo->load('details');

        // qty=0 → bruto=0
        $result = PromoService::simulatePromo($promo, 1, null, null, 0, 10000);

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────────────────────────────
    // findBestPromo
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function find_best_promo_returns_highest_discount(): void
    {
        // Promo A: 5%  of 100000 = 5000
        $promoA = $this->makeApprovedPromo(['nama_promo' => 'Promo A']);
        $this->addDetail($promoA, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 5]);

        // Promo B: 15% of 100000 = 15000 → should win
        $promoB = $this->makeApprovedPromo(['nama_promo' => 'Promo B']);
        $this->addDetail($promoB, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 15]);

        $promoA->load('details');
        $promoB->load('details');

        $result = PromoService::findBestPromo(1, null, null, 1, 100000, collect([$promoA, $promoB]));

        $this->assertNotNull($result);
        $this->assertEquals('Promo B', $result['nama_promo']);
        $this->assertEquals(15000, $result['total_diskon']);
    }
    #[Test]
    public function find_best_promo_returns_null_when_no_promo_matches(): void
    {
        // Promo only applies to product_id=999; we query product_id=1
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, [
            'target_type'    => 'produk',
            'target_id'      => 999,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 10,
        ]);
        $promo->load('details');

        $result = PromoService::findBestPromo(1, null, null, 1, 100000, collect([$promo]));

        $this->assertNull($result);
    }
    #[Test]
    public function find_best_promo_returns_null_when_collection_empty(): void
    {
        $result = PromoService::findBestPromo(1, null, null, 1, 100000, collect());

        $this->assertNull($result);
    }
    #[Test]
    public function find_best_promo_returns_null_when_qty_is_zero(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo);
        $promo->load('details');

        $result = PromoService::findBestPromo(1, null, null, 0, 100000, collect([$promo]));

        $this->assertNull($result);
    }
    #[Test]
    public function find_best_promo_returns_null_when_harga_is_zero(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo);
        $promo->load('details');

        $result = PromoService::findBestPromo(1, null, null, 1, 0, collect([$promo]));

        $this->assertNull($result);
    }

    // ──────────────────────────────────────────────────────────────────
    // EDGE CASES TAMBAHAN (galak): nilai eksak, batas, prioritas target
    // ──────────────────────────────────────────────────────────────────

    /**
     * Nominal diskon yang LEBIH BESAR dari bruto WAJIB di-cap ke bruto
     * (calculateDiscountLevel: nominal => min($nilai, $base)).
     * Tanpa cap, total_diskon bisa melebihi harga → bug fatal.
     */
    #[Test]
    public function simulate_promo_nominal_lebih_besar_dari_bruto_di_cap_ke_bruto(): void
    {
        $promo = $this->makeApprovedPromo();
        // nominal 90000 sedangkan bruto cuma 50000 → harus jadi 50000
        $this->addDetail($promo, ['diskon_1_tipe' => 'nominal', 'diskon_1_nilai' => 90000]);
        $promo->load('details');

        $result = PromoService::simulatePromo($promo, 1, null, null, 1, 50000);

        $this->assertNotNull($result);
        $this->assertSame(50000.0, $result['total_diskon'], 'Nominal 90000 harus di-cap ke bruto 50000');
        // nilai mentah tetap tersimpan apa adanya (recalc final di CheckoutSalesAction)
        $this->assertEquals('nominal', $result['diskon_1_tipe']);
        $this->assertEquals(90000, $result['diskon_1_nilai']);
    }

    /**
     * Percent menghasilkan pecahan: harus di-round 2 desimal (bukan dibulatkan kasar).
     * 7% dari 33333 = 2333.31 (round half-up dari 2333.310).
     */
    #[Test]
    public function simulate_promo_percent_pecahan_di_round_dua_desimal(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 7]);
        $promo->load('details');

        // bruto = 1 * 33333 = 33333 ; 7% = 2333.31
        $result = PromoService::simulatePromo($promo, 1, null, null, 1, 33333);

        $this->assertNotNull($result);
        $this->assertSame(2333.31, $result['total_diskon']);
    }

    /**
     * min_qty boundary: qty == min_qty (tepat di batas) WAJIB lolos.
     * Komplemen test existing yang hanya cek qty < min_qty.
     */
    #[Test]
    public function simulate_promo_qty_tepat_di_min_qty_boundary_lolos(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, ['min_qty' => 5, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);
        $promo->load('details');

        // qty=5 == min_qty=5 → lolos. bruto = 5 * 20000 = 100000, 10% = 10000
        $atBoundary = PromoService::simulatePromo($promo, 1, null, null, 5, 20000);
        $this->assertNotNull($atBoundary, 'qty tepat di min_qty harus lolos');
        $this->assertSame(10000.0, $atBoundary['total_diskon']);

        // qty=4 == min_qty-1 → gagal
        $belowBoundary = PromoService::simulatePromo($promo, 1, null, null, 4, 20000);
        $this->assertNull($belowBoundary, 'qty kurang 1 dari min_qty harus gagal');
    }

    /**
     * Mode recursive vs sum dengan campuran percent + nominal — nilai eksak.
     * slot-1 percent 20%, slot-2 nominal 5000, bruto 100000.
     * recursive: slot1 = 20%*100000 = 20000 (running=80000); slot2 = min(5000,80000)=5000 → total 25000
     * sum:       slot1 = 20%*100000 = 20000;                 slot2 = min(5000,100000)=5000 → total 25000
     * (di kasus ini sama; tambahkan kasus yang BEDA di bawah)
     */
    #[Test]
    public function simulate_promo_campuran_percent_dan_nominal_recursive_dan_sum(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, [
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 20,
            'diskon_2_tipe' => 'nominal', 'diskon_2_nilai' => 5000,
            'diskon_3_tipe' => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',    'diskon_4_nilai' => 0,
        ]);
        $promo->load('details');

        $recursive = PromoService::simulatePromo($promo, 1, null, null, 1, 100000, 'recursive');
        $sum       = PromoService::simulatePromo($promo, 1, null, null, 1, 100000, 'sum');

        $this->assertSame(25000.0, $recursive['total_diskon']);
        $this->assertSame(25000.0, $sum['total_diskon']);
    }

    /**
     * Mode recursive vs sum yang menghasilkan nilai BERBEDA (dua percent berbeda).
     * slot-1 30%, slot-2 50%, bruto 100000.
     * recursive: 30000 + 50%*70000 (35000) = 65000
     * sum:       30000 + 50%*100000 (50000) = 80000
     */
    #[Test]
    public function simulate_promo_dua_percent_recursive_lebih_kecil_dari_sum(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, [
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 30,
            'diskon_2_tipe' => 'percent', 'diskon_2_nilai' => 50,
            'diskon_3_tipe' => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',    'diskon_4_nilai' => 0,
        ]);
        $promo->load('details');

        $recursive = PromoService::simulatePromo($promo, 1, null, null, 1, 100000, 'recursive');
        $sum       = PromoService::simulatePromo($promo, 1, null, null, 1, 100000, 'sum');

        $this->assertSame(65000.0, $recursive['total_diskon'], 'recursive 30% lalu 50% atas sisa');
        $this->assertSame(80000.0, $sum['total_diskon'], 'sum 30% + 50% keduanya atas bruto');
        $this->assertLessThan($sum['total_diskon'], $recursive['total_diskon']);
    }

    /**
     * Per-slot picking: dua detail mengisi slot BERBEDA (slot-1 dan slot-2),
     * keduanya harus terbawa ke promo yang sama (gabung antar detail).
     * detail A slot-1 = 10% ; detail B slot-2 = 10% ; recursive → 10000 + 9000 = 19000.
     */
    #[Test]
    public function simulate_promo_gabung_slot_dari_detail_berbeda(): void
    {
        $promo = $this->makeApprovedPromo();
        $this->addDetail($promo, [
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10,
            'diskon_2_tipe' => 'none',    'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',    'diskon_4_nilai' => 0,
        ]);
        $this->addDetail($promo, [
            'diskon_1_tipe' => 'none',    'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'percent', 'diskon_2_nilai' => 10,
            'diskon_3_tipe' => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',    'diskon_4_nilai' => 0,
        ]);
        $promo->load('details');

        $result = PromoService::simulatePromo($promo, 1, null, null, 1, 100000, 'recursive');

        $this->assertNotNull($result);
        $this->assertEquals('percent', $result['diskon_1_tipe']);
        $this->assertEquals(10, $result['diskon_1_nilai']);
        $this->assertEquals('percent', $result['diskon_2_tipe']);
        $this->assertEquals(10, $result['diskon_2_nilai']);
        $this->assertSame(19000.0, $result['total_diskon']);
    }

    /**
     * Detail yang TIDAK qualify (min_qty tidak terpenuhi) WAJIB diabaikan
     * sekalipun ada detail lain yang qualify dalam promo yang sama.
     * detail A produk=7 min_qty=10 (gagal) ; detail B semua min_qty=1 5% (lolos).
     */
    #[Test]
    public function simulate_promo_abaikan_detail_tidak_qualify_pakai_detail_lain(): void
    {
        $promo = $this->makeApprovedPromo();
        // Detail besar tapi min_qty tinggi → tidak qualify pada qty=2
        $this->addDetail($promo, [
            'target_type' => 'produk', 'target_id' => 7, 'min_qty' => 10,
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 50,
        ]);
        // Detail kecil tapi qualify
        $this->addDetail($promo, [
            'target_type' => 'semua', 'min_qty' => 1,
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 5,
        ]);
        $promo->load('details');

        // qty=2, harga=50000 → bruto=100000 ; hanya detail 5% berlaku = 5000
        $result = PromoService::simulatePromo($promo, 7, null, null, 2, 50000);

        $this->assertNotNull($result);
        $this->assertSame(5000.0, $result['total_diskon'], 'Hanya detail qualify (5%) yang dipakai, bukan 50%');
    }

    /**
     * findBestPromo tiebreaker: dua promo nilai diskon SAMA → promo TERBARU menang.
     * getActivePromos sort created_at desc, dan loop pakai '>' (bukan '>='),
     * jadi elemen pertama (terbaru) dipertahankan saat seri.
     */
    /**
     * findBestPromo tiebreaker: dua promo nilai diskon SAMA → promo TERBARU menang.
     * getActivePromos sort created_at desc, dan loop pakai '>' (bukan '>='),
     * jadi elemen pertama koleksi (created_at terbesar) dipertahankan saat seri.
     * created_at dibuat eksplisit berbeda agar urutan deterministik.
     */
    #[Test]
    public function find_best_promo_seri_dimenangkan_promo_terbaru(): void
    {
        // Promo lama: created_at 2 jam lalu
        $older = $this->makeApprovedPromo(['nama_promo' => 'Promo Lama']);
        $older->forceFill(['created_at' => now()->subHours(2)])->saveQuietly();
        $this->addDetail($older, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);

        // Promo baru: created_at sekarang
        $newer = $this->makeApprovedPromo(['nama_promo' => 'Promo Baru']);
        $newer->forceFill(['created_at' => now()])->saveQuietly();
        $this->addDetail($newer, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);

        // Urutan koleksi sesuai getActivePromos: terbaru dulu (desc created_at)
        $active = PromoService::getActivePromos(null, null);
        $this->assertEquals('Promo Baru', $active->first()->nama_promo, 'getActivePromos harus urut terbaru dulu');

        $result = PromoService::findBestPromo(1, null, null, 1, 100000, $active);

        $this->assertNotNull($result);
        $this->assertSame(10000.0, $result['total_diskon']);
        $this->assertEquals('Promo Baru', $result['nama_promo'], 'Saat seri, promo terbaru (urutan pertama) menang');
        $this->assertEquals($newer->id, $result['promo_id']);
    }

    /**
     * findBestPromo prioritas: promo target PRODUK (lebih spesifik) memberi diskon
     * lebih besar daripada promo SEMUA → produk menang murni karena nilai rupiah terbesar.
     * Membuktikan pemilihan murni berbasis total diskon, bukan tipe target.
     */
    #[Test]
    public function find_best_promo_pilih_nilai_terbesar_lintas_target_type(): void
    {
        // Promo SEMUA 5%
        $semua = $this->makeApprovedPromo(['nama_promo' => 'Semua 5%']);
        $this->addDetail($semua, ['target_type' => 'semua', 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 5]);
        $semua->load('details');

        // Promo PRODUK 25% (lebih besar)
        $produk = $this->makeApprovedPromo(['nama_promo' => 'Produk 25%']);
        $this->addDetail($produk, ['target_type' => 'produk', 'target_id' => 7, 'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 25]);
        $produk->load('details');

        $result = PromoService::findBestPromo(7, null, null, 1, 100000, collect([$semua, $produk]));

        $this->assertNotNull($result);
        $this->assertEquals('Produk 25%', $result['nama_promo']);
        $this->assertSame(25000.0, $result['total_diskon']);
    }

    /**
     * findBestPromo melewati promo yang match tapi total_diskon = 0
     * (semua slot none) dan memilih promo lain yang valid.
     */
    #[Test]
    public function find_best_promo_lewati_promo_diskon_nol(): void
    {
        $zero = $this->makeApprovedPromo(['nama_promo' => 'Zero']);
        $zero->details()->create([
            'target_type' => 'semua', 'min_qty' => 1,
            'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
        ]);
        $zero->load('details');

        $real = $this->makeApprovedPromo(['nama_promo' => 'Real 10%']);
        $this->addDetail($real, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);
        $real->load('details');

        $result = PromoService::findBestPromo(1, null, null, 1, 100000, collect([$zero, $real]));

        $this->assertNotNull($result);
        $this->assertEquals('Real 10%', $result['nama_promo']);
        $this->assertSame(10000.0, $result['total_diskon']);
    }

    /**
     * getActivePromos: promo dengan customer_type_id spesifik HANYA tampil
     * untuk customer type yang cocok; tipe berbeda tidak melihatnya.
     * Komplemen test customer_category yang sudah ada.
     */
    #[Test]
    public function get_active_promos_filter_by_customer_type_id(): void
    {
        $tipeA = \App\Models\MasterTipeCustomer::create([
            'ulid'       => (string) Str::ulid(),
            'kode_tipe'  => 'RESELLER',
            'nama_tipe'  => 'Reseller',
            'status'     => 'active',
        ]);
        $tipeB = \App\Models\MasterTipeCustomer::create([
            'ulid'       => (string) Str::ulid(),
            'kode_tipe'  => 'UMUM',
            'nama_tipe'  => 'Umum',
            'status'     => 'active',
        ]);

        $promoA = $this->makeApprovedPromo(['nama_promo' => 'Reseller Promo', 'customer_type_id' => $tipeA->id]);
        $this->addDetail($promoA);
        $promoB = $this->makeApprovedPromo(['nama_promo' => 'Umum Promo', 'customer_type_id' => $tipeB->id]);
        $this->addDetail($promoB);

        $result = PromoService::getActivePromos(null, $tipeA->id);

        $names = $result->pluck('nama_promo')->all();
        $this->assertContains('Reseller Promo', $names);
        $this->assertNotContains('Umum Promo', $names);
    }

    /**
     * getActivePromos: promo dengan jam_mulai diisi TAPI jam_selesai null.
     * scopeEffective butuh keduanya untuk lolos jam-window (jam_selesai >= now).
     * jam_selesai null → kondisi orWhere jam gagal → promo TIDAK aktif.
     * Mendokumentasikan perilaku aktual scope.
     */
    #[Test]
    public function get_active_promos_jam_mulai_tanpa_jam_selesai_tidak_aktif(): void
    {
        $now = \Illuminate\Support\Carbon::parse(today()->toDateString() . ' 10:00:00');

        $promo = $this->makeApprovedPromo([
            'nama_promo'  => 'Jam Setengah',
            'jam_mulai'   => '08:00:00',
            'jam_selesai' => null,
        ]);
        $this->addDetail($promo);

        $result = PromoService::getActivePromos(null, null, $now);

        $this->assertNotContains(
            'Jam Setengah',
            $result->pluck('nama_promo')->all(),
            'Promo dengan jam_mulai tapi jam_selesai null tidak lolos scopeEffective'
        );
    }

    /**
     * getActivePromos: batas jam tepat di jam_selesai (boundary inklusif '>=').
     * Promo 08:00-12:00, now tepat 12:00:00 → masih aktif (jam_selesai >= now).
     */
    #[Test]
    public function get_active_promos_jam_tepat_di_jam_selesai_masih_aktif(): void
    {
        $now = \Illuminate\Support\Carbon::parse(today()->toDateString() . ' 12:00:00');

        $promo = $this->makeApprovedPromo([
            'nama_promo'  => 'Pagi 08-12',
            'jam_mulai'   => '08:00:00',
            'jam_selesai' => '12:00:00',
        ]);
        $this->addDetail($promo);

        $result = PromoService::getActivePromos(null, null, $now);

        $this->assertContains('Pagi 08-12', $result->pluck('nama_promo')->all());
    }

    /**
     * getActivePromos: promo dengan tanggal_selesai tepat hari ini (boundary)
     * masih aktif (tanggal_selesai >= today).
     */
    #[Test]
    public function get_active_promos_tanggal_selesai_tepat_hari_ini_masih_aktif(): void
    {
        $promo = $this->makeApprovedPromo([
            'nama_promo'      => 'Berakhir Hari Ini',
            'tanggal_mulai'   => today()->subDays(3)->toDateString(),
            'tanggal_selesai' => today()->toDateString(),
        ]);
        $this->addDetail($promo);

        $result = PromoService::getActivePromos(null, null);

        $this->assertContains('Berakhir Hari Ini', $result->pluck('nama_promo')->all());
    }
}
