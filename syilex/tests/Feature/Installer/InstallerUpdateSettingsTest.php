<?php

namespace Tests\Feature\Installer;

use App\Http\Controllers\InstallerController;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use Tests\TestCase;

class InstallerUpdateSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_settings_persists_wizard_extended_keys(): void
    {
        $this->seed(\Database\Seeders\SettingSeeder::class);

        $controller = new InstallerController();
        $method = (new ReflectionClass($controller))->getMethod('updateSettings');
        $method->setAccessible(true);
        $method->invoke($controller, [
            'name' => 'Toko Uji',
            'address' => 'Jl. Uji 1',
            'phone' => '021',
            'email' => 'uji@toko.test',
            'npwp' => '',
            'url' => 'http://posip.test',
            'receipt_footer' => 'Sukses selalu!',
        ], [
            'timezone' => 'Asia/Jakarta',
            'date_format' => 'DD/MM/YYYY',
            'time_format' => 'HH:mm',
            'currency_code' => 'IDR',
            'currency_symbol' => 'Rp',
            'currency_position' => 'before',
            'thousand_separator' => '.',
            'decimal_separator' => ',',
            'decimal_places' => '0',
            'qty_decimal_places' => '0',
            'percent_decimal_places' => '3',
            'uppercase_mode' => 'all',
        ], [
            'tax_purchase_name' => 'PPN',
            'tax_purchase_percent' => '11',
            'tax_purchase_included_in_hpp' => false,
            'tax_sales_name' => 'PPN',
            'tax_sales_percent' => '11',
            'rounding_sales_method' => 'round',
            'rounding_sales_precision' => '100',
            'rounding_purchase_method' => 'floor',
            'rounding_purchase_precision' => '500',
            'negative_mode' => 'allow',
            'discount_mode' => 'recursive',
            'price_input_mode' => 'auto',
            'elektronik_enabled' => true,
            'sales_allow_free' => false,
            'sales_free_require_sold' => true,
            'purchase_allow_free' => true,
            'purchase_free_require_purchased' => true,
        ], [
            'enabled' => true,
            'allow_manual_discount' => true,
            'max_manual_discount_percent' => 100,
            'max_manual_discount_nominal' => null,
        ]);

        SettingService::clearCache();

        $this->assertSame('http://posip.test', SettingService::get('store.url'));
        $this->assertSame('Sukses selalu!', SettingService::get('store.receipt_footer'));
        $this->assertSame(3, (int) SettingService::get('number.percent_decimal_places'));
        $this->assertSame('all', SettingService::get('text.uppercase_mode'));
        $this->assertSame('floor', SettingService::get('rounding.purchase_method'));
        $this->assertSame(500, (int) SettingService::get('rounding.purchase_precision'));
        $this->assertSame('allow', SettingService::get('stock.negative_mode'));
        $this->assertFalse((bool) filter_var(SettingService::get('returns.sales_allow_free'), FILTER_VALIDATE_BOOLEAN));
        $this->assertTrue((bool) filter_var(SettingService::get('returns.purchase_free_require_purchased'), FILTER_VALIDATE_BOOLEAN));

        $this->assertDatabaseHas('settings', [
            'group' => 'returns',
            'key' => 'sales_allow_free',
            'value' => 'false',
        ]);
    }
}
