<?php

namespace Tests\Feature\Promo;

use App\Models\DocPromo;
use App\Models\DocPromoDetail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * HTTP/API tests for PromoController.
 *
 * Tests all endpoints: index, show, store, update, destroy,
 * approve, cancel, deactivate, reactivate.
 *
 * Follows the Sanctum + Spatie Permission pattern used across
 * the rest of the api test suite.
 */
class PromoApiTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected const BASE = '/api/v1/promos';

    protected function setUp(): void
    {
        parent::setUp();

        // Create all promo-related permissions and an admin role
        $permissions = [
            'promo.view', 'promo.create', 'promo.update',
            'promo.delete', 'promo.approve', 'promo.toggle',
        ];
        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p]);
        }

        $role = Role::create(['name' => 'promo-admin']);
        $role->givePermissionTo($permissions);

        $this->user = User::factory()->create();
        $this->user->assignRole('promo-admin');

        Sanctum::actingAs($this->user);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function makePromo(array $overrides = []): DocPromo
    {
        return DocPromo::create(array_merge([
            'kode_promo'    => 'PM-' . Str::random(6),
            'nama_promo'    => 'Test Promo',
            'tanggal_mulai' => today()->subDay()->toDateString(),
            'status'        => 'draft',
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
            'diskon_2_tipe'  => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe'  => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none', 'diskon_4_nilai' => 0,
        ], $overrides));
    }

    /** Valid payload for store/update */
    private function validPayload(array $overrides = []): array
    {
        return array_merge([
            'nama_promo'    => 'Promo Lebaran',
            'tanggal_mulai' => today()->toDateString(),
            'details' => [[
                'target_type'    => 'semua',
                'min_qty'        => 1,
                'diskon_1_tipe'  => 'percent',
                'diskon_1_nilai' => 10,
                'diskon_2_tipe'  => 'none', 'diskon_2_nilai' => 0,
                'diskon_3_tipe'  => 'none', 'diskon_3_nilai' => 0,
                'diskon_4_tipe'  => 'none', 'diskon_4_nilai' => 0,
            ]],
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────────
    // GET /api/v1/promos  (index)
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function index_returns_paginated_list(): void
    {
        $this->makePromo(['nama_promo' => 'Promo A']);
        $this->makePromo(['nama_promo' => 'Promo B']);

        $response = $this->getJson(self::BASE);

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'items'      => [['ulid', 'kode_promo', 'nama_promo', 'status']],
                'pagination' => ['current_page', 'last_page', 'per_page', 'total'],
            ],
        ]);
        $response->assertJsonPath('data.pagination.total', 2);
    }
    #[Test]
    public function index_attaches_display_status_to_each_item(): void
    {
        $this->makePromo(['status' => 'draft']);

        $response = $this->getJson(self::BASE);

        $response->assertOk();
        $item = $response->json('data.items.0');
        $this->assertArrayHasKey('display_status', $item);
        $this->assertEquals('draft', $item['display_status']);
    }
    #[Test]
    public function index_filters_by_display_status(): void
    {
        $this->makePromo(['status' => 'draft', 'nama_promo' => 'Draft Promo']);
        $approved = $this->makePromo([
            'status'        => 'approved',
            'nama_promo'    => 'Approved Promo',
            'tanggal_mulai' => today()->subDay()->toDateString(),
        ]);

        $response = $this->getJson(self::BASE . '?status=draft');

        $response->assertOk();
        $response->assertJsonPath('data.pagination.total', 1);
        $response->assertJsonPath('data.items.0.nama_promo', 'Draft Promo');
    }
    #[Test]
    public function index_requires_authentication(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson(self::BASE)->assertUnauthorized();
    }
    #[Test]
    public function index_requires_promo_view_permission(): void
    {
        $noPermUser = User::factory()->create(); // no role
        Sanctum::actingAs($noPermUser);

        $this->getJson(self::BASE)->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────
    // GET /api/v1/promos/{ulid}  (show)
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function show_returns_promo_with_details(): void
    {
        $promo = $this->makePromo();
        $this->addDetail($promo);

        $response = $this->getJson(self::BASE . "/{$promo->ulid}");

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                'promo' => ['ulid', 'kode_promo', 'nama_promo', 'status', 'display_status', 'details'],
            ],
        ]);
        $response->assertJsonPath('data.promo.ulid', $promo->ulid);
        $this->assertCount(1, $response->json('data.promo.details'));
    }
    #[Test]
    public function show_returns_404_for_unknown_ulid(): void
    {
        $this->getJson(self::BASE . '/nonexistent-ulid')->assertNotFound();
    }
    #[Test]
    public function show_requires_promo_view_permission(): void
    {
        $promo = $this->makePromo();
        $noPermUser = User::factory()->create();
        Sanctum::actingAs($noPermUser);

        $this->getJson(self::BASE . "/{$promo->ulid}")->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /api/v1/promos  (store)
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function store_creates_draft_promo_and_returns_201(): void
    {
        $response = $this->postJson(self::BASE, $this->validPayload());

        $response->assertCreated();
        $response->assertJsonStructure(['data' => ['promo' => ['ulid', 'kode_promo', 'status', 'details']]]);
        $response->assertJsonPath('data.promo.status', 'draft');

        $kode = $response->json('data.promo.kode_promo');
        $this->assertStringStartsWith('PRM-', $kode);
    }
    #[Test]
    public function store_saves_details_in_database(): void
    {
        $this->postJson(self::BASE, $this->validPayload());

        $this->assertEquals(1, DocPromo::count());
        $this->assertEquals(1, DocPromoDetail::count());
    }
    #[Test]
    public function store_returns_422_when_nama_promo_missing(): void
    {
        $payload = $this->validPayload();
        unset($payload['nama_promo']);

        $this->postJson(self::BASE, $payload)->assertUnprocessable();
    }
    #[Test]
    public function store_returns_422_when_details_empty(): void
    {
        $this->postJson(self::BASE, $this->validPayload(['details' => []]))->assertUnprocessable();
    }
    #[Test]
    public function store_returns_422_when_tanggal_selesai_before_tanggal_mulai(): void
    {
        $payload = $this->validPayload([
            'tanggal_mulai'   => today()->toDateString(),
            'tanggal_selesai' => today()->subDay()->toDateString(),
        ]);

        $this->postJson(self::BASE, $payload)->assertUnprocessable();
    }
    #[Test]
    public function store_returns_422_when_detail_target_type_invalid(): void
    {
        $payload = $this->validPayload();
        $payload['details'][0]['target_type'] = 'invalid_type';

        $this->postJson(self::BASE, $payload)->assertUnprocessable();
    }
    #[Test]
    public function store_requires_promo_create_permission(): void
    {
        $noPermUser = User::factory()->create();
        Sanctum::actingAs($noPermUser);

        $this->postJson(self::BASE, $this->validPayload())->assertForbidden();
    }
    #[Test]
    public function store_persists_customer_category_id(): void
    {
        $kategori = \App\Models\MasterKategoriCustomer::create([
            'ulid'          => (string) Str::ulid(),
            'kode_kategori' => 'GOLD',
            'nama_kategori' => 'Gold Member',
            'status'        => 'active',
        ]);

        $payload = $this->validPayload(['customer_category_id' => $kategori->id]);

        $response = $this->postJson(self::BASE, $payload);

        $response->assertCreated();
        $ulid = $response->json('data.promo.ulid');
        $promo = DocPromo::where('ulid', $ulid)->first();
        $this->assertEquals($kategori->id, $promo->customer_category_id);
        $response->assertJsonPath('data.promo.customer_category.kode_kategori', 'GOLD');
    }
    #[Test]
    public function store_returns_422_when_customer_category_id_does_not_exist(): void
    {
        $payload = $this->validPayload(['customer_category_id' => 99999]);

        $this->postJson(self::BASE, $payload)->assertUnprocessable();
    }

    // ──────────────────────────────────────────────────────────────────
    // PUT /api/v1/promos/{ulid}  (update)
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function update_modifies_draft_promo(): void
    {
        $promo = $this->makePromo();
        $this->addDetail($promo);

        $response = $this->putJson(
            self::BASE . "/{$promo->ulid}",
            $this->validPayload(['nama_promo' => 'Promo Updated'])
        );

        $response->assertOk();
        $response->assertJsonPath('data.promo.nama_promo', 'Promo Updated');

        $this->assertEquals('Promo Updated', $promo->fresh()->nama_promo);
    }
    #[Test]
    public function update_replaces_all_details(): void
    {
        $promo = $this->makePromo();
        $this->addDetail($promo);
        $this->addDetail($promo); // 2 details

        $payload = $this->validPayload(); // 1 detail in payload

        $this->putJson(self::BASE . "/{$promo->ulid}", $payload)->assertOk();

        $this->assertEquals(1, $promo->fresh()->details()->count(), 'Old details replaced by new ones');
    }
    #[Test]
    public function update_returns_422_when_promo_is_approved(): void
    {
        $promo = $this->makePromo(['status' => 'approved']);

        $this->putJson(self::BASE . "/{$promo->ulid}", $this->validPayload())
            ->assertUnprocessable();
    }
    #[Test]
    public function update_persists_customer_category_id(): void
    {
        $kategori = \App\Models\MasterKategoriCustomer::create([
            'ulid'          => (string) Str::ulid(),
            'kode_kategori' => 'GOLD',
            'nama_kategori' => 'Gold Member',
            'status'        => 'active',
        ]);

        $promo = $this->makePromo();
        $this->addDetail($promo);

        $payload = $this->validPayload(['customer_category_id' => $kategori->id]);

        $response = $this->putJson(self::BASE . "/{$promo->ulid}", $payload);

        $response->assertOk();
        $this->assertEquals($kategori->id, $promo->fresh()->customer_category_id);
        $response->assertJsonPath('data.promo.customer_category.kode_kategori', 'GOLD');
    }
    #[Test]
    public function update_returns_404_for_unknown_ulid(): void
    {
        $this->putJson(self::BASE . '/unknown-ulid', $this->validPayload())->assertNotFound();
    }
    #[Test]
    public function update_requires_promo_update_permission(): void
    {
        $promo = $this->makePromo();
        $noPermUser = User::factory()->create();
        Sanctum::actingAs($noPermUser);

        $this->putJson(self::BASE . "/{$promo->ulid}", $this->validPayload())->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────
    // DELETE /api/v1/promos/{ulid}  (destroy)
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function destroy_deletes_draft_promo(): void
    {
        $promo = $this->makePromo();

        $this->deleteJson(self::BASE . "/{$promo->ulid}")->assertOk();

        $this->assertEquals(0, DocPromo::count());
    }
    #[Test]
    public function destroy_cascades_to_details(): void
    {
        $promo = $this->makePromo();
        $this->addDetail($promo);
        $this->addDetail($promo);

        $this->deleteJson(self::BASE . "/{$promo->ulid}")->assertOk();

        $this->assertEquals(0, DocPromoDetail::count());
    }
    #[Test]
    public function destroy_returns_422_when_promo_is_not_draft(): void
    {
        $promo = $this->makePromo(['status' => 'approved']);

        $this->deleteJson(self::BASE . "/{$promo->ulid}")->assertUnprocessable();
        $this->assertEquals(1, DocPromo::count(), 'Promo must not be deleted');
    }
    #[Test]
    public function destroy_returns_404_for_unknown_ulid(): void
    {
        $this->deleteJson(self::BASE . '/unknown-ulid')->assertNotFound();
    }
    #[Test]
    public function destroy_requires_promo_delete_permission(): void
    {
        $promo = $this->makePromo();
        $noPermUser = User::factory()->create();
        Sanctum::actingAs($noPermUser);

        $this->deleteJson(self::BASE . "/{$promo->ulid}")->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /api/v1/promos/{ulid}/approve
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function approve_transitions_draft_to_approved(): void
    {
        $promo = $this->makePromo();
        $this->addDetail($promo);

        $response = $this->postJson(self::BASE . "/{$promo->ulid}/approve");

        $response->assertOk();
        $this->assertEquals('approved', $promo->fresh()->status);
        $this->assertNotNull($promo->fresh()->approved_at);
    }
    #[Test]
    public function approve_returns_422_when_already_approved(): void
    {
        $promo = $this->makePromo(['status' => 'approved']);
        $this->addDetail($promo);

        $this->postJson(self::BASE . "/{$promo->ulid}/approve")->assertUnprocessable();
    }
    #[Test]
    public function approve_returns_422_when_no_details(): void
    {
        $promo = $this->makePromo(); // no details

        $this->postJson(self::BASE . "/{$promo->ulid}/approve")->assertUnprocessable();
    }
    #[Test]
    public function approve_returns_422_when_all_discounts_are_zero(): void
    {
        $promo = $this->makePromo();
        $this->addDetail($promo, [
            'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
        ]);

        $this->postJson(self::BASE . "/{$promo->ulid}/approve")->assertUnprocessable();
    }
    #[Test]
    public function approve_requires_promo_approve_permission(): void
    {
        $promo = $this->makePromo();
        $this->addDetail($promo);
        $noPermUser = User::factory()->create();
        Sanctum::actingAs($noPermUser);

        $this->postJson(self::BASE . "/{$promo->ulid}/approve")->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /api/v1/promos/{ulid}/cancel
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function cancel_returns_approved_promo_to_draft(): void
    {
        $promo = $this->makePromo(['status' => 'approved', 'approved_at' => now(), 'approved_by' => $this->user->id]);
        $this->addDetail($promo);

        $response = $this->postJson(self::BASE . "/{$promo->ulid}/cancel");

        $response->assertOk();
        $fresh = $promo->fresh();
        $this->assertEquals('draft', $fresh->status);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->approved_by);
    }
    #[Test]
    public function cancel_returns_422_when_promo_is_draft(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);

        $this->postJson(self::BASE . "/{$promo->ulid}/cancel")->assertUnprocessable();
    }
    #[Test]
    public function cancel_requires_promo_approve_permission(): void
    {
        $promo = $this->makePromo(['status' => 'approved']);
        $noPermUser = User::factory()->create();
        Sanctum::actingAs($noPermUser);

        $this->postJson(self::BASE . "/{$promo->ulid}/cancel")->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /api/v1/promos/{ulid}/deactivate
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function deactivate_transitions_approved_to_inactive(): void
    {
        $promo = $this->makePromo(['status' => 'approved']);

        $response = $this->postJson(self::BASE . "/{$promo->ulid}/deactivate");

        $response->assertOk();
        $this->assertEquals('inactive', $promo->fresh()->status);
    }
    #[Test]
    public function deactivate_returns_422_when_promo_is_not_approved(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);

        $this->postJson(self::BASE . "/{$promo->ulid}/deactivate")->assertUnprocessable();
    }
    #[Test]
    public function deactivate_requires_promo_toggle_permission(): void
    {
        $promo = $this->makePromo(['status' => 'approved']);
        $noPermUser = User::factory()->create();
        Sanctum::actingAs($noPermUser);

        $this->postJson(self::BASE . "/{$promo->ulid}/deactivate")->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────
    // POST /api/v1/promos/{ulid}/reactivate
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function reactivate_transitions_inactive_to_approved(): void
    {
        $promo = $this->makePromo(['status' => 'inactive']);

        $response = $this->postJson(self::BASE . "/{$promo->ulid}/reactivate");

        $response->assertOk();
        $this->assertEquals('approved', $promo->fresh()->status);
    }
    #[Test]
    public function reactivate_returns_422_when_promo_is_not_inactive(): void
    {
        $promo = $this->makePromo(['status' => 'draft']);

        $this->postJson(self::BASE . "/{$promo->ulid}/reactivate")->assertUnprocessable();
    }
    #[Test]
    public function reactivate_returns_404_for_unknown_ulid(): void
    {
        $this->postJson(self::BASE . '/unknown-ulid/reactivate')->assertNotFound();
    }
    #[Test]
    public function reactivate_requires_promo_toggle_permission(): void
    {
        $promo = $this->makePromo(['status' => 'inactive']);
        $noPermUser = User::factory()->create();
        Sanctum::actingAs($noPermUser);

        $this->postJson(self::BASE . "/{$promo->ulid}/reactivate")->assertForbidden();
    }

    // ──────────────────────────────────────────────────────────────────
    // EDGE CASES TAMBAHAN (galak): lifecycle penuh, validasi batas, target
    // ──────────────────────────────────────────────────────────────────

    /**
     * Lifecycle penuh via HTTP: store(draft) → approve → deactivate →
     * reactivate → cancel(draft). Setiap transisi diverifikasi status + meta.
     */
    #[Test]
    public function lifecycle_penuh_store_approve_deactivate_reactivate_cancel(): void
    {
        // 1. store → draft
        $create = $this->postJson(self::BASE, $this->validPayload());
        $create->assertCreated();
        $ulid = $create->json('data.promo.ulid');
        $this->assertEquals('draft', DocPromo::where('ulid', $ulid)->value('status'));

        // 2. approve → approved + approved_at + approved_by
        $this->postJson(self::BASE . "/{$ulid}/approve")->assertOk();
        $promo = DocPromo::where('ulid', $ulid)->first();
        $this->assertEquals('approved', $promo->status);
        $this->assertNotNull($promo->approved_at);
        $this->assertEquals($this->user->id, $promo->approved_by);

        // 3. deactivate → inactive
        $this->postJson(self::BASE . "/{$ulid}/deactivate")->assertOk();
        $this->assertEquals('inactive', DocPromo::where('ulid', $ulid)->value('status'));

        // 4. reactivate → approved kembali
        $this->postJson(self::BASE . "/{$ulid}/reactivate")->assertOk();
        $this->assertEquals('approved', DocPromo::where('ulid', $ulid)->value('status'));

        // 5. cancel → draft + meta approval bersih
        $this->postJson(self::BASE . "/{$ulid}/cancel")->assertOk();
        $fresh = DocPromo::where('ulid', $ulid)->first();
        $this->assertEquals('draft', $fresh->status);
        $this->assertNull($fresh->approved_at);
        $this->assertNull($fresh->approved_by);
    }

    /**
     * Boundary tanggal: tanggal_selesai == tanggal_mulai (promo 1 hari) HARUS lolos
     * (rule after_or_equal inklusif). Komplemen test "selesai sebelum mulai" = 422.
     */
    #[Test]
    public function store_lolos_saat_tanggal_selesai_sama_dengan_tanggal_mulai(): void
    {
        $payload = $this->validPayload([
            'tanggal_mulai'   => today()->toDateString(),
            'tanggal_selesai' => today()->toDateString(),
        ]);

        $this->postJson(self::BASE, $payload)->assertCreated();
    }

    /**
     * Validasi jam: jam_selesai harus SETELAH jam_mulai (rule after).
     * jam_selesai <= jam_mulai → 422.
     */
    #[Test]
    public function store_tolak_saat_jam_selesai_tidak_setelah_jam_mulai(): void
    {
        $payload = $this->validPayload([
            'jam_mulai'   => '10:00',
            'jam_selesai' => '09:00',
        ]);

        $this->postJson(self::BASE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('jam_selesai');
    }

    /**
     * Validasi jam: jam_mulai diisi tapi jam_selesai kosong → 422
     * (rule required_with:jam_mulai).
     */
    #[Test]
    public function store_tolak_jam_mulai_tanpa_jam_selesai(): void
    {
        $payload = $this->validPayload(['jam_mulai' => '08:00']);
        unset($payload['jam_selesai']);

        $this->postJson(self::BASE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('jam_selesai');
    }

    /**
     * Validasi min_qty: minimal 1 (rule min:1). min_qty=0 → 422.
     */
    #[Test]
    public function store_tolak_min_qty_nol(): void
    {
        $payload = $this->validPayload();
        $payload['details'][0]['min_qty'] = 0;

        $this->postJson(self::BASE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('details.0.min_qty');
    }

    /**
     * Validasi diskon_nilai: tidak boleh negatif (rule min:0). nilai -5 → 422.
     */
    #[Test]
    public function store_tolak_diskon_nilai_negatif(): void
    {
        $payload = $this->validPayload();
        $payload['details'][0]['diskon_1_nilai'] = -5;

        $this->postJson(self::BASE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('details.0.diskon_1_nilai');
    }

    /**
     * Validasi diskon_tipe: hanya percent/nominal/none. Nilai lain → 422.
     */
    #[Test]
    public function store_tolak_diskon_tipe_tidak_valid(): void
    {
        $payload = $this->validPayload();
        $payload['details'][0]['diskon_1_tipe'] = 'flat';

        $this->postJson(self::BASE, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('details.0.diskon_1_tipe');
    }

    /**
     * store mempersist terminal_id (targeting per terminal) + nomor PRM unik.
     */
    #[Test]
    public function store_mempersist_terminal_id(): void
    {
        $warehouse = \App\Models\MasterWarehouse::factory()->create(['status' => 'active']);
        $terminal = \App\Models\MasterPosTerminal::create([
            'ulid'          => (string) Str::ulid(),
            'kode_terminal' => 'TRM-API',
            'nama_terminal' => 'Kasir API',
            'warehouse_id'  => $warehouse->id,
            'status'        => 'active',
            'created_by'    => $this->user->id,
        ]);

        $response = $this->postJson(self::BASE, $this->validPayload(['terminal_id' => $terminal->id]));

        $response->assertCreated();
        $ulid = $response->json('data.promo.ulid');
        $this->assertEquals($terminal->id, DocPromo::where('ulid', $ulid)->value('terminal_id'));
    }

    /**
     * store menolak terminal_id yang tidak ada (rule exists). → 422.
     */
    #[Test]
    public function store_tolak_terminal_id_tidak_ada(): void
    {
        $this->postJson(self::BASE, $this->validPayload(['terminal_id' => 999999]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('terminal_id');
    }

    /**
     * Kode promo yang di-generate sistem WAJIB unik antar dua promo (sequence).
     */
    #[Test]
    public function dua_store_menghasilkan_kode_promo_unik(): void
    {
        $r1 = $this->postJson(self::BASE, $this->validPayload());
        $r2 = $this->postJson(self::BASE, $this->validPayload());

        $r1->assertCreated();
        $r2->assertCreated();

        $kode1 = $r1->json('data.promo.kode_promo');
        $kode2 = $r2->json('data.promo.kode_promo');

        $this->assertNotEquals($kode1, $kode2, 'Kode promo harus unik');
        $this->assertStringStartsWith('PRM-', $kode1);
        $this->assertStringStartsWith('PRM-', $kode2);
        $this->assertEquals(2, DocPromo::count());
    }

    /**
     * update menolak promo berstatus inactive (bukan draft) → 422.
     * Komplemen test "approved" yang sudah ada.
     */
    #[Test]
    public function update_tolak_promo_inactive(): void
    {
        $promo = $this->makePromo(['status' => 'inactive']);

        $this->putJson(self::BASE . "/{$promo->ulid}", $this->validPayload())
            ->assertUnprocessable();
    }

    /**
     * index search by nama_promo memfilter hasil dengan benar.
     */
    #[Test]
    public function index_search_filter_by_nama_promo(): void
    {
        $this->makePromo(['nama_promo' => 'PROMO RAMADHAN']);
        $this->makePromo(['nama_promo' => 'PROMO NATAL']);

        $response = $this->getJson(self::BASE . '?search=RAMADHAN');

        $response->assertOk();
        $response->assertJsonPath('data.pagination.total', 1);
        $response->assertJsonPath('data.items.0.nama_promo', 'PROMO RAMADHAN');
    }

    /**
     * approve TIDAK lolos saat promo punya detail tapi salah satu tipe percent
     * dengan nilai 0 di semua slot (tidak ada diskon efektif) → 422.
     * Memastikan guard mengecek nilai > 0, bukan sekadar keberadaan detail.
     */
    #[Test]
    public function approve_tolak_saat_tipe_diisi_tapi_semua_nilai_nol(): void
    {
        $promo = $this->makePromo();
        $this->addDetail($promo, [
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'percent', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',    'diskon_4_nilai' => 0,
        ]);

        $this->postJson(self::BASE . "/{$promo->ulid}/approve")->assertUnprocessable();
        $this->assertEquals('draft', $promo->fresh()->status, 'Promo harus tetap draft');
    }

    /**
     * update mengganti customer_category_id menjadi null (lepas targeting).
     * Pastikan field benar-benar di-reset, bukan dipertahankan.
     */
    #[Test]
    public function update_dapat_menghapus_customer_category_id(): void
    {
        $kategori = \App\Models\MasterKategoriCustomer::create([
            'ulid'          => (string) Str::ulid(),
            'kode_kategori' => 'GOLD',
            'nama_kategori' => 'Gold Member',
            'status'        => 'active',
        ]);

        $promo = $this->makePromo(['customer_category_id' => $kategori->id]);
        $this->addDetail($promo);

        // Payload tanpa customer_category_id → controller set null
        $this->putJson(self::BASE . "/{$promo->ulid}", $this->validPayload())->assertOk();

        $this->assertNull($promo->fresh()->customer_category_id, 'Targeting kategori harus dilepas');
    }
}
