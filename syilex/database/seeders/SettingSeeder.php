<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;
use App\Services\SettingService;

class SettingSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            // =====================================================================
            // STORE INFO
            // =====================================================================
            ['group' => 'store', 'key' => 'name', 'value' => 'POSIP Store', 'type' => 'string'],
            ['group' => 'store', 'key' => 'address', 'value' => 'Jl. Contoh No. 123, Jakarta', 'type' => 'string'],
            ['group' => 'store', 'key' => 'phone', 'value' => '021-1234567', 'type' => 'string'],
            ['group' => 'store', 'key' => 'email', 'value' => 'info@posip.com', 'type' => 'string'],
            ['group' => 'store', 'key' => 'logo', 'value' => null, 'type' => 'string'],
            ['group' => 'store', 'key' => 'icon', 'value' => null, 'type' => 'string'],
            ['group' => 'store', 'key' => 'npwp', 'value' => '', 'type' => 'string'],

            // =====================================================================
            // REGIONAL
            // =====================================================================
            ['group' => 'regional', 'key' => 'timezone', 'value' => 'Asia/Jakarta', 'type' => 'string'],
            ['group' => 'regional', 'key' => 'date_format', 'value' => 'DD/MM/YYYY', 'type' => 'string'],
            ['group' => 'regional', 'key' => 'time_format', 'value' => 'HH:mm', 'type' => 'string'],

            // =====================================================================
            // CURRENCY
            // =====================================================================
            ['group' => 'currency', 'key' => 'code', 'value' => 'IDR', 'type' => 'string'],
            ['group' => 'currency', 'key' => 'symbol', 'value' => 'Rp', 'type' => 'string'],
            ['group' => 'currency', 'key' => 'position', 'value' => 'before', 'type' => 'string'],
            ['group' => 'currency', 'key' => 'thousand_separator', 'value' => '.', 'type' => 'string'],
            ['group' => 'currency', 'key' => 'decimal_separator', 'value' => ',', 'type' => 'string'],
            ['group' => 'currency', 'key' => 'decimal_places', 'value' => '0', 'type' => 'integer'],

            // =====================================================================
            // NUMBER
            // =====================================================================
            ['group' => 'number', 'key' => 'qty_decimal_places', 'value' => '0', 'type' => 'integer'],
            ['group' => 'number', 'key' => 'percent_decimal_places', 'value' => '2', 'type' => 'integer'],

            // =====================================================================
            // TAX (dengan prefix tax_)
            // =====================================================================
            ['group' => 'tax', 'key' => 'tax_purchase_name', 'value' => 'PPN', 'type' => 'string'],
            ['group' => 'tax', 'key' => 'tax_purchase_percent', 'value' => '11', 'type' => 'decimal'],
            ['group' => 'tax', 'key' => 'tax_purchase_included_in_hpp', 'value' => 'false', 'type' => 'boolean'],
            ['group' => 'tax', 'key' => 'tax_sales_name', 'value' => 'PPN', 'type' => 'string'],
            ['group' => 'tax', 'key' => 'tax_sales_percent', 'value' => '11', 'type' => 'decimal'],

            // =====================================================================
            // ROUNDING
            // =====================================================================
            ['group' => 'rounding', 'key' => 'purchase_method', 'value' => 'none', 'type' => 'string'],
            ['group' => 'rounding', 'key' => 'purchase_precision', 'value' => '0', 'type' => 'integer'],
            ['group' => 'rounding', 'key' => 'sales_method', 'value' => 'round', 'type' => 'string'],
            ['group' => 'rounding', 'key' => 'sales_precision', 'value' => '100', 'type' => 'integer'],

            // =====================================================================
            // STOCK
            // =====================================================================
            ['group' => 'stock', 'key' => 'negative_mode', 'value' => 'block', 'type' => 'string'],

            // =====================================================================
            // CALCULATION
            // =====================================================================
            ['group' => 'calculation', 'key' => 'discount_mode', 'value' => 'recursive', 'type' => 'string'],
            ['group' => 'calculation', 'key' => 'cost_allocation_mode', 'value' => 'by_value', 'type' => 'string'],

            // =====================================================================
            // PROMO
            // =====================================================================
            ['group' => 'promo', 'key' => 'enabled', 'value' => 'true', 'type' => 'boolean'],
            ['group' => 'promo', 'key' => 'allow_manual_discount', 'value' => 'true', 'type' => 'boolean'],
            ['group' => 'promo', 'key' => 'max_manual_discount_percent', 'value' => '100', 'type' => 'decimal'],
            ['group' => 'promo', 'key' => 'max_manual_discount_nominal', 'value' => null, 'type' => 'decimal'],
            ['group' => 'promo', 'key' => 'auto_apply', 'value' => 'true', 'type' => 'boolean'],
            ['group' => 'promo', 'key' => 'show_label', 'value' => 'true', 'type' => 'boolean'],

            // =====================================================================
            // PRODUCT
            // =====================================================================
            ['group' => 'product', 'key' => 'price_input_mode', 'value' => 'auto', 'type' => 'string'],

            // =====================================================================
            // TEXT
            // =====================================================================
            ['group' => 'text', 'key' => 'uppercase_mode', 'value' => 'code_only', 'type' => 'string'],

            // =====================================================================
            // PREFIX (untuk nomor dokumen) — semua default 3 karakter
            // =====================================================================
            ['group' => 'prefix', 'key' => 'purchase_order', 'value' => 'POR', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'purchase_return', 'value' => 'RPB', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'sales', 'value' => 'INV', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'sales_return', 'value' => 'RPJ', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'payment_hutang', 'value' => 'PBH', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'stock_opname', 'value' => 'OPN', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'adjustment', 'value' => 'ADJ', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'transfer', 'value' => 'TRF', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'repack', 'value' => 'RPK', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'price_change', 'value' => 'PCH', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'hpp_correction', 'value' => 'HPC', 'type' => 'string'],
            ['group' => 'prefix', 'key' => 'promo', 'value' => 'PRM', 'type' => 'string'],

            // =====================================================================
            // SCHEDULER (untuk auto-apply scheduled documents)
            // =====================================================================
            ['group' => 'scheduler', 'key' => 'price_change_enabled', 'value' => 'true', 'type' => 'boolean'],
            ['group' => 'scheduler', 'key' => 'price_change_cooldown', 'value' => '5', 'type' => 'integer'],
            ['group' => 'scheduler', 'key' => 'price_change_max_batch', 'value' => '50', 'type' => 'integer'],
            ['group' => 'scheduler', 'key' => 'activity_log_enabled', 'value' => 'true', 'type' => 'boolean'],
            ['group' => 'scheduler', 'key' => 'activity_log_cooldown', 'value' => '10080', 'type' => 'integer'],

            // =====================================================================
            // MODUL (toggle fitur opsional) — retail selalu aktif, elektronik bisa on/off
            // =====================================================================
            ['group' => 'modules', 'key' => 'elektronik_enabled', 'value' => 'true', 'type' => 'boolean'],
        ];

        foreach ($settings as $setting) {
            Setting::firstOrCreate(
                ['group' => $setting['group'], 'key' => $setting['key']],
                ['value' => $setting['value'], 'type' => $setting['type']]
            );
        }

        // Clear cache after seeding
        SettingService::clearCache();

        $this->command->info('Settings seeded successfully!');
        $this->command->info('Total settings: ' . count($settings));
    }
}
