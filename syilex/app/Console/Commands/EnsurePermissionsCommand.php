<?php

namespace App\Console\Commands;

use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Catalog + super-admin only — does not sync admin/kasir/gudang (preserves custom matrices).
 */
class EnsurePermissionsCommand extends Command
{
    protected $signature = 'permissions:ensure';

    protected $description = 'Create missing permission rows and sync super-admin to all (preserve other roles)';

    public function handle(): int
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $seeder = new RolePermissionSeeder;
        $created = $seeder->ensurePermissions();
        $seeder->syncSuperAdmin();

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->info("Permissions ensured. New rows: {$created}. Total: ".Permission::count().'. Super-admin synced.');

        return self::SUCCESS;
    }
}
