<?php

namespace Tests\Feature\Services;

use App\Models\DocPriceChange;
use App\Models\Setting;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class SettingServiceTest extends TestCase
{
    use RefreshDatabase;

    // ============================================================
    // formatCode — pure, always uppercase
    // ============================================================
    #[Test]
    public function format_code_uppercases_and_trims()
    {
        $this->assertEquals('PRD-001', SettingService::formatCode('prd-001'));
        $this->assertEquals('PRD-001', SettingService::formatCode('  prd-001  '));
    }
    #[Test]
    public function format_code_returns_null_for_null()
    {
        $this->assertNull(SettingService::formatCode(null));
    }

    // ============================================================
    // formatName — depends on text.uppercase_mode setting
    // ============================================================
    #[Test]
    public function format_name_trims_without_uppercase_by_default()
    {
        SettingService::set('text.uppercase_mode', 'none', 'string');

        $this->assertEquals('hello world', SettingService::formatName('  hello world  '));
        $this->assertEquals('Mixed Case', SettingService::formatName('Mixed Case'));
    }
    #[Test]
    public function format_name_uppercases_when_mode_is_all()
    {
        SettingService::set('text.uppercase_mode', 'all', 'string');

        $this->assertEquals('HELLO WORLD', SettingService::formatName('hello world'));
        $this->assertEquals('MIXED CASE', SettingService::formatName('Mixed Case'));
    }
    #[Test]
    public function format_name_returns_null_for_null()
    {
        $this->assertNull(SettingService::formatName(null));
    }

    // ============================================================
    // calculateTax — pure function
    // ============================================================
    #[Test]
    public function calculate_tax_exclusive_adds_tax_on_top()
    {
        // Amount 100000 + PPN 11% exclusive → tax 11000, total 111000
        $result = SettingService::calculateTax(100000, 11, false);

        $this->assertEquals(100000, $result['base_amount']);
        $this->assertEquals(11000, $result['tax_amount']);
        $this->assertEquals(111000, $result['total_amount']);
    }
    #[Test]
    public function calculate_tax_inclusive_extracts_tax_from_amount()
    {
        // Amount 111000 includes 11% PPN → base 100000, tax 11000
        $result = SettingService::calculateTax(111000, 11, true);

        $this->assertEquals(100000, $result['base_amount']);
        $this->assertEquals(11000, $result['tax_amount']);
        $this->assertEquals(111000, $result['total_amount']);
    }
    #[Test]
    public function calculate_tax_with_zero_percent()
    {
        $result = SettingService::calculateTax(50000, 0, false);

        $this->assertEquals(50000, $result['base_amount']);
        $this->assertEquals(0, $result['tax_amount']);
        $this->assertEquals(50000, $result['total_amount']);
    }

    // ============================================================
    // applyRounding — depends on rounding.{type}_method & _precision
    // ============================================================
    #[Test]
    public function apply_rounding_none_returns_value_unchanged()
    {
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('rounding.sales_precision', 0, 'integer');

        $this->assertEquals(12345.67, SettingService::applyRounding(12345.67, 'sales'));
    }
    #[Test]
    public function apply_rounding_round_nearest_100()
    {
        SettingService::set('rounding.sales_method', 'round', 'string');
        SettingService::set('rounding.sales_precision', 100, 'integer');

        // 12345 → 12300 (closer than 12400)
        $this->assertEquals(12300, SettingService::applyRounding(12345, 'sales'));
        // 12350 → 12400 (PHP round half away from zero)
        $this->assertEquals(12400, SettingService::applyRounding(12350, 'sales'));
        // 12355 → 12400
        $this->assertEquals(12400, SettingService::applyRounding(12355, 'sales'));
    }
    #[Test]
    public function apply_rounding_floor_500()
    {
        SettingService::set('rounding.sales_method', 'floor', 'string');
        SettingService::set('rounding.sales_precision', 500, 'integer');

        // 12345 → 12000 (floor to nearest 500)
        $this->assertEquals(12000, SettingService::applyRounding(12345, 'sales'));
        // 12500 → 12500 (already at multiple)
        $this->assertEquals(12500, SettingService::applyRounding(12500, 'sales'));
        // 12999 → 12500
        $this->assertEquals(12500, SettingService::applyRounding(12999, 'sales'));
    }
    #[Test]
    public function apply_rounding_ceil_1000()
    {
        SettingService::set('rounding.sales_method', 'ceil', 'string');
        SettingService::set('rounding.sales_precision', 1000, 'integer');

        // 12001 → 13000 (ceil to nearest 1000)
        $this->assertEquals(13000, SettingService::applyRounding(12001, 'sales'));
        // 12000 → 12000 (already at multiple)
        $this->assertEquals(12000, SettingService::applyRounding(12000, 'sales'));
    }
    #[Test]
    public function apply_rounding_purchase_type_independent_of_sales()
    {
        SettingService::set('rounding.sales_method', 'round', 'string');
        SettingService::set('rounding.sales_precision', 100, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');
        SettingService::set('rounding.purchase_precision', 0, 'integer');

        $this->assertEquals(12300, SettingService::applyRounding(12345, 'sales'));
        $this->assertEquals(12345, SettingService::applyRounding(12345, 'purchase'), 'Purchase should not round');
    }

    // ============================================================
    // formatCurrency — depends on currency settings
    // ============================================================
    #[Test]
    public function format_currency_default_indonesian_rupiah()
    {
        SettingService::set('currency.symbol', 'Rp', 'string');
        SettingService::set('currency.position', 'before', 'string');
        SettingService::set('currency.thousand_separator', '.', 'string');
        SettingService::set('currency.decimal_separator', ',', 'string');
        SettingService::set('currency.decimal_places', 0, 'integer');

        $this->assertEquals('Rp 12.345', SettingService::formatCurrency(12345));
        $this->assertEquals('Rp 1.000.000', SettingService::formatCurrency(1000000));
    }
    #[Test]
    public function format_currency_handles_negative_values()
    {
        SettingService::set('currency.symbol', 'Rp', 'string');
        SettingService::set('currency.position', 'before', 'string');
        SettingService::set('currency.thousand_separator', '.', 'string');
        SettingService::set('currency.decimal_separator', ',', 'string');
        SettingService::set('currency.decimal_places', 0, 'integer');

        $this->assertEquals('-Rp 12.345', SettingService::formatCurrency(-12345));
    }
    #[Test]
    public function format_currency_with_symbol_after()
    {
        SettingService::set('currency.symbol', 'IDR', 'string');
        SettingService::set('currency.position', 'after', 'string');
        SettingService::set('currency.thousand_separator', ',', 'string');
        SettingService::set('currency.decimal_separator', '.', 'string');
        SettingService::set('currency.decimal_places', 2, 'integer');

        $this->assertEquals('12,345.67 IDR', SettingService::formatCurrency(12345.67));
    }

    // ============================================================
    // getPrefix — fallback to defaults
    // ============================================================
    #[Test]
    public function get_prefix_returns_default_when_setting_not_set()
    {
        // Defaults diharmonisasi ke 3 karakter (lihat SettingService::getPrefix).
        $this->assertEquals('INV', SettingService::getPrefix('sales'));
        $this->assertEquals('POR', SettingService::getPrefix('purchase_order'));
        $this->assertEquals('ADJ', SettingService::getPrefix('adjustment'));
        $this->assertEquals('PRM', SettingService::getPrefix('promo'));
        $this->assertEquals('HPC', SettingService::getPrefix('hpp_correction'));
    }

    // ============================================================
    // Setting cache behavior
    // ============================================================
    #[Test]
    public function setting_set_clears_cache_so_next_get_is_fresh()
    {
        SettingService::set('tax.tax_sales_percent', 11, 'integer');
        $this->assertEquals(11, SettingService::getSalesTaxSettings()['percent']);

        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        $this->assertEquals(0, SettingService::getSalesTaxSettings()['percent'], 'Cache should be cleared on set');
    }

    // ============================================================
    // Timezone — driven by regional.timezone setting
    // ============================================================
    #[Test]
    public function get_timezone_returns_jakarta_by_default()
    {
        SettingService::clearCache();
        $this->assertEquals('Asia/Jakarta', SettingService::getTimezone());
    }
    #[Test]
    public function get_timezone_returns_setting_value_when_set()
    {
        SettingService::set('regional.timezone', 'Asia/Makassar', 'string');
        $this->assertEquals('Asia/Makassar', SettingService::getTimezone());
    }
    #[Test]
    public function get_timezone_offset_converts_jakarta_to_plus_seven()
    {
        SettingService::set('regional.timezone', 'Asia/Jakarta', 'string');
        $this->assertEquals('+07:00', SettingService::getTimezoneOffset());
    }
    #[Test]
    public function get_timezone_offset_converts_makassar_to_plus_eight()
    {
        SettingService::set('regional.timezone', 'Asia/Makassar', 'string');
        $this->assertEquals('+08:00', SettingService::getTimezoneOffset());
    }
    #[Test]
    public function get_timezone_offset_converts_jayapura_to_plus_nine()
    {
        SettingService::set('regional.timezone', 'Asia/Jayapura', 'string');
        $this->assertEquals('+09:00', SettingService::getTimezoneOffset());
    }
    #[Test]
    public function get_timezone_offset_converts_utc_to_zero()
    {
        SettingService::set('regional.timezone', 'UTC', 'string');
        $this->assertEquals('+00:00', SettingService::getTimezoneOffset());
    }
    #[Test]
    public function get_timezone_offset_falls_back_when_timezone_invalid()
    {
        SettingService::set('regional.timezone', 'Not/A/Real/Zone', 'string');
        // Should not throw — falls back to '+07:00'
        $this->assertEquals('+07:00', SettingService::getTimezoneOffset());
    }

    // ============================================================
    // set/get — round-trip casting per tipe (string/int/decimal/bool/json)
    // ============================================================
    #[Test]
    public function set_get_string_dipertahankan_apa_adanya()
    {
        SettingService::set('store.name', 'TOKO ABC', 'string');
        $this->assertSame('TOKO ABC', SettingService::get('store.name'));
    }
    #[Test]
    public function set_get_integer_di_cast_ke_int()
    {
        SettingService::set('number.qty_decimal_places', 3, 'integer');
        $value = SettingService::get('number.qty_decimal_places');

        $this->assertSame(3, $value, 'Harus int murni, bukan string "3"');
        $this->assertIsInt($value);
    }
    #[Test]
    public function set_get_decimal_di_cast_ke_float()
    {
        SettingService::set('promo.max_manual_discount_percent', 12.5, 'decimal');
        $value = SettingService::get('promo.max_manual_discount_percent');

        $this->assertSame(12.5, $value);
        $this->assertIsFloat($value);
    }
    #[Test]
    public function set_get_boolean_true_dan_false_di_cast_ke_bool()
    {
        SettingService::set('promo.enabled', true, 'boolean');
        $this->assertTrue(SettingService::get('promo.enabled'));
        $this->assertIsBool(SettingService::get('promo.enabled'));

        SettingService::set('promo.enabled', false, 'boolean');
        $this->assertFalse(SettingService::get('promo.enabled'));
    }
    #[Test]
    public function set_get_json_di_decode_ke_array_asosiatif()
    {
        $data = ['a' => 1, 'b' => ['c' => 2], 'list' => [10, 20]];
        SettingService::set('custom.payload', $data, 'json');

        $value = SettingService::get('custom.payload');

        $this->assertIsArray($value);
        $this->assertSame($data, $value, 'JSON round-trip harus identik');
    }
    #[Test]
    public function set_dengan_key_tanpa_titik_melempar_exception()
    {
        $this->expectException(\InvalidArgumentException::class);
        SettingService::set('tanpatitik', 'x', 'string');
    }
    #[Test]
    public function set_memperbarui_nilai_existing_bukan_membuat_duplikat()
    {
        SettingService::set('store.phone', '0811', 'string');
        SettingService::set('store.phone', '0822', 'string');

        $this->assertSame('0822', SettingService::get('store.phone'));
        $this->assertEquals(1, Setting::where('group', 'store')->where('key', 'phone')->count(),
            'firstOrNew harus update baris yang sama, bukan duplikat');
    }
    #[Test]
    public function get_mengembalikan_default_ketika_key_tidak_ada()
    {
        $this->assertSame('FALLBACK', SettingService::get('group.tidak_ada', 'FALLBACK'));
        $this->assertNull(SettingService::get('group.tidak_ada'));
    }

    // ============================================================
    // getDiscountMode — default recursive
    // ============================================================
    #[Test]
    public function get_discount_mode_default_recursive()
    {
        SettingService::clearCache();
        $this->assertSame('recursive', SettingService::getDiscountMode());
    }
    #[Test]
    public function get_discount_mode_mengikuti_setting_sum()
    {
        SettingService::set('calculation.discount_mode', 'sum', 'string');
        $this->assertSame('sum', SettingService::getDiscountMode());
    }

    // ============================================================
    // getPurchaseTaxSettings — default included_in_hpp = false
    // ============================================================
    #[Test]
    public function get_purchase_tax_settings_default_eksak()
    {
        SettingService::clearCache();
        $settings = SettingService::getPurchaseTaxSettings();

        $this->assertSame('PPN', $settings['name']);
        $this->assertSame(11.0, $settings['percent']);
        $this->assertIsFloat($settings['percent']);
        $this->assertFalse($settings['included_in_hpp'], 'Default included_in_hpp HARUS false');
        $this->assertIsBool($settings['included_in_hpp']);
    }
    #[Test]
    public function get_purchase_tax_settings_included_in_hpp_bisa_diaktifkan()
    {
        SettingService::set('tax.tax_purchase_included_in_hpp', true, 'boolean');
        SettingService::set('tax.tax_purchase_percent', 10, 'integer');
        SettingService::set('tax.tax_purchase_name', 'VAT', 'string');

        $settings = SettingService::getPurchaseTaxSettings();

        $this->assertSame('VAT', $settings['name']);
        $this->assertSame(10.0, $settings['percent']);
        $this->assertTrue($settings['included_in_hpp']);
    }

    // ============================================================
    // applyRounding — short-circuit precision 0 / method none
    // ============================================================
    #[Test]
    public function apply_rounding_precision_nol_mengembalikan_nilai_apa_adanya_walau_method_round()
    {
        // Guard kode: method 'none' ATAU precision 0 → kembalikan apa adanya.
        SettingService::set('rounding.sales_method', 'round', 'string');
        SettingService::set('rounding.sales_precision', 0, 'integer');

        $this->assertSame(12345.67, SettingService::applyRounding(12345.67, 'sales'));
    }
    #[Test]
    public function apply_rounding_purchase_dan_sales_independen_dengan_nilai_eksak()
    {
        SettingService::set('rounding.sales_method', 'ceil', 'string');
        SettingService::set('rounding.sales_precision', 1000, 'integer');
        SettingService::set('rounding.purchase_method', 'floor', 'string');
        SettingService::set('rounding.purchase_precision', 500, 'integer');

        // sales ceil/1000: 12001 → 13000 ; purchase floor/500: 12999 → 12500
        $this->assertEquals(13000, SettingService::applyRounding(12001, 'sales'));
        $this->assertEquals(12500, SettingService::applyRounding(12999, 'purchase'));
    }

    // ============================================================
    // generateDocumentNumber — sequence, prefix 3-char, format
    // ============================================================
    #[Test]
    public function generate_document_number_pertama_dimulai_dari_0001()
    {
        $now = SettingService::now();
        $expected = sprintf('PCH-%s-0001', $now->format('ym'));

        $nomor = SettingService::generateDocumentNumber('price_change', 'doc_price_change');

        $this->assertSame($expected, $nomor);
    }
    #[Test]
    public function generate_document_number_format_prefix_3_char_dan_yymm()
    {
        $nomor = SettingService::generateDocumentNumber('price_change', 'doc_price_change');

        // Pola eksak: PCH-YYMM-NNNN
        $this->assertMatchesRegularExpression('/^PCH-\d{4}-\d{4}$/', $nomor);
        $parts = explode('-', $nomor);
        $this->assertSame('PCH', $parts[0]);
        $this->assertSame(3, strlen($parts[0]), 'Prefix harus 3 karakter');
        $this->assertSame(SettingService::now()->format('ym'), $parts[1]);
    }
    #[Test]
    public function generate_document_number_increment_dari_dokumen_terakhir()
    {
        $now = SettingService::now();
        $ym = $now->format('ym');

        // Sisipkan dokumen existing dengan sequence 0007.
        DocPriceChange::create([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => "PCH-{$ym}-0007",
            'tanggal_pengajuan' => '2026-04-10 00:00:00',
            'tanggal_berlaku' => '2026-04-20 00:00:00',
            'status' => 'draft',
            'created_by' => null,
        ]);

        $nomor = SettingService::generateDocumentNumber('price_change', 'doc_price_change');

        $this->assertSame("PCH-{$ym}-0008", $nomor, 'Sequence harus lanjut dari 0007 → 0008');
    }
    #[Test]
    public function generate_document_number_memakai_prefix_kustom_dari_setting()
    {
        SettingService::set('prefix.price_change', 'XYZ', 'string');
        $ym = SettingService::now()->format('ym');

        $nomor = SettingService::generateDocumentNumber('price_change', 'doc_price_change');

        $this->assertSame("XYZ-{$ym}-0001", $nomor);
    }

    // ============================================================
    // getPrefix — fallback uppercase untuk tipe tak dikenal
    // ============================================================
    #[Test]
    public function get_prefix_fallback_uppercase_untuk_tipe_tak_dikenal()
    {
        // Tidak ada di defaults & tidak ada setting → strtoupper(type).
        $this->assertSame('MYSTERY', SettingService::getPrefix('mystery'));
    }
    #[Test]
    public function get_prefix_3_char_untuk_semua_tipe_dokumen_inti()
    {
        SettingService::clearCache();
        foreach (['purchase_order', 'purchase_return', 'sales', 'sales_return', 'price_change', 'hpp_correction', 'promo'] as $type) {
            $prefix = SettingService::getPrefix($type);
            $this->assertSame(3, strlen($prefix), "Prefix '{$type}' harus 3 karakter, dapat '{$prefix}'");
        }
    }

    // ============================================================
    // calculateTax — pembulatan inklusif dengan pecahan
    // ============================================================
    #[Test]
    public function calculate_tax_inclusive_dengan_pembulatan_dua_desimal()
    {
        // amount 100000 inklusif 11%: tax = 100000 - 100000/1.11 = 9909.909... → round 9909.91
        $result = SettingService::calculateTax(100000, 11, true);

        $this->assertSame(9909.91, $result['tax_amount']);
        $this->assertSame(90090.09, $result['base_amount']);
        $this->assertSame(100000.0, $result['total_amount']);
    }
}
