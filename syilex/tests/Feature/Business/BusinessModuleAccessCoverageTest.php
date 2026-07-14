<?php

namespace Tests\Feature\Business;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Modul bisnis belum diaudit — promo master, price change, POS terminal/shift.
 */
class BusinessModuleAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'promo.view', 'promo.create',
            'price-change.view', 'price-change.create',
            'terminal.view', 'terminal.create',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->viewer = User::factory()->create();
    }

    public function test_promo_index_forbidden_without_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/promos')
            ->assertForbidden();
    }

    public function test_promo_create_forbidden_without_create(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('promo.view');

        $this->actingAs($user)
            ->postJson('/api/v1/promos', [])
            ->assertForbidden();
    }

    public function test_price_change_index_forbidden_without_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/price-changes')
            ->assertForbidden();
    }

    public function test_price_change_create_forbidden_without_create(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('price-change.view');

        $this->actingAs($user)
            ->postJson('/api/v1/price-changes', [])
            ->assertForbidden();
    }

    public function test_pos_terminal_index_forbidden_without_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/pos-terminals')
            ->assertForbidden();
    }

    public function test_shifts_index_forbidden_without_terminal_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts')
            ->assertForbidden();
    }

    public function test_promo_show_forbidden_for_unknown_ulid_without_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/promos/'.Str::ulid())
            ->assertForbidden();
    }
}
