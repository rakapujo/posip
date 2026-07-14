<?php

namespace Tests\Feature\Settings;

use App\Models\Setting;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Toggle Modul Elektronik (serial) — setting modules.elektronik_enabled.
 * Default ON (migration seed). Saat OFF: endpoint serial diblok, produk serial tak bisa dibuat,
 * dan toggle tak bisa dimatikan selama masih ada data serial.
 */
class ElektronikModuleTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::clearCache(); // cegah cache bocor antar-test

        foreach ([
            'settings.view', 'settings.update', 'produk.create',
            'serial-intake.view', 'serial-change.view', 'serial-hpp.view',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo([
            'settings.view', 'settings.update', 'produk.create',
            'serial-intake.view', 'serial-change.view', 'serial-hpp.view',
        ]);
        $this->actingAs($this->admin);
    }

    private function setElektronik(bool $on): void
    {
        Setting::updateOrCreate(
            ['group' => 'modules', 'key' => 'elektronik_enabled'],
            ['value' => $on ? 'true' : 'false', 'type' => 'boolean']
        );
        SettingService::clearCache();
    }

    private function serialPayload(array $o = []): array
    {
        return array_merge([
            'kode_produk' => 'SRL_A', 'nama_produk' => 'MacBook Air M2',
            'status' => 'active', 'is_serial' => true,
        ], $o);
    }

    private const SERIAL_ENDPOINTS = [
        '/api/v1/serial-units',
        '/api/v1/serial-intakes',
        '/api/v1/serial-changes',
        '/api/v1/serial-hpp-corrections',
    ];
    #[Test]
    public function default_on_serial_endpoints_accessible()
    {
        // Migration menanam modules.elektronik_enabled=true → endpoint serial bisa diakses.
        $this->assertTrue(SettingService::isElektronikEnabled());
        $this->getJson('/api/v1/serial-units')->assertOk();
    }
    #[Test]
    public function helper_defaults_true_when_setting_row_absent()
    {
        Setting::where('group', 'modules')->where('key', 'elektronik_enabled')->delete();
        SettingService::clearCache();
        $this->assertTrue(SettingService::isElektronikEnabled());
        $this->getJson('/api/v1/serial-units')->assertOk();
    }
    #[Test]
    public function off_blocks_all_serial_endpoints_403()
    {
        $this->setElektronik(false);
        foreach (self::SERIAL_ENDPOINTS as $url) {
            $this->getJson($url)->assertStatus(403);
        }
    }
    #[Test]
    public function off_blocks_serial_product_create()
    {
        $this->setElektronik(false);
        $this->postJson('/api/v1/produks', $this->serialPayload())->assertStatus(422);
    }
    #[Test]
    public function off_still_allows_retail_product_create()
    {
        $this->setElektronik(false);
        $this->postJson('/api/v1/produks', [
            'kode_produk' => 'RTL_X', 'nama_produk' => 'Charger', 'status' => 'active', 'is_serial' => false,
            'minimum_stok' => 5,
            'unit_1' => 'PCS', 'konversi_1' => 1, 'harga_1' => 50000,
            'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 50000,
            'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 50000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 50000,
        ])->assertStatus(201);
    }
    #[Test]
    public function cannot_disable_while_serial_product_exists()
    {
        // Buat produk serial (elektronik default ON) lalu coba matikan → ditolak (lock).
        $this->postJson('/api/v1/produks', $this->serialPayload())->assertStatus(201);

        $this->putJson('/api/v1/settings/group/modules', [
            'settings' => [['key' => 'elektronik_enabled', 'value' => false, 'type' => 'boolean']],
        ])->assertStatus(422);

        // Masih aktif
        $this->assertTrue(SettingService::isElektronikEnabled());
    }
/** F: lock juga berlaku di jalur single-update & bulk-update (bukan cuma updateGroup). */
    #[Test]
    public function cannot_disable_via_single_update_or_bulk_when_serial_exists()
    {
        $this->postJson('/api/v1/produks', $this->serialPayload())->assertStatus(201);

        // Jalur single update: PUT /settings/{group}/{key}
        $this->putJson('/api/v1/settings/modules/elektronik_enabled', [
            'value' => false, 'type' => 'boolean',
        ])->assertStatus(422);

        // Jalur bulk update: PUT /settings/bulk
        $this->putJson('/api/v1/settings/bulk', [
            'settings' => ['modules.elektronik_enabled' => false],
        ])->assertStatus(422);

        $this->assertTrue(SettingService::isElektronikEnabled());
    }
    #[Test]
    public function can_disable_when_no_serial_data()
    {
        $this->putJson('/api/v1/settings/group/modules', [
            'settings' => [['key' => 'elektronik_enabled', 'value' => false, 'type' => 'boolean']],
        ])->assertOk();

        SettingService::clearCache();
        $this->assertFalse(SettingService::isElektronikEnabled());
        // Setelah OFF, endpoint serial diblok
        $this->getJson('/api/v1/serial-units')->assertStatus(403);
    }
    #[Test]
    public function elektronik_lock_endpoint_reflects_serial_data()
    {
        // Tanpa data serial → tidak terkunci
        $res = $this->getJson('/api/v1/settings/elektronik-lock')->assertOk();
        $res->assertJsonPath('data.locked', false);
        $res->assertJsonPath('data.serial_products', 0);

        // Setelah ada produk serial → terkunci
        $this->postJson('/api/v1/produks', $this->serialPayload())->assertStatus(201);
        $res = $this->getJson('/api/v1/settings/elektronik-lock')->assertOk();
        $res->assertJsonPath('data.locked', true);
        $this->assertEquals(1, $res->json('data.serial_products'));
    }
    #[Test]
    public function public_settings_expose_modules_group()
    {
        $this->getJson('/api/v1/settings/public')->assertOk()
            ->assertJsonPath('data.modules.elektronik_enabled', true);
    }
}
