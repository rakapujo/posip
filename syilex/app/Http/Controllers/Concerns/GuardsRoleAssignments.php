<?php

namespace App\Http\Controllers\Concerns;

use App\Models\User;
use App\Services\SettingService;
use Illuminate\Http\JsonResponse;
use Spatie\Permission\Models\Role;

/**
 * Guards for role/permission assignment — prevent privilege escalation.
 */
trait GuardsRoleAssignments
{
    protected function actorIsSuperAdmin(?User $actor = null): bool
    {
        $actor ??= auth()->user();

        return (bool) $actor?->hasRole('super-admin');
    }

    /**
     * Non-super-admin may only sync permissions they themselves hold.
     *
     * @param  array<int, string>  $permissions
     */
    protected function assertAssignablePermissions(array $permissions, ?User $actor = null): ?JsonResponse
    {
        $actor ??= auth()->user();

        if ($this->actorIsSuperAdmin($actor)) {
            return null;
        }

        $forbidden = [];
        foreach ($permissions as $permission) {
            if (! $actor->can($permission)) {
                $forbidden[] = $permission;
            }
        }

        if ($forbidden !== []) {
            return $this->error(
                'Tidak dapat memberikan permission yang tidak Anda miliki: '.implode(', ', array_slice($forbidden, 0, 5)),
                422
            );
        }

        return null;
    }

    /**
     * Non-SA may not update/destroy a role whose existing perms exceed the actor's ceiling.
     * Serial perms ignored when elektronik OFF (same carve-out as RoleController sync merge).
     */
    protected function assertRolePermissionsManageable(Role $role, ?User $actor = null): ?JsonResponse
    {
        $actor ??= auth()->user();

        if ($this->actorIsSuperAdmin($actor)) {
            return null;
        }

        if ($role->name === 'super-admin') {
            return $this->error('Tidak dapat mengubah role super-admin', 403);
        }

        $role->loadMissing('permissions');
        $rolePermissions = $role->permissions->pluck('name')->all();

        if (! SettingService::isElektronikEnabled()) {
            $rolePermissions = array_values(array_filter(
                $rolePermissions,
                fn (string $permission) => ! str_starts_with($permission, 'serial-change.')
                    && ! str_starts_with($permission, 'serial-hpp.')
                    && ! str_starts_with($permission, 'serial-intake.')
            ));
        }

        return $this->assertAssignablePermissions($rolePermissions, $actor);
    }

    /**
     * Block assigning super-admin (unless actor is super-admin) and roles
     * whose permission set exceeds the actor's.
     */
    protected function assertAssignableRole(string $roleName, ?User $actor = null): ?JsonResponse
    {
        $actor ??= auth()->user();

        if ($roleName === 'super-admin' && ! $this->actorIsSuperAdmin($actor)) {
            return $this->error('Tidak dapat menugaskan role super-admin', 422);
        }

        if ($this->actorIsSuperAdmin($actor)) {
            return null;
        }

        // Guard eksplisit 'web' — role selalu dibuat dengan guard_name 'web' (lihat RoleController::store),
        // tapi default guard request API adalah 'sanctum' (di-set oleh Authenticate middleware via
        // Auth::shouldUse()). Tanpa guard eksplisit, Role::findByName() salah resolve ke guard 'sanctum'
        // untuk model Role (yang tidak terikat ke provider user manapun) dan selalu throw RoleDoesNotExist.
        $role = Role::findByName($roleName, 'web');
        $rolePermissions = $role->permissions->pluck('name')->all();

        return $this->assertAssignablePermissions($rolePermissions, $actor);
    }
}
