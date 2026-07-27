<?php

namespace Tests\Feature\Pengaturan;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 1 — Role Management CRUD via API.
 */
class RoleCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['role.view', 'role.create', 'role.update', 'role.delete', 'brand.view', 'tipe.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::findOrCreate('super-admin', 'web');

        $this->superAdmin = User::factory()->create();
        $this->superAdmin->assignRole('super-admin');
        $this->superAdmin->givePermissionTo(Permission::all());
    }

    public function test_index_forbidden_without_role_view(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/v1/roles')
            ->assertForbidden();
    }

    public function test_create_forbidden_without_role_create(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('role.view');

        $this->actingAs($viewer)
            ->postJson('/api/v1/roles', [
                'name' => 'evil-role',
                'permissions' => ['brand.view'],
            ])
            ->assertForbidden();
    }

    public function test_role_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/roles', [
                'name' => 'gudang-plus',
                'permissions' => ['brand.view', 'tipe.view'],
            ])
            ->assertCreated()
            ->assertJsonPath('data.role.name', 'gudang-plus');

        $role = Role::where('name', 'gudang-plus')->first();

        $this->actingAs($this->superAdmin)
            ->getJson('/api/v1/roles')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2); // super-admin + gudang-plus

        $this->actingAs($this->superAdmin)
            ->getJson("/api/v1/roles/{$role->id}")
            ->assertOk()
            ->assertJsonPath('data.role.name', 'gudang-plus')
            ->assertJsonCount(2, 'data.role.permissions');

        $this->actingAs($this->superAdmin)
            ->putJson("/api/v1/roles/{$role->id}", [
                'name' => 'gudang-plus',
                'permissions' => ['brand.view'],
            ])
            ->assertOk()
            ->assertJsonPath('data.role.name', 'gudang-plus');

        $role->refresh();
        $this->assertCount(1, $role->permissions);

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertOk();

        $this->assertDatabaseMissing('roles', ['id' => $role->id]);
    }

    public function test_create_rejects_permission_actor_does_not_have(): void
    {
        Permission::firstOrCreate(['name' => 'settings.reset', 'guard_name' => 'web']);
        $limited = User::factory()->create();
        $limited->givePermissionTo(['role.create', 'brand.view']);

        $this->actingAs($limited)
            ->postJson('/api/v1/roles', [
                'name' => 'sneaky-role',
                'permissions' => ['brand.view', 'settings.reset'],
            ])
            ->assertStatus(422);
    }

    public function test_cannot_create_role_named_super_admin(): void
    {
        $this->actingAs($this->superAdmin)
            ->postJson('/api/v1/roles', [
                'name' => 'super-admin',
                'permissions' => ['brand.view'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('name');
    }

    public function test_update_forbidden_without_role_update(): void
    {
        $role = Role::create(['name' => 'kasir-plus', 'guard_name' => 'web']);
        $role->syncPermissions(['brand.view']);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('role.view');

        $this->actingAs($viewer)
            ->putJson("/api/v1/roles/{$role->id}", [
                'name' => 'kasir-plus',
                'permissions' => ['tipe.view'],
            ])
            ->assertForbidden();
    }

    public function test_delete_forbidden_without_role_delete(): void
    {
        $role = Role::create(['name' => 'kasir-plus2', 'guard_name' => 'web']);

        $editor = User::factory()->create();
        $editor->givePermissionTo(['role.view', 'role.update']);

        $this->actingAs($editor)
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertForbidden();
    }

    public function test_delete_blocked_when_role_has_users(): void
    {
        $role = Role::create(['name' => 'used-role', 'guard_name' => 'web']);
        $target = User::factory()->create();
        $target->assignRole('used-role');

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(422);
    }

    public function test_delete_blocked_for_super_admin_role(): void
    {
        $super = Role::where('name', 'super-admin')->first();

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/roles/{$super->id}")
            ->assertStatus(422);
    }

    public function test_permissions_matrix_allowed_with_role_create_only(): void
    {
        $creator = User::factory()->create();
        $creator->givePermissionTo(['role.create', 'brand.view']);

        $this->actingAs($creator)
            ->getJson('/api/v1/roles/permissions')
            ->assertOk()
            ->assertJsonStructure(['data' => ['groups', 'all_permissions']]);
    }

    public function test_permissions_matrix_forbidden_without_create_or_update(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['role.view', 'brand.view']);

        $this->actingAs($viewer)
            ->getJson('/api/v1/roles/permissions')
            ->assertForbidden();
    }

    public function test_delete_blocked_when_only_soft_deleted_user_assigned(): void
    {
        $role = Role::create(['name' => 'ghost-role', 'guard_name' => 'web']);
        $target = User::factory()->create();
        $target->assignRole('ghost-role');
        $target->delete();

        $this->actingAs($this->superAdmin)
            ->deleteJson("/api/v1/roles/{$role->id}")
            ->assertStatus(422);
    }

    public function test_non_sa_cannot_strip_richer_role(): void
    {
        Permission::firstOrCreate(['name' => 'settings.reset', 'guard_name' => 'web']);
        $rich = Role::create(['name' => 'rich-role', 'guard_name' => 'web']);
        $rich->syncPermissions(['brand.view', 'settings.reset']);

        $actor = User::factory()->create();
        $actor->givePermissionTo(['role.update', 'brand.view']);

        $this->actingAs($actor)
            ->putJson("/api/v1/roles/{$rich->id}", [
                'name' => 'rich-role',
                'permissions' => ['brand.view'],
            ])
            ->assertStatus(422);
    }

    public function test_non_sa_cannot_update_super_admin_role(): void
    {
        $super = Role::where('name', 'super-admin')->first();
        $actor = User::factory()->create();
        $actor->givePermissionTo(Permission::all());

        $this->actingAs($actor)
            ->putJson("/api/v1/roles/{$super->id}", [
                'name' => 'super-admin',
                'permissions' => ['brand.view'],
            ])
            ->assertForbidden();
    }

    public function test_non_sa_cannot_delete_richer_role(): void
    {
        Permission::firstOrCreate(['name' => 'settings.reset', 'guard_name' => 'web']);
        $rich = Role::create(['name' => 'rich-del', 'guard_name' => 'web']);
        $rich->syncPermissions(['brand.view', 'settings.reset']);

        $actor = User::factory()->create();
        $actor->givePermissionTo(['role.delete', 'brand.view']);

        $this->actingAs($actor)
            ->deleteJson("/api/v1/roles/{$rich->id}")
            ->assertStatus(422);
    }
}
