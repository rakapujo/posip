<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 3 — cluster POS: permission matrix HTTP.
 */
class PosAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['pos.access', 'pos.void', 'pos.retur'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->viewer = User::factory()->create();
    }

    public function test_active_terminal_forbidden_without_pos_access(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/pos/active-terminal')
            ->assertForbidden();
    }

    public function test_products_forbidden_without_pos_access(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/pos/products')
            ->assertForbidden();
    }

    public function test_checkout_forbidden_without_pos_access(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/pos/checkout', [])
            ->assertForbidden();
    }

    public function test_void_forbidden_without_pos_void_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('pos.access');

        $this->actingAs($user)
            ->postJson('/api/v1/pos/sales/'.Str::ulid().'/void', [])
            ->assertForbidden();
    }

    public function test_returns_index_forbidden_without_pos_retur(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('pos.access');

        $this->actingAs($user)
            ->getJson('/api/v1/pos/returns?shift_id=1')
            ->assertForbidden();
    }

    public function test_returns_store_forbidden_without_pos_retur(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('pos.access');

        $this->actingAs($user)
            ->postJson('/api/v1/pos/returns', [])
            ->assertForbidden();
    }
}
