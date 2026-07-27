<?php

namespace Tests\Feature\Sales;

use App\Models\DocPromo;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;
use App\Models\User;
use App\Services\ManualSalesCalculationService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Manual sales calculate + rebuild_promos (opsi A: Disc 1–4 dari promo, Disc 5 manual).
 */
class ManualSalesCalculatePromoTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MasterCustomer $customer;

    private MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('promo.enabled', true, 'boolean');
        SettingService::set('promo.allow_manual_discount', true, 'boolean');
        SettingService::set('calculation.discount_mode', 'recursive', 'string');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'BO-PROMO',
            'nama' => 'Customer Promo BO',
            'telepon' => '0800',
            'tempo_default' => 30,
            'jenis' => 'spesifik',
            'status' => 'active',
        ]);

        $this->product = MasterProduk::factory()->create([
            'status' => 'active',
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'harga_4' => 10000,
        ]);
    }

    #[Test]
    public function rebuild_overwrites_disc_1_to_4_keeps_disc_5_and_sets_nama_promo(): void
    {
        $promo = DocPromo::create([
            'kode_promo' => 'PM-BO-1',
            'nama_promo' => 'Promo BO 10%',
            'tanggal_mulai' => today()->subDay()->toDateString(),
            'status' => 'approved',
            'channel' => 'penjualan',
            'approved_at' => now(),
            'approved_by' => $this->user->id,
            'created_by' => $this->user->id,
        ]);
        $promo->details()->create([
            'target_type' => 'semua',
            'min_qty' => 1,
            'diskon_1_tipe' => 'percent',
            'diskon_1_nilai' => 10,
            'diskon_2_tipe' => 'none',
            'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none',
            'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',
            'diskon_4_nilai' => 0,
        ]);

        $result = ManualSalesCalculationService::calculate([
            'customer_id' => $this->customer->id,
            'details' => [[
                'product_id' => $this->product->id,
                'unit' => 'PCS',
                'konversi' => 1,
                'qty' => 1,
                'harga_satuan' => 10000,
                // Fake client values — harus ditimpa saat rebuild
                'diskon_1_tipe' => 'percent',
                'diskon_1_nilai' => 99,
                'diskon_2_tipe' => 'nominal',
                'diskon_2_nilai' => 5000,
                'diskon_3_tipe' => 'none',
                'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none',
                'diskon_4_nilai' => 0,
                // Disc 5 manual — harus tetap
                'diskon_5_tipe' => 'percent',
                'diskon_5_nilai' => 5,
            ]],
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
            ],
            'biaya_kirim' => ['tipe' => 'none', 'nilai' => 0],
            'biaya_lain' => ['tipe' => 'none', 'nilai' => 0],
        ], true);

        $row = $result['details'][0];
        $this->assertSame('percent', $row['diskon_1_tipe']);
        $this->assertEquals(10, $row['diskon_1_nilai']);
        $this->assertSame('none', $row['diskon_2_tipe']);
        $this->assertEquals(0, $row['diskon_2_nilai']);
        $this->assertSame('Promo BO 10%', $row['nama_promo']);
        $this->assertSame($promo->id, $row['promo_id']);

        // Disc 5 preserved: 10% then 5% recursive on 10000 → 1000 + 450 = 1450
        $this->assertSame('percent', $row['diskon_5_tipe']);
        $this->assertEquals(5, $row['diskon_5_nilai']);
        $this->assertEquals(1450, $row['diskon_total']);
        $this->assertEquals(8550, $row['jumlah']);
    }

    #[Test]
    public function without_rebuild_keeps_client_disc_1_to_4(): void
    {
        $result = ManualSalesCalculationService::calculate([
            'customer_id' => $this->customer->id,
            'details' => [[
                'product_id' => $this->product->id,
                'unit' => 'PCS',
                'konversi' => 1,
                'qty' => 1,
                'harga_satuan' => 10000,
                'diskon_1_tipe' => 'percent',
                'diskon_1_nilai' => 20,
                'diskon_2_tipe' => 'none',
                'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none',
                'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none',
                'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none',
                'diskon_5_nilai' => 0,
            ]],
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
            ],
            'biaya_kirim' => ['tipe' => 'none', 'nilai' => 0],
            'biaya_lain' => ['tipe' => 'none', 'nilai' => 0],
        ], false);

        $row = $result['details'][0];
        $this->assertEquals(20, $row['diskon_1_nilai']);
        $this->assertArrayNotHasKey('nama_promo', $row);
        $this->assertEquals(2000, $row['diskon_total']);
    }

    #[Test]
    public function rebuild_clears_promo_slots_when_no_matching_promo(): void
    {
        $result = ManualSalesCalculationService::calculate([
            'customer_id' => $this->customer->id,
            'details' => [[
                'product_id' => $this->product->id,
                'unit' => 'PCS',
                'konversi' => 1,
                'qty' => 1,
                'harga_satuan' => 10000,
                'diskon_1_tipe' => 'percent',
                'diskon_1_nilai' => 50,
                'diskon_2_tipe' => 'none',
                'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none',
                'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none',
                'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'nominal',
                'diskon_5_nilai' => 500,
            ]],
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
            ],
            'biaya_kirim' => ['tipe' => 'none', 'nilai' => 0],
            'biaya_lain' => ['tipe' => 'none', 'nilai' => 0],
        ], true);

        $row = $result['details'][0];
        $this->assertSame('none', $row['diskon_1_tipe']);
        $this->assertEquals(0, $row['diskon_1_nilai']);
        $this->assertNull($row['promo_id']);
        $this->assertNull($row['nama_promo']);
        $this->assertSame('nominal', $row['diskon_5_tipe']);
        $this->assertEquals(500, $row['diskon_5_nilai']);
        $this->assertEquals(500, $row['diskon_total']);
    }
}
