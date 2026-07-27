<?php

namespace Tests\Feature\Settings;

use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\Setting;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SettingWriteGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::clearCache();

        foreach (['settings.view', 'settings.update'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['settings.view', 'settings.update']);

        Setting::updateOrCreate(
            ['group' => 'stock', 'key' => 'negative_mode'],
            ['value' => 'block', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['group' => 'product', 'key' => 'price_input_mode'],
            ['value' => 'auto', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['group' => 'currency', 'key' => 'thousand_separator'],
            ['value' => '.', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['group' => 'currency', 'key' => 'decimal_separator'],
            ['value' => ',', 'type' => 'string']
        );
        Setting::updateOrCreate(
            ['group' => 'modules', 'key' => 'elektronik_enabled'],
            ['value' => 'true', 'type' => 'boolean']
        );
        SettingService::clearCache();
    }

    #[Test]
    public function single_update_blocks_stock_mode_when_stock_card_exists(): void
    {
        $wh = MasterWarehouse::factory()->create(['status' => 'active']);
        $produk = MasterProduk::factory()->create();
        StockCard::create([
            'product_id' => $produk->id,
            'warehouse_id' => $wh->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => now(),
            'qty_in' => 1,
            'qty_out' => 0,
            'qty_balance' => 1,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/stock/negative_mode', ['value' => 'allow'])
            ->assertStatus(422);
    }

    #[Test]
    public function bulk_update_blocks_price_mode_when_products_exist(): void
    {
        MasterProduk::factory()->create();

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/bulk', [
                'settings' => ['product.price_input_mode' => 'manual'],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function rejects_invalid_enum_and_timezone(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/stock/negative_mode', ['value' => 'maybe'])
            ->assertStatus(422);

        Setting::updateOrCreate(
            ['group' => 'regional', 'key' => 'timezone'],
            ['value' => 'Asia/Jakarta', 'type' => 'string']
        );
        SettingService::clearCache();

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/regional/timezone', ['value' => 'Mars/Olympus'])
            ->assertStatus(422);
    }

    #[Test]
    public function rejects_activity_log_retention_out_of_range(): void
    {
        Setting::updateOrCreate(
            ['group' => 'scheduler', 'key' => 'activity_log_retention_days'],
            ['value' => '365', 'type' => 'integer']
        );
        SettingService::clearCache();

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/scheduler/activity_log_retention_days', ['value' => 10])
            ->assertStatus(422);

        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/scheduler/activity_log_retention_days', ['value' => 365])
            ->assertOk();
    }

    #[Test]
    public function ignores_client_type_override_on_boolean(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/modules/elektronik_enabled', [
                'value' => false,
                'type' => 'string',
            ])
            ->assertOk();

        $row = Setting::where('group', 'modules')->where('key', 'elektronik_enabled')->first();
        $this->assertSame('boolean', $row->type);
        $this->assertFalse(SettingService::isElektronikEnabled());
    }

    #[Test]
    public function update_group_rejects_unknown_key(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/group/store', [
                'settings' => [
                    ['key' => 'totally_fake_key', 'value' => 'x'],
                ],
            ])
            ->assertStatus(422);
    }

    #[Test]
    public function rejects_same_currency_separators(): void
    {
        $this->actingAs($this->admin)
            ->putJson('/api/v1/settings/group/currency', [
                'settings' => [
                    ['key' => 'thousand_separator', 'value' => '.'],
                    ['key' => 'decimal_separator', 'value' => '.'],
                ],
            ])
            ->assertStatus(422);
    }
}
