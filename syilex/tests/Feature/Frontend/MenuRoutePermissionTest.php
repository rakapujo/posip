<?php

namespace Tests\Feature\Frontend;

use Database\Seeders\UserSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Audit 52 menu: setiap permission di router frontend harus terdaftar di seeder.
 */
class MenuRoutePermissionTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $routerPermissions;

    protected function setUp(): void
    {
        parent::setUp();

        $routerPath = dirname(base_path(), 1).DIRECTORY_SEPARATOR.'syilex-frontend'.DIRECTORY_SEPARATOR.'src'.DIRECTORY_SEPARATOR.'router'.DIRECTORY_SEPARATOR.'index.js';
        $source = file_get_contents($routerPath);

        preg_match_all("/permission:\s*'([^']+)'/", $source, $single);
        preg_match_all("/permissions:\s*\[([^\]]+)\]/", $source, $multiBlocks);

        $permissions = $single[1];
        foreach ($multiBlocks[1] as $block) {
            preg_match_all("/'([^']+)'/", $block, $multi);
            $permissions = array_merge($permissions, $multi[1]);
        }

        $this->routerPermissions = array_values(array_unique($permissions));
    }

    public function test_router_permissions_are_registered_in_seeder(): void
    {
        $this->seed(UserSeeder::class);

        $registered = Permission::pluck('name')->all();

        $missing = array_values(array_diff($this->routerPermissions, $registered));

        $this->assertSame(
            [],
            $missing,
            'Router permission belum ada di UserSeeder: '.implode(', ', $missing)
        );
    }

    public function test_router_declares_expected_menu_permission_count(): void
    {
        $this->assertGreaterThanOrEqual(
            52,
            count($this->routerPermissions),
            'Jumlah permission unik di router kurang dari 52 menu yang diaudit.'
        );
    }
}
