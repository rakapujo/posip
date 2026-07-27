<?php

namespace Tests\Feature\Master;

use App\Models\MasterKategoriCustomer;
use App\Models\MasterTipeCustomer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 1 — Tipe Customer & Kategori Customer CRUD via API (HandlesSimpleMasterCrud).
 */
class TipeKategoriCustomerCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'tipe-customer.view', 'tipe-customer.create', 'tipe-customer.update', 'tipe-customer.delete',
            'kategori-customer.view', 'kategori-customer.create', 'kategori-customer.update', 'kategori-customer.delete',
            'customer-discount.manage',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'tipe-customer.view', 'tipe-customer.create', 'tipe-customer.update', 'tipe-customer.delete',
            'kategori-customer.view', 'kategori-customer.create', 'kategori-customer.update', 'kategori-customer.delete',
            'customer-discount.manage',
        ]);
    }

    // ==================== Tipe Customer ====================

    public function test_tipe_customer_index_forbidden_without_view_permission(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/v1/tipe-customers')
            ->assertForbidden();
    }

    public function test_tipe_customer_create_forbidden_without_create_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('tipe-customer.view');

        $this->actingAs($viewer)
            ->postJson('/api/v1/tipe-customers', [
                'kode_tipe' => 'TC-DENY',
                'nama_tipe' => 'Denied',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_tipe_customer_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/tipe-customers', [
                'kode_tipe' => 'TC-01',
                'nama_tipe' => 'Retail',
                'diskon_tipe' => 'percent',
                'diskon_nilai' => 5,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.tipe_customer.kode_tipe', 'TC-01')
            ->assertJsonPath('data.tipe_customer.nama_tipe', 'Retail');

        $ulid = MasterTipeCustomer::where('kode_tipe', 'TC-01')->first()->ulid;

        $this->actingAs($this->user)
            ->getJson('/api/v1/tipe-customers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user)
            ->putJson("/api/v1/tipe-customers/{$ulid}", [
                'nama_tipe' => 'Retail Updated',
                'diskon_tipe' => 'nominal',
                'diskon_nilai' => 1000,
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.tipe_customer.diskon_tipe', 'nominal');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/tipe-customers/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.tipe_customer.status', 'inactive');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/tipe-customers/{$ulid}")
            ->assertOk();

        $this->assertDatabaseMissing('master_tipe_customer', ['ulid' => $ulid]);
    }

    public function test_tipe_customer_delete_blocked_when_has_customer(): void
    {
        $tipe = MasterTipeCustomer::create([
            'kode_tipe' => 'TC-USED',
            'nama_tipe' => 'Used Tipe',
            'diskon_tipe' => 'none',
            'diskon_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        \App\Models\MasterCustomer::create([
            'kode_customer' => 'CUS-TC',
            'nama' => 'Customer Tipe',
            'telepon' => '08123',
            'jenis' => 'spesifik',
            'tipe_customer_id' => $tipe->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/tipe-customers/{$tipe->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    // ==================== Kategori Customer ====================

    public function test_kategori_customer_index_forbidden_without_view_permission(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/v1/kategori-customers')
            ->assertForbidden();
    }

    public function test_kategori_customer_create_forbidden_without_create_permission(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('kategori-customer.view');

        $this->actingAs($viewer)
            ->postJson('/api/v1/kategori-customers', [
                'kode_kategori' => 'KC-DENY',
                'nama_kategori' => 'Denied',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_kategori_customer_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/kategori-customers', [
                'kode_kategori' => 'KC-01',
                'nama_kategori' => 'Toko',
                'diskon_tipe' => 'percent',
                'diskon_nilai' => 3,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kategori_customer.kode_kategori', 'KC-01');

        $ulid = MasterKategoriCustomer::where('kode_kategori', 'KC-01')->first()->ulid;

        $this->actingAs($this->user)
            ->getJson('/api/v1/kategori-customers')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user)
            ->putJson("/api/v1/kategori-customers/{$ulid}", [
                'nama_kategori' => 'Toko Updated',
                'diskon_tipe' => 'none',
                'diskon_nilai' => 0,
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.kategori_customer.diskon_tipe', 'none');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/kategori-customers/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.kategori_customer.status', 'inactive');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/kategori-customers/{$ulid}")
            ->assertOk();

        $this->assertDatabaseMissing('master_kategori_customer', ['ulid' => $ulid]);
    }

    public function test_kategori_customer_delete_blocked_when_has_customer(): void
    {
        $kategori = MasterKategoriCustomer::create([
            'kode_kategori' => 'KC-USED',
            'nama_kategori' => 'Used Kategori',
            'diskon_tipe' => 'none',
            'diskon_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        \App\Models\MasterCustomer::create([
            'kode_customer' => 'CUS-KC',
            'nama' => 'Customer Kategori',
            'telepon' => '08123',
            'jenis' => 'spesifik',
            'kategori_customer_id' => $kategori->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/kategori-customers/{$kategori->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }
}
