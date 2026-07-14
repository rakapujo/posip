<?php

namespace Tests\Feature\Reports;

use App\Models\DocPromo;
use App\Models\MasterProduk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProductPromoReportTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'laporan.promo', 'guard_name' => 'web']);
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo('laporan.promo');
    }

    private function makeKategori(string $kode, string $nama): int
    {
        $tipeId = DB::table('master_tipe')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_tipe' => 'TIP-' . Str::random(4),
            'nama_tipe' => 'Tipe',
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('master_kategori')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_kategori' => $kode,
            'nama_kategori' => $nama,
            'tipe_id' => $tipeId,
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function makePromo(string $kode, string $nama, bool $effective = true): DocPromo
    {
        return DocPromo::create([
            'ulid' => (string) Str::ulid(),
            'kode_promo' => $kode,
            'nama_promo' => $nama,
            'tanggal_mulai' => now()->subDays(5)->toDateString(),
            'tanggal_selesai' => $effective ? now()->addDays(5)->toDateString() : now()->subDay()->toDateString(),
            'status' => 'approved',
            'created_by' => $this->viewer->id,
        ]);
    }

    private function makePromoDetail(int $promoId, string $targetType, ?int $targetId, array $diskon = []): int
    {
        return DB::table('doc_promo_details')->insertGetId(array_merge([
            'promo_id' => $promoId,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'min_qty' => 1,
            'diskon_1_tipe' => $diskon['d1_tipe'] ?? 'percent',
            'diskon_1_nilai' => $diskon['d1_nilai'] ?? 10,
            'diskon_2_tipe' => 'none',
            'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none',
            'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',
            'diskon_4_nilai' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]));
    }

    public function test_requires_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/reports/product-promo/by-product')
            ->assertForbidden();

        $this->actingAs($other)
            ->getJson('/api/v1/reports/product-promo/by-promo')
            ->assertForbidden();
    }

    public function test_by_product_lists_products_with_eligible_promos(): void
    {
        $kat = $this->makeKategori('KAT-A', 'Kategori A');
        $p1 = MasterProduk::factory()->create(['kode_produk' => 'P1', 'kategori_id' => $kat, 'status' => 'active']);
        $p2 = MasterProduk::factory()->create(['kode_produk' => 'P2', 'kategori_id' => $kat, 'status' => 'active']);
        $pOther = MasterProduk::factory()->create(['kode_produk' => 'PO', 'kategori_id' => null, 'status' => 'active']);

        $promo = $this->makePromo('PRM-A', 'Promo A');
        $this->makePromoDetail($promo->id, 'kategori', $kat);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-product')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('kode_produk');

        // P1 dan P2 punya 1 promo, PO punya 0
        $this->assertEquals(1, $items->get('P1')['promo_count']);
        $this->assertEquals(1, $items->get('P2')['promo_count']);
        $this->assertEquals(0, $items->get('PO')['promo_count']);

        // Detail promo yang cover P1: via kategori
        $this->assertEquals('PRM-A', $items->get('P1')['promos'][0]['kode_promo']);
        $this->assertEquals('kategori', $items->get('P1')['promos'][0]['cover_type']);
    }

    public function test_by_product_only_with_promo_filter(): void
    {
        $kat = $this->makeKategori('KAT-B', 'Kategori B');
        MasterProduk::factory()->create(['kode_produk' => 'HAS', 'kategori_id' => $kat, 'status' => 'active']);
        MasterProduk::factory()->create(['kode_produk' => 'NONE', 'kategori_id' => null, 'status' => 'active']);

        $promo = $this->makePromo('PRM-B', 'Promo B');
        $this->makePromoDetail($promo->id, 'kategori', $kat);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-product?only_with_promo=1')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('HAS', $items[0]['kode_produk']);
    }

    public function test_by_product_target_semua_covers_all_active_products(): void
    {
        MasterProduk::factory()->create(['kode_produk' => 'X1', 'status' => 'active']);
        MasterProduk::factory()->create(['kode_produk' => 'X2', 'status' => 'active']);

        $promo = $this->makePromo('PRM-ALL', 'Promo Global');
        $this->makePromoDetail($promo->id, 'semua', null);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-product')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('kode_produk');
        $this->assertGreaterThanOrEqual(1, $items->get('X1')['promo_count']);
        $this->assertGreaterThanOrEqual(1, $items->get('X2')['promo_count']);
    }

    public function test_by_promo_shows_products_covered(): void
    {
        $kat = $this->makeKategori('KAT-C', 'Kategori C');
        $p1 = MasterProduk::factory()->create(['kode_produk' => 'C1', 'kategori_id' => $kat, 'status' => 'active']);
        MasterProduk::factory()->create(['kode_produk' => 'C2', 'kategori_id' => $kat, 'status' => 'active']);

        $promo = $this->makePromo('PRM-C', 'Promo C');
        $this->makePromoDetail($promo->id, 'kategori', $kat);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-promo')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals('PRM-C', $items[0]['kode_promo']);
        $this->assertEquals(2, $items[0]['product_count']);
        $this->assertCount(1, $items[0]['details']);
        $this->assertEquals('kategori', $items[0]['details'][0]['target_type']);
    }

    public function test_by_promo_specific_product_target(): void
    {
        $p1 = MasterProduk::factory()->create(['kode_produk' => 'SP1', 'status' => 'active']);
        MasterProduk::factory()->create(['kode_produk' => 'SP2', 'status' => 'active']);

        $promo = $this->makePromo('PRM-SP', 'Promo Specific');
        $this->makePromoDetail($promo->id, 'produk', $p1->id);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-promo')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals(1, $items[0]['product_count']);
        $this->assertEquals('SP1', $items[0]['products'][0]['kode_produk']);
    }

    public function test_status_filter_active_now_excludes_expired(): void
    {
        $kat = $this->makeKategori('KAT-E', 'Expired Cat');
        MasterProduk::factory()->create(['kode_produk' => 'E1', 'kategori_id' => $kat, 'status' => 'active']);

        $expired = $this->makePromo('PRM-EXP', 'Expired', effective: false);
        $this->makePromoDetail($expired->id, 'kategori', $kat);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-promo?status=active_now')
            ->assertOk();

        $this->assertCount(0, $response->json('data.items'));

        // Dengan status=approved_all → muncul
        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-promo?status=approved_all')
            ->assertOk();

        $this->assertCount(1, $response->json('data.items'));
    }

    // ─── EDGE CASES (galak) ──────────────────────────────────────────────

    private function makeProduk(string $kode, ?int $kategoriId = null, ?int $grupId = null, string $status = 'active'): MasterProduk
    {
        return MasterProduk::factory()->create([
            'kode_produk' => $kode,
            'kategori_id' => $kategoriId,
            'grup_id' => $grupId,
            'status' => $status,
        ]);
    }

    private function makeGrup(string $kode, string $nama): int
    {
        // master_grup.kategori_id NOT NULL — buat kategori induk dulu.
        $katId = $this->makeKategori('KAT-' . Str::random(5), 'Kat induk grup');

        return DB::table('master_grup')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kategori_id' => $katId,
            'kode_grup' => $kode,
            'nama_grup' => $nama,
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * Boundary: tanpa produk & tanpa promo → by-product items kosong, by-promo kosong, tak error.
     */
    public function test_kosong_total_struktur_valid(): void
    {
        $byProduct = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-product')
            ->assertOk();
        $this->assertCount(0, $byProduct->json('data.items'));
        $this->assertEquals(0, $byProduct->json('data.pagination.total'));

        $byPromo = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-promo')
            ->assertOk();
        $this->assertCount(0, $byPromo->json('data.items'));
    }

    /**
     * Satu produk dicover OLEH 2 promo berbeda → promo_count = 2 (dedupe per promo).
     */
    public function test_by_product_dua_promo_meng_cover_produk_sama(): void
    {
        $kat = $this->makeKategori('KAT-MULTI', 'Multi');
        $this->makeProduk('PM', $kat);

        $p1 = $this->makePromo('PRM-1', 'Promo 1');
        $this->makePromoDetail($p1->id, 'kategori', $kat);
        $p2 = $this->makePromo('PRM-2', 'Promo 2');
        $this->makePromoDetail($p2->id, 'semua', null);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/product-promo/by-product')
                ->assertOk()->json('data.items')
        )->keyBy('kode_produk');

        $this->assertEquals(2, $items->get('PM')['promo_count']);
        $kodes = collect($items->get('PM')['promos'])->pluck('kode_promo')->sort()->values()->all();
        $this->assertEquals(['PRM-1', 'PRM-2'], $kodes);
    }

    /**
     * Satu promo dengan DUA detail (kategori + semua) yang sama-sama cover produk →
     * promo TETAP dihitung sekali (dedupe per promo_id), promo_count = 1.
     */
    public function test_by_product_dedupe_promo_id_walau_dua_detail_overlap(): void
    {
        $kat = $this->makeKategori('KAT-OVL', 'Overlap');
        $this->makeProduk('POVL', $kat);

        $promo = $this->makePromo('PRM-OVL', 'Overlap');
        $this->makePromoDetail($promo->id, 'kategori', $kat);
        $this->makePromoDetail($promo->id, 'semua', null);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/product-promo/by-product')
                ->assertOk()->json('data.items')
        )->keyBy('kode_produk');

        $this->assertEquals(1, $items->get('POVL')['promo_count']);
    }

    /**
     * findMatchingDetailForProduct: prioritas produk-spesifik > kategori (cover_type 'produk').
     */
    public function test_by_product_cover_type_prioritas_produk_diatas_kategori(): void
    {
        $kat = $this->makeKategori('KAT-PRI', 'Prioritas');
        $prod = $this->makeProduk('PRI', $kat);

        $promo = $this->makePromo('PRM-PRI', 'Prioritas');
        $this->makePromoDetail($promo->id, 'kategori', $kat, ['d1_nilai' => 5]);
        $this->makePromoDetail($promo->id, 'produk', $prod->id, ['d1_nilai' => 20]);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/product-promo/by-product')
                ->assertOk()->json('data.items')
        )->keyBy('kode_produk');

        $promoRow = $items->get('PRI')['promos'][0];
        $this->assertEquals('produk', $promoRow['cover_type']);
        // Diskon yang ditampilkan dari detail produk-spesifik (nilai 20), bukan kategori (5)
        $this->assertEquals(20, $promoRow['diskon']['slot_1']['nilai']);
    }

    /**
     * cover via grup → cover_type 'grup'.
     */
    public function test_by_product_cover_via_grup(): void
    {
        $grup = $this->makeGrup('GRP-A', 'Grup A');
        $this->makeProduk('PG', null, $grup);

        $promo = $this->makePromo('PRM-G', 'Promo Grup');
        $this->makePromoDetail($promo->id, 'grup', $grup);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/product-promo/by-product')
                ->assertOk()->json('data.items')
        )->keyBy('kode_produk');

        $this->assertEquals(1, $items->get('PG')['promo_count']);
        $this->assertEquals('grup', $items->get('PG')['promos'][0]['cover_type']);
    }

    /**
     * formatDiskon: hanya slot dengan tipe!='none' DAN nilai>0 yang muncul.
     */
    public function test_by_product_format_diskon_skip_slot_kosong(): void
    {
        $kat = $this->makeKategori('KAT-DSK', 'Diskon');
        $this->makeProduk('PDSK', $kat);

        $promo = $this->makePromo('PRM-DSK', 'Diskon');
        // slot 1 percent 10 (default helper), slot 2-4 none
        $this->makePromoDetail($promo->id, 'kategori', $kat);

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/product-promo/by-product')
                ->assertOk()->json('data.items')
        )->keyBy('kode_produk');

        $diskon = $items->get('PDSK')['promos'][0]['diskon'];
        $this->assertArrayHasKey('slot_1', $diskon);
        $this->assertArrayNotHasKey('slot_2', $diskon);
        $this->assertArrayNotHasKey('slot_3', $diskon);
        $this->assertArrayNotHasKey('slot_4', $diskon);
        $this->assertEquals('percent', $diskon['slot_1']['tipe']);
        $this->assertEquals(10, $diskon['slot_1']['nilai']);
    }

    /**
     * target 'semua' HANYA cover produk status active (expandTargetToProductIds filter status).
     * Produk inactive tidak masuk product_count untuk target semua.
     */
    public function test_by_promo_target_semua_kecualikan_produk_inactive(): void
    {
        $this->makeProduk('ACT', null, null, 'active');
        $this->makeProduk('INACT', null, null, 'inactive');

        $promo = $this->makePromo('PRM-ALL', 'Promo Semua');
        $this->makePromoDetail($promo->id, 'semua', null);

        $item = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-promo')
            ->assertOk()->json('data.items')[0];

        $this->assertEquals(1, $item['product_count']);
        $this->assertEquals('ACT', $item['products'][0]['kode_produk']);
    }

    /**
     * status=upcoming hanya menampilkan promo yang tanggal_mulai > hari ini.
     */
    public function test_by_promo_status_upcoming(): void
    {
        $kat = $this->makeKategori('KAT-UP', 'Upcoming');
        $this->makeProduk('PUP', $kat);

        // Promo yang baru mulai 3 hari lagi
        DocPromo::create([
            'ulid' => (string) Str::ulid(),
            'kode_promo' => 'PRM-UP',
            'nama_promo' => 'Upcoming',
            'tanggal_mulai' => now()->addDays(3)->toDateString(),
            'tanggal_selesai' => now()->addDays(10)->toDateString(),
            'status' => 'approved',
            'created_by' => $this->viewer->id,
        ])->id;
        $up = DocPromo::where('kode_promo', 'PRM-UP')->first();
        $this->makePromoDetail($up->id, 'kategori', $kat);

        // Promo aktif sekarang (tidak boleh muncul di upcoming)
        $now = $this->makePromo('PRM-NOW', 'Now');
        $this->makePromoDetail($now->id, 'kategori', $kat);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-promo?status=upcoming')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('PRM-UP', $items[0]['kode_promo']);
    }

    /**
     * status=expired hanya menampilkan promo yang tanggal_selesai < hari ini.
     */
    public function test_by_promo_status_expired(): void
    {
        $kat = $this->makeKategori('KAT-EXP', 'Exp');
        $this->makeProduk('PEXP', $kat);

        $expired = $this->makePromo('PRM-EXP2', 'Expired', effective: false); // selesai kemarin
        $this->makePromoDetail($expired->id, 'kategori', $kat);

        $active = $this->makePromo('PRM-ACT2', 'Active');
        $this->makePromoDetail($active->id, 'kategori', $kat);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-promo?status=expired')
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('PRM-EXP2', $items[0]['kode_promo']);
    }

    /**
     * Filter product_status=inactive pada by-product hanya mengembalikan produk inactive.
     */
    public function test_by_product_filter_product_status_inactive(): void
    {
        $kat = $this->makeKategori('KAT-PS', 'PS');
        $this->makeProduk('AKTIF', $kat, null, 'active');
        $this->makeProduk('NONAKTIF', $kat, null, 'inactive');

        $items = collect(
            $this->actingAs($this->viewer)
                ->getJson('/api/v1/reports/product-promo/by-product?product_status=inactive')
                ->assertOk()->json('data.items')
        )->keyBy('kode_produk');

        $this->assertCount(1, $items);
        $this->assertTrue($items->has('NONAKTIF'));
        $this->assertFalse($items->has('AKTIF'));
    }

    /**
     * Filter kategori_id pada by-product membatasi produk yang dilist.
     */
    public function test_by_product_filter_kategori_id(): void
    {
        $katA = $this->makeKategori('KAT-FA', 'FA');
        $katB = $this->makeKategori('KAT-FB', 'FB');
        $this->makeProduk('INA', $katA);
        $this->makeProduk('INB', $katB);

        $items = $this->actingAs($this->viewer)
            ->getJson("/api/v1/reports/product-promo/by-product?kategori_id={$katA}")
            ->assertOk()->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals('INA', $items[0]['kode_produk']);
    }

    /**
     * Paginasi by-product: per_page & metadata eksak.
     */
    public function test_by_product_paginasi(): void
    {
        $kat = $this->makeKategori('KAT-PGN', 'Pgn');
        for ($i = 1; $i <= 5; $i++) {
            $this->makeProduk("PP{$i}", $kat);
        }

        $resp = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-product?per_page=2')
            ->assertOk();

        $this->assertCount(2, $resp->json('data.items'));
        $this->assertEquals(5, $resp->json('data.pagination.total'));
        $this->assertEquals(3, $resp->json('data.pagination.last_page'));
    }

    /**
     * by-promo target produk-spesifik: target_label menyebut nama produk; min_qty terbawa.
     */
    public function test_by_promo_detail_label_dan_min_qty(): void
    {
        $prod = $this->makeProduk('LBL', null);

        $promo = $this->makePromo('PRM-LBL', 'Label');
        DB::table('doc_promo_details')->insert([
            'promo_id' => $promo->id,
            'target_type' => 'produk',
            'target_id' => $prod->id,
            'min_qty' => 12,
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 15,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $item = $this->actingAs($this->viewer)
            ->getJson('/api/v1/reports/product-promo/by-promo')
            ->assertOk()->json('data.items')[0];

        $detail = $item['details'][0];
        $this->assertEquals('produk', $detail['target_type']);
        $this->assertEquals(12, $detail['min_qty']);
        $this->assertStringContainsString($prod->nama_produk, $detail['target_label']);
        $this->assertEquals(15, $detail['diskon']['slot_1']['nilai']);
    }
}
