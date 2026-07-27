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
            'user.view', 'role.view', 'import.master', 'settings.reset',
            'brand.create',
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

    public function test_dashboard_ok_for_authenticated_user_without_any_widget_permission(): void
    {
        // Dashboard gates each widget individually — authenticated user with zero
        // permissions still gets 200 with an (mostly empty) payload, never 403.
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/dashboard')
            ->assertOk();
    }

    public function test_users_index_forbidden_without_user_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/users')
            ->assertForbidden();
    }

    public function test_roles_index_forbidden_without_role_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/roles')
            ->assertForbidden();
    }

    public function test_import_template_forbidden_without_import_master(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('brand.create');

        $this->actingAs($user)
            ->getJson('/api/v1/import/template/brand')
            ->assertForbidden();
    }

    public function test_reset_forbidden_without_settings_reset(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/reset', [])
            ->assertForbidden();
    }
}
