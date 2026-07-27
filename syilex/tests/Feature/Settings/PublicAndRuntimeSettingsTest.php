<?php

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\SettingService;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PublicAndRuntimeSettingsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::clearCache();
        $this->seed(SettingSeeder::class);
        SettingService::clearCache();
    }

    #[Test]
    public function public_settings_omit_business_groups(): void
    {
        $res = $this->getJson('/api/v1/settings/public')->assertOk();
        $data = $res->json('data');

        $this->assertArrayHasKey('store', $data);
        $this->assertArrayHasKey('currency', $data);
        $this->assertArrayHasKey('modules', $data);

        $this->assertArrayNotHasKey('tax', $data);
        $this->assertArrayNotHasKey('stock', $data);
        $this->assertArrayNotHasKey('promo', $data);
        $this->assertArrayNotHasKey('calculation', $data);
        $this->assertArrayNotHasKey('rounding', $data);
        $this->assertArrayNotHasKey('product', $data);
        $this->assertArrayNotHasKey('npwp', $data['store'] ?? []);
    }

    #[Test]
    public function runtime_settings_require_auth_and_expose_business_groups(): void
    {
        $this->getJson('/api/v1/settings/runtime')->assertUnauthorized();

        $user = User::factory()->create();
        $res = $this->actingAs($user)->getJson('/api/v1/settings/runtime')->assertOk();
        $data = $res->json('data');

        foreach (['tax', 'rounding', 'product', 'promo', 'stock', 'calculation'] as $group) {
            $this->assertArrayHasKey($group, $data);
        }
    }
}
