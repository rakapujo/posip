<?php

namespace Tests\Feature\Authz;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleUserPrivilegeEscalationTest extends TestCase
{
    use RefreshDatabase;

    private function seedPerms(array $names): void
    {
        foreach ($names as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function userWithPerms(array $perms): User
    {
        $this->seedPerms($perms);
        $user = User::factory()->create(['status' => 'active']);
        $user->givePermissionTo($perms);

        return $user;
    }

    #[Test]
    public function cannot_rename_super_admin_role(): void
    {
        $this->seedPerms(['role.update', 'brand.view']);
        Role::findOrCreate('super-admin', 'web');
        $super = Role::findByName('super-admin');
        $super->syncPermissions(Permission::all());

        $sa = User::factory()->create(['status' => 'active']);
        $sa->assignRole('super-admin');
        $sa->givePermissionTo(Permission::all());

        $this->actingAs($sa)
            ->putJson("/api/v1/roles/{$super->id}", [
                'name' => 'not-super',
                'permissions' => ['brand.view'],
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Nama role super-admin tidak dapat diubah']);
    }

    #[Test]
    public function cannot_grant_permission_actor_does_not_have(): void
    {
        $this->seedPerms(['role.create', 'role.update', 'brand.view', 'settings.reset']);
        Role::findOrCreate('kasir', 'web');

        $actor = $this->userWithPerms(['role.create', 'role.update', 'brand.view']);

        $this->actingAs($actor)
            ->postJson('/api/v1/roles', [
                'name' => 'evil-role',
                'permissions' => ['brand.view', 'settings.reset'],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function cannot_assign_super_admin_role_to_user(): void
    {
        $this->seedPerms(['user.create', 'brand.view']);
        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('kasir', 'web');

        $actor = $this->userWithPerms(['user.create', 'brand.view']);

        $this->actingAs($actor)
            ->postJson('/api/v1/users', [
                'name' => 'Escalate Me',
                'email' => 'escalate@test.com',
                'password' => 'password1',
                'pin' => '123456',
                'phone' => '08123456789',
                'role' => 'super-admin',
                'status' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => 'Tidak dapat menugaskan role super-admin']);
    }

    #[Test]
    public function users_list_requires_permission(): void
    {
        $actor = User::factory()->create(['status' => 'active']);
        // no permissions

        $this->actingAs($actor)
            ->getJson('/api/v1/users/list')
            ->assertStatus(403);
    }

    #[Test]
    public function users_list_allowed_with_terminal_edit(): void
    {
        $actor = $this->userWithPerms(['terminal.edit']);

        $this->actingAs($actor)
            ->getJson('/api/v1/users/list')
            ->assertOk();
    }

    #[Test]
    public function users_roles_requires_user_permission(): void
    {
        $actor = User::factory()->create(['status' => 'active']);

        $this->actingAs($actor)
            ->getJson('/api/v1/users/roles')
            ->assertStatus(403);
    }

    #[Test]
    public function users_roles_hides_super_admin_for_non_super(): void
    {
        $this->seedPerms(['user.view']);
        Role::findOrCreate('super-admin', 'web');
        Role::findOrCreate('kasir', 'web');

        $actor = $this->userWithPerms(['user.view']);

        $names = collect(
            $this->actingAs($actor)->getJson('/api/v1/users/roles')->assertOk()->json('data.roles')
        )->pluck('name');

        $this->assertFalse($names->contains('super-admin'));
        $this->assertTrue($names->contains('kasir'));
    }

    #[Test]
    public function cannot_assign_role_with_permissions_actor_lacks(): void
    {
        $this->seedPerms(['user.create', 'brand.view', 'settings.reset']);
        $evil = Role::findOrCreate('evil-role', 'web');
        $evil->syncPermissions(['brand.view', 'settings.reset']);

        $actor = $this->userWithPerms(['user.create', 'brand.view']);

        $this->actingAs($actor)
            ->postJson('/api/v1/users', [
                'name' => 'Escalate Via Role',
                'email' => 'evil-assign@test.com',
                'password' => 'password1',
                'pin' => '123456',
                'phone' => '08123456789',
                'role' => 'evil-role',
                'status' => 'active',
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function non_super_admin_cannot_put_super_admin_role_even_with_all_perms(): void
    {
        $this->seedPerms(['role.update', 'brand.view']);
        Role::findOrCreate('super-admin', 'web');
        $super = Role::findByName('super-admin', 'web');
        $super->syncPermissions(Permission::all());

        $actor = $this->userWithPerms(['role.update', 'brand.view']);
        $actor->givePermissionTo(Permission::all());

        $this->actingAs($actor)
            ->putJson("/api/v1/roles/{$super->id}", [
                'name' => 'super-admin',
                'permissions' => ['brand.view'],
            ])
            ->assertForbidden();
    }

    #[Test]
    public function cannot_strip_role_with_permissions_actor_lacks(): void
    {
        $this->seedPerms(['role.update', 'brand.view', 'settings.reset']);
        $rich = Role::findOrCreate('rich-strip', 'web');
        $rich->syncPermissions(['brand.view', 'settings.reset']);

        $actor = $this->userWithPerms(['role.update', 'brand.view']);

        $this->actingAs($actor)
            ->putJson("/api/v1/roles/{$rich->id}", [
                'name' => 'rich-strip',
                'permissions' => ['brand.view'],
            ])
            ->assertStatus(422);
    }
}
