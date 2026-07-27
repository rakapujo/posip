<?php

namespace Tests\Feature\Role;

use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleBackofficeSalesPermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_backoffice_sales_permissions_are_admin_only_by_default(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $permissions = [
            'sales.view',
            'piutang.view',
            'pembayaran-piutang.complete',
            'deposit-customer.view',
            'retur-jual.approve',
        ];

        $this->assertTrue(Role::findByName('admin')->hasAllPermissions($permissions));
        $this->assertTrue(Role::findByName('super-admin')->hasAllPermissions($permissions));
        $this->assertFalse(Role::findByName('kasir')->hasAnyPermission($permissions));
        $this->assertFalse(Role::findByName('gudang')->hasAnyPermission($permissions));
    }

    public function test_permission_editor_exposes_penjualan_group(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->create();
        $user->givePermissionTo('role.update');

        $groups = collect(
            $this->actingAs($user)->getJson('/api/v1/roles/permissions')->assertOk()->json('data.groups')
        );
        $prefixes = collect($groups->firstWhere('label', 'Penjualan')['modules'])->pluck('prefix');

        $this->assertEqualsCanonicalizing([
            'sales',
            'piutang',
            'pembayaran-piutang',
            'deposit-customer',
            'retur-jual',
        ], $prefixes->all());
    }
}
