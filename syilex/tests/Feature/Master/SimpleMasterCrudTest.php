<?php

namespace Tests\Feature\Master;

use App\Models\MasterBrand;
use App\Models\MasterTipe;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SimpleMasterCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['brand.view', 'brand.create', 'brand.update', 'brand.delete', 'tipe.view', 'tipe.create', 'tipe.update', 'tipe.delete'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'brand.view', 'brand.create', 'brand.update', 'brand.delete',
            'tipe.view', 'tipe.create', 'tipe.update', 'tipe.delete',
        ]);
    }

    public function test_brand_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/brands', [
                'kode_brand' => 'BR-01',
                'nama_brand' => 'Brand Satu',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.brand.kode_brand', 'BR-01');

        $ulid = MasterBrand::first()->ulid;

        $this->actingAs($this->user)
            ->getJson('/api/v1/brands')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 1);

        $this->actingAs($this->user)
            ->putJson("/api/v1/brands/{$ulid}", [
                'nama_brand' => 'Brand Satu Updated',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.brand.nama_brand', 'Brand Satu Updated');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/brands/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.brand.status', 'inactive');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/brands/{$ulid}")
            ->assertOk();

        $this->assertDatabaseMissing('master_brand', ['ulid' => $ulid]);
    }

    public function test_tipe_delete_blocked_when_has_kategori(): void
    {
        $tipe = MasterTipe::create([
            'kode_tipe' => 'TP-01',
            'nama_tipe' => 'Elektronik',
            'status' => 'active',
        ]);

        \App\Models\MasterKategori::create([
            'tipe_id' => $tipe->id,
            'kode_kategori' => 'KT-01',
            'nama_kategori' => 'Handphone',
            'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/tipes/{$tipe->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_unauthorized_user_cannot_create_brand(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson('/api/v1/brands', [
                'kode_brand' => 'BR-99',
                'nama_brand' => 'Denied',
                'status' => 'active',
            ])
            ->assertForbidden();
    }
}
