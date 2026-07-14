<?php

namespace Tests\Unit\Services;

use App\Services\SalesCalculationService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Catatan: sebagian besar method adalah pure function (tidak butuh DB),
 * tetapi calculateTotals() membaca setting (discount_mode, pajak, rounding)
 * sehingga butuh framework + DB. Karena PHPUnit hanya mendiscover satu test
 * class per file (file→class), seluruh test digabung di SATU class yang
 * extends Tests\TestCase. Booting app tidak mengganggu test pure di atas.
 */
class SalesCalculationServiceTest extends TestCase
{
    use RefreshDatabase;
    #[Test]
    public function calculate_line_item_without_discount()
    {
        $result = SalesCalculationService::calculateLineItem(2, 10000);

        $this->assertEquals(0, $result['diskon_nominal']);
        $this->assertEquals(20000, $result['jumlah']);
    }
    #[Test]
    public function calculate_line_item_with_percent_discount()
    {
        $result = SalesCalculationService::calculateLineItem(2, 10000, 10);

        $this->assertEquals(2000, $result['diskon_nominal']);
        $this->assertEquals(18000, $result['jumlah']);
    }
    #[Test]
    public function calculate_line_item_with_decimal_qty()
    {
        $result = SalesCalculationService::calculateLineItem(2.5, 10000);

        $this->assertEquals(0, $result['diskon_nominal']);
        $this->assertEquals(25000, $result['jumlah']);
    }
    #[Test]
    public function calculate_discount_level_percent()
    {
        $hasil = SalesCalculationService::calculateDiscountLevel('percent', 10, 100000);
        $this->assertEquals(10000, $hasil);
    }
    #[Test]
    public function calculate_discount_level_nominal()
    {
        $hasil = SalesCalculationService::calculateDiscountLevel('nominal', 5000, 100000);
        $this->assertEquals(5000, $hasil);
    }
    #[Test]
    public function calculate_discount_level_nominal_capped_at_base()
    {
        $hasil = SalesCalculationService::calculateDiscountLevel('nominal', 200000, 100000);
        $this->assertEquals(100000, $hasil, 'Discount nominal should be capped at base amount');
    }
    #[Test]
    public function calculate_discount_level_none_returns_zero()
    {
        $hasil = SalesCalculationService::calculateDiscountLevel('none', 10, 100000);
        $this->assertEquals(0, $hasil);
    }
    #[Test]
    public function calculate_biaya_level_percent()
    {
        $hasil = SalesCalculationService::calculateBiayaLevel('percent', 5, 100000);
        $this->assertEquals(5000, $hasil);
    }
    #[Test]
    public function calculate_biaya_level_nominal_not_capped()
    {
        $hasil = SalesCalculationService::calculateBiayaLevel('nominal', 200000, 100000);
        $this->assertEquals(200000, $hasil, 'Biaya nominal should NOT be capped (unlike discount)');
    }
    #[Test]
    public function calculate_biaya_level_none()
    {
        $hasil = SalesCalculationService::calculateBiayaLevel('none', 5, 100000);
        $this->assertEquals(0, $hasil);
    }
    #[Test]
    public function calculate_payment_fee_percent()
    {
        $fee = SalesCalculationService::calculatePaymentFee(100000, 'percent', 2);
        $this->assertEquals(2000, $fee);
    }
    #[Test]
    public function calculate_payment_fee_nominal()
    {
        $fee = SalesCalculationService::calculatePaymentFee(100000, 'nominal', 5000);
        $this->assertEquals(5000, $fee);
    }
    #[Test]
    public function calculate_payment_fee_none()
    {
        $fee = SalesCalculationService::calculatePaymentFee(100000, 'none', 5);
        $this->assertEquals(0, $fee);
    }
    #[Test]
    public function discount_level_percent_rounds_to_two_decimals()
    {
        // 33.33% of 1000 = 333.3 → rounded to 333.30
        $hasil = SalesCalculationService::calculateDiscountLevel('percent', 33.33, 1000);
        $this->assertEquals(333.30, $hasil);
    }

    // ============================================================
    // EDGE CASES — calculateLineItem (galak, nilai eksak)
    // ============================================================
    #[Test]
    public function line_item_diskon_persen_membulatkan_dua_desimal_eksak()
    {
        // bruto = 3 * 333 = 999 ; diskon 33.33% = 332.9667 → round(2) = 333.0 (332.97 sebenarnya)
        // 999 * 33.33 / 100 = 332.9667 → round 2 = 332.97
        $result = SalesCalculationService::calculateLineItem(3, 333, 33.33);

        $this->assertSame(332.97, $result['diskon_nominal']);
        $this->assertSame(666.03, round($result['jumlah'], 2));
    }
    #[Test]
    public function line_item_diskon_persen_nol_dianggap_tanpa_diskon()
    {
        // diskonPersen = 0 → tidak masuk cabang round, diskon_nominal int 0
        $result = SalesCalculationService::calculateLineItem(4, 2500, 0);

        $this->assertSame(0, $result['diskon_nominal']);
        $this->assertEquals(10000, $result['jumlah']);
    }
    #[Test]
    public function line_item_diskon_persen_negatif_diabaikan_karena_guard_lebih_dari_nol()
    {
        // Guard di kode: $diskonPersen > 0. Persen negatif → diskon 0 (bukan nambah harga).
        $result = SalesCalculationService::calculateLineItem(2, 10000, -10);

        $this->assertSame(0, $result['diskon_nominal']);
        $this->assertEquals(20000, $result['jumlah']);
    }
    #[Test]
    public function line_item_diskon_seratus_persen_jumlah_nol()
    {
        $result = SalesCalculationService::calculateLineItem(5, 4000, 100);

        $this->assertEquals(20000, $result['diskon_nominal']);
        $this->assertEquals(0, $result['jumlah']);
    }
    #[Test]
    public function line_item_qty_pecahan_dengan_diskon_persen_eksak()
    {
        // bruto = 1.5 * 12000 = 18000 ; diskon 7.5% = 1350 ; jumlah = 16650
        $result = SalesCalculationService::calculateLineItem(1.5, 12000, 7.5);

        $this->assertEquals(1350, $result['diskon_nominal']);
        $this->assertEquals(16650, $result['jumlah']);
    }

    // ============================================================
    // EDGE CASES — calculateDiscountLevel (cap & rounding)
    // ============================================================
    #[Test]
    public function discount_level_nominal_tepat_sama_base_tidak_dipotong()
    {
        // min(100000, 100000) = 100000 (batas tepat)
        $hasil = SalesCalculationService::calculateDiscountLevel('nominal', 100000, 100000);
        $this->assertEquals(100000, $hasil);
    }
    #[Test]
    public function discount_level_tipe_tidak_dikenal_mengembalikan_nol()
    {
        // match default → 0 (bukan exception)
        $this->assertEquals(0, SalesCalculationService::calculateDiscountLevel('unknown_type', 50, 100000));
        $this->assertEquals(0, SalesCalculationService::calculateDiscountLevel('', 50, 100000));
    }
    #[Test]
    public function discount_level_percent_half_up_membulatkan_ke_atas()
    {
        // 1000 * 12.345% = 123.45 (sudah 2 desimal). Uji pembulatan setengah:
        // 1000 * 0.125% = 1.25 → tetap 1.25 ; 1000 * 0.1255% = 1.255 → round(2) = 1.26 (half away from zero)
        $this->assertEquals(1.25, SalesCalculationService::calculateDiscountLevel('percent', 0.125, 1000));
        $this->assertEquals(1.26, SalesCalculationService::calculateDiscountLevel('percent', 0.1255, 1000));
    }

    // ============================================================
    // EDGE CASES — calculateBiayaLevel (tidak di-cap)
    // ============================================================
    #[Test]
    public function biaya_level_percent_membulatkan_dua_desimal()
    {
        // 100000 * 2.5% = 2500 ; 333 * 33.33% = 110.9889 → round 2 = 110.99
        $this->assertEquals(2500, SalesCalculationService::calculateBiayaLevel('percent', 2.5, 100000));
        $this->assertEquals(110.99, SalesCalculationService::calculateBiayaLevel('percent', 33.33, 333));
    }
    #[Test]
    public function biaya_level_tipe_tidak_dikenal_mengembalikan_nol()
    {
        $this->assertEquals(0, SalesCalculationService::calculateBiayaLevel('xx', 9999, 100000));
    }

    // ============================================================
    // EDGE CASES — calculatePaymentFee
    // ============================================================
    #[Test]
    public function payment_fee_percent_membulatkan_dua_desimal()
    {
        // 99999 * 2.5% = 2499.975 → round 2 = 2499.98
        $this->assertEquals(2499.98, SalesCalculationService::calculatePaymentFee(99999, 'percent', 2.5));
    }
    #[Test]
    public function payment_fee_nominal_tidak_di_cap_oleh_base()
    {
        // nominal fee bisa melebihi nominal transaksi (mis. biaya admin flat besar)
        $this->assertEquals(500000, SalesCalculationService::calculatePaymentFee(100000, 'nominal', 500000));
    }
    #[Test]
    public function payment_fee_tipe_tidak_dikenal_mengembalikan_nol()
    {
        $this->assertEquals(0, SalesCalculationService::calculatePaymentFee(100000, 'flat', 5));
    }

    // ============================================================
    // calculateTotals — butuh DB (baca setting). Verifikasi alur:
    // Subtotal → Disc 1,2,3 → Total → +Biaya → DPP → Pajak →
    // Pembulatan → Grand Total, dengan nilai EKSAK.
    // ============================================================

    /** Set baseline setting deterministik untuk calculateTotals. */
    private function setBaselineTotalsSettings(): void
    {
        SettingService::set('calculation.discount_mode', 'recursive', 'string');
        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('tax.tax_sales_name', 'PPN', 'string');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('rounding.sales_precision', 0, 'integer');
    }
    #[Test]
    public function totals_diskon_recursive_memakai_running_value_tiap_level()
    {
        $this->setBaselineTotalsSettings();
        // recursive: d1 = 10% * 100000 = 10000 (running 90000)
        //            d2 = 5%  * 90000  = 4500  (running 85500)
        //            d3 = none = 0
        $result = SalesCalculationService::calculateTotals(
            100000,
            [
                ['tipe' => 'percent', 'nilai' => 10],
                ['tipe' => 'percent', 'nilai' => 5],
                ['tipe' => 'none', 'nilai' => 0],
            ]
        );

        $this->assertEquals(10000, $result['diskon_nota_1_hasil']);
        $this->assertEquals(4500, $result['diskon_nota_2_hasil']);
        $this->assertEquals(0, $result['diskon_nota_3_hasil']);
        $this->assertEquals(14500, $result['total_diskon']);
        $this->assertEquals(85500, $result['total_setelah_diskon']);
        $this->assertEquals(85500, $result['grand_total'], 'Tanpa biaya & pajak grand total = setelah diskon');
    }
    #[Test]
    public function totals_diskon_sum_memakai_subtotal_asli_tiap_level()
    {
        $this->setBaselineTotalsSettings();
        SettingService::set('calculation.discount_mode', 'sum', 'string');

        // sum: d1 = 10% * 100000 = 10000 ; d2 = 5% * 100000 = 5000 ; total 15000
        $result = SalesCalculationService::calculateTotals(
            100000,
            [
                ['tipe' => 'percent', 'nilai' => 10],
                ['tipe' => 'percent', 'nilai' => 5],
                ['tipe' => 'none', 'nilai' => 0],
            ]
        );

        $this->assertEquals(10000, $result['diskon_nota_1_hasil']);
        $this->assertEquals(5000, $result['diskon_nota_2_hasil']);
        $this->assertEquals(15000, $result['total_diskon']);
        $this->assertEquals(85000, $result['total_setelah_diskon']);
    }
    #[Test]
    public function totals_biaya_kirim_dan_lain_ditambah_sebelum_pajak()
    {
        $this->setBaselineTotalsSettings();
        // setelah diskon 0 → 100000 ; biaya kirim 10000 nominal ; biaya lain 2% * 100000 = 2000
        $result = SalesCalculationService::calculateTotals(
            100000,
            [],
            ['tipe' => 'nominal', 'nilai' => 10000],
            ['tipe' => 'percent', 'nilai' => 2]
        );

        $this->assertEquals(10000, $result['biaya_kirim_hasil']);
        $this->assertEquals(2000, $result['biaya_lain_hasil']);
        $this->assertEquals(112000, $result['grand_total'], '100000 + 10000 + 2000, pajak 0%');
    }
    #[Test]
    public function totals_pajak_exclusive_default_ditambah_di_atas()
    {
        $this->setBaselineTotalsSettings();
        SettingService::set('tax.tax_sales_percent', 11, 'integer');

        // beforeTax = 100000 ; pajak 11% = 11000 ; grand = 111000
        $result = SalesCalculationService::calculateTotals(100000);

        $this->assertEquals(100000, $result['dpp']);
        $this->assertEquals(11, $result['pajak_persen']);
        $this->assertEquals(11000, $result['pajak_nominal']);
        $this->assertEquals(111000, $result['grand_total']);
    }
    #[Test]
    public function totals_pembulatan_dihitung_sebagai_selisih_grand_minus_before_rounding()
    {
        $this->setBaselineTotalsSettings();
        SettingService::set('tax.tax_sales_percent', 11, 'integer');
        SettingService::set('rounding.sales_method', 'round', 'string');
        SettingService::set('rounding.sales_precision', 100, 'integer');

        // subtotal 99999 ; pajak 11% = 10999.89 ; beforeRounding = 110998.89
        // round ke 100 terdekat → 111000 ; pembulatan = 111000 - 110998.89 = 1.11
        $result = SalesCalculationService::calculateTotals(99999);

        $this->assertEquals(10999.89, $result['pajak_nominal']);
        $this->assertEquals(111000, $result['grand_total']);
        $this->assertEquals(1.11, round($result['pembulatan'], 2));
    }
    #[Test]
    public function totals_diskon_nominal_di_cap_pada_base_running()
    {
        $this->setBaselineTotalsSettings();
        // recursive: d1 nominal 150000 di-cap ke 100000 (base) → running 0
        //            d2 percent 10% * 0 = 0
        $result = SalesCalculationService::calculateTotals(
            100000,
            [
                ['tipe' => 'nominal', 'nilai' => 150000],
                ['tipe' => 'percent', 'nilai' => 10],
            ]
        );

        $this->assertEquals(100000, $result['diskon_nota_1_hasil'], 'Nominal di-cap ke base');
        $this->assertEquals(0, $result['diskon_nota_2_hasil']);
        $this->assertEquals(100000, $result['total_diskon']);
        $this->assertEquals(0, $result['total_setelah_diskon']);
    }
    #[Test]
    public function totals_menjumlahkan_biaya_tambahan_pembayaran()
    {
        $this->setBaselineTotalsSettings();
        $result = SalesCalculationService::calculateTotals(
            100000,
            [],
            [],
            [],
            [
                ['biaya_tambahan' => 2500],
                ['biaya_tambahan' => 1500],
                ['nominal' => 50000], // tanpa biaya_tambahan → dianggap 0
            ]
        );

        $this->assertEquals(4000, $result['total_biaya_pembayaran']);
    }
    #[Test]
    public function totals_default_tipe_diskon_none_ketika_array_kosong()
    {
        $this->setBaselineTotalsSettings();
        $result = SalesCalculationService::calculateTotals(100000);

        $this->assertEquals('none', $result['diskon_nota_1_tipe']);
        $this->assertEquals('none', $result['diskon_nota_2_tipe']);
        $this->assertEquals('none', $result['diskon_nota_3_tipe']);
        $this->assertEquals(0, $result['total_diskon']);
        $this->assertEquals(100000, $result['subtotal']);
    }
}
