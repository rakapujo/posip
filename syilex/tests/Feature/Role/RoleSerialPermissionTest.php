<?php

namespace Tests\Feature\Role;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Editor permission role (Role & Permission) wajib menampilkan modul serial
 * agar permission serial-intake.* & serial-change.* bisa di-assign ke role.
 */
class RoleSerialPermissionTest extends TestCase
{
    use RefreshDatabase;
    #[Test]
    public function permission_editor_includes_serial_modules()
    {
        foreach ([
            'role.update',
            'serial-intake.view', 'serial-intake.create', 'serial-intake.approve',
            'serial-change.view', 'serial-change.approve',
        ] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $admin = User::factory()->create();
        $admin->givePermissionTo('role.update');

        $res = $this->actingAs($admin)->getJson('/api/v1/roles/permissions')->assertOk();

        $modules = collect($res->json('data.groups'))
            ->flatMap(fn ($g) => collect($g['modules']))
            ->keyBy('prefix');

        $this->assertTrue($modules->has('serial-intake'), 'Modul serial-intake harus muncul di editor permission');
        $this->assertTrue($modules->has('serial-change'), 'Modul serial-change harus muncul di editor permission');
        // Permission-nya ikut termuat
        $this->assertContains('serial-intake.approve', $modules['serial-intake']['permissions']);
    }

    // ───────────────────────── EDGE CASE TAMBAHAN (galak) ─────────────────────────

    /**
     * Seed katalog izin serial lengkap + helper untuk fetch & index per prefix.
     */
    private function seedAndFetchModules(array $perms): \Illuminate\Support\Collection
    {
        foreach (array_merge(['role.update'], $perms) as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $admin = User::factory()->create();
        $admin->givePermissionTo('role.update');

        $res = $this->actingAs($admin)->getJson('/api/v1/roles/permissions')->assertOk();

        return collect($res->json('data.groups'))
            ->flatMap(fn ($g) => collect($g['modules']))
            ->keyBy('prefix');
    }
    #[Test]
    public function katalog_serial_lengkap_dengan_permission_eksak_per_modul(): void
    {
        $perms = [
            'serial-intake.view', 'serial-intake.create', 'serial-intake.update', 'serial-intake.delete', 'serial-intake.approve',
            'serial-change.view', 'serial-change.create', 'serial-change.update', 'serial-change.delete', 'serial-change.approve',
            'serial-hpp.view', 'serial-hpp.create', 'serial-hpp.update', 'serial-hpp.delete', 'serial-hpp.approve',
            'hpp.view', 'hpp.create', 'hpp.update', 'hpp.delete', 'hpp.approve',
        ];
        $modules = $this->seedAndFetchModules($perms);

        // Semua modul serial + hpp muncul.
        foreach (['serial-intake', 'serial-change', 'serial-hpp', 'hpp'] as $prefix) {
            $this->assertTrue($modules->has($prefix), "Modul '{$prefix}' wajib muncul di katalog");
        }

        // Permission per modul HARUS persis (tidak bocor lintas-prefix).
        $expected = [
            'serial-intake' => ['serial-intake.view', 'serial-intake.create', 'serial-intake.update', 'serial-intake.delete', 'serial-intake.approve'],
            'serial-change' => ['serial-change.view', 'serial-change.create', 'serial-change.update', 'serial-change.delete', 'serial-change.approve'],
            'serial-hpp'    => ['serial-hpp.view', 'serial-hpp.create', 'serial-hpp.update', 'serial-hpp.delete', 'serial-hpp.approve'],
            'hpp'           => ['hpp.view', 'hpp.create', 'hpp.update', 'hpp.delete', 'hpp.approve'],
        ];
        foreach ($expected as $prefix => $perms2) {
            sort($perms2);
            $actual = $modules[$prefix]['permissions'];
            sort($actual);
            $this->assertSame($perms2, $actual, "Permission modul '{$prefix}' harus persis");
        }
    }
    #[Test]
    public function prefix_hpp_tidak_menyerap_permission_serial_hpp(): void
    {
        // Bug-prone: 'serial-hpp.view' TIDAK boleh masuk modul 'hpp' (dan sebaliknya).
        $modules = $this->seedAndFetchModules([
            'hpp.view', 'hpp.approve',
            'serial-hpp.view', 'serial-hpp.approve',
        ]);

        $this->assertNotContains('serial-hpp.view', $modules['hpp']['permissions']);
        $this->assertNotContains('serial-hpp.approve', $modules['hpp']['permissions']);
        $this->assertNotContains('hpp.view', $modules['serial-hpp']['permissions']);
        $this->assertNotContains('hpp.approve', $modules['serial-hpp']['permissions']);
    }
    #[Test]
    public function serial_intake_dikelompokkan_di_grup_pembelian(): void
    {
        foreach (['role.update', 'serial-intake.view'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $admin = User::factory()->create();
        $admin->givePermissionTo('role.update');

        $groups = collect(
            $this->actingAs($admin)->getJson('/api/v1/roles/permissions')->assertOk()->json('data.groups')
        );

        $pembelian = $groups->firstWhere('label', 'Pembelian');
        $this->assertNotNull($pembelian, 'Grup Pembelian harus ada');
        $prefixes = collect($pembelian['modules'])->pluck('prefix');
        $this->assertTrue($prefixes->contains('serial-intake'), 'serial-intake harus di grup Pembelian');
    }
    #[Test]
    public function serial_change_dan_serial_hpp_di_grup_yang_benar(): void
    {
        foreach (['role.update', 'serial-change.view', 'serial-hpp.view'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $admin = User::factory()->create();
        $admin->givePermissionTo('role.update');

        $groups = collect(
            $this->actingAs($admin)->getJson('/api/v1/roles/permissions')->assertOk()->json('data.groups')
        );

        // serial-change → Master Data; serial-hpp → Inventory.
        $masterData = collect($groups->firstWhere('label', 'Master Data')['modules'])->pluck('prefix');
        $inventory = collect($groups->firstWhere('label', 'Inventory')['modules'])->pluck('prefix');

        $this->assertTrue($masterData->contains('serial-change'), 'serial-change harus di Master Data');
        $this->assertTrue($inventory->contains('serial-hpp'), 'serial-hpp harus di Inventory');
    }
    #[Test]
    public function endpoint_permissions_butuh_izin_role_update(): void
    {
        // Tanpa role.update → 403 Unauthorized (guard di RoleController::permissions).
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/v1/roles/permissions')
            ->assertStatus(403)
            ->assertJson(['success' => false]);
    }
}
