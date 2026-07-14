<?php

namespace Tests\Feature\Promo;

use App\Actions\Sales\CheckoutSalesAction;
use App\Models\DocPromo;
use App\Models\DocPromoDetail;
use App\Models\DocSales;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\PosTerminalShift;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Integration tests for promo auto-apply during checkout.
 *
 * Validates the anti-fraud pattern: backend always rebuilds diskon_1-4
 * from DB, never trusts frontend-supplied values.
 *
 * Setup mirrors CheckoutSalesActionTest — same terminal/shift/product/stock
 * scaffold, with promo helpers layered on top.
 */
class PromoCheckoutIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterPosTerminal $terminal;
    protected PosTerminalShift $shift;
    protected MasterCustomer $customer;
    protected MasterMetodePembayaran $cashPayment;
    protected MasterProduk $product;
    protected CheckoutSalesAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        // Predictable math: no tax, no rounding
        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');
        SettingService::set('promo.enabled', true, 'boolean');
        SettingService::set('calculation.discount_mode', 'recursive', 'string');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->customer = MasterCustomer::create([
            'ulid'           => (string) Str::ulid(),
            'kode_customer'  => 'CUST-P01',
            'nama'           => 'Promo Test Customer',
            'telepon'        => '08100000001',
            'jenis'          => 'spesifik',
            'status'         => 'active',
            'created_by'     => $this->user->id,
        ]);

        $this->cashPayment = MasterMetodePembayaran::create([
            'ulid'                  => (string) Str::ulid(),
            'kode_pembayaran'       => 'CASH',
            'nama_pembayaran'       => 'Tunai',
            'metode'                => 'tunai',
            'biaya_tambahan_tipe'   => 'none',
            'biaya_tambahan_nilai'  => 0,
            'status'                => 'active',
            'created_by'            => $this->user->id,
        ]);

        $this->terminal = MasterPosTerminal::create([
            'ulid'                          => (string) Str::ulid(),
            'kode_terminal'                 => 'TRM-P01',
            'nama_terminal'                 => 'Kasir Promo',
            'warehouse_id'                  => $this->warehouse->id,
            'default_customer_id'           => $this->customer->id,
            'default_metode_pembayaran_id'  => $this->cashPayment->id,
            'active_user_id'                => $this->user->id,
            'status'                        => 'active',
            'created_by'                    => $this->user->id,
        ]);

        $this->shift = PosTerminalShift::create([
            'ulid'        => (string) Str::ulid(),
            'terminal_id' => $this->terminal->id,
            'user_id'     => $this->user->id,
            'started_at'  => now(),
        ]);

        // Product: harga 10000, avg_cost 5000
        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Promo Product',
            'avg_cost'    => 5000,
            'harga_4'     => 10000,
            'unit_4'      => 'PCS',
            'konversi_4'  => 1,
            'status'      => 'active',
        ]);

        // Initial stock = 100
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 100, 'avg_cost' => 5000]
        );
        StockCard::$skipObserver = false;

        $this->action = new CheckoutSalesAction();
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function makeApprovedPromo(array $overrides = []): DocPromo
    {
        return DocPromo::create(array_merge([
            'kode_promo'    => 'PM-' . Str::random(6),
            'nama_promo'    => 'Test Promo',
            // Use subDay() so stored datetime '…T00:00:00' is clearly <= today's date string
            // (SQLite stores date cast as Y-m-d H:i:s; today() == today string comparison fails)
            'tanggal_mulai' => today()->subDay()->toDateString(),
            'status'        => 'approved',
            'approved_at'   => now(),
            'approved_by'   => $this->user->id,
            'created_by'    => $this->user->id,
        ], $overrides));
    }

    private function addDetail(DocPromo $promo, array $overrides = []): DocPromoDetail
    {
        return $promo->details()->create(array_merge([
            'target_type'    => 'semua',
            'min_qty'        => 1,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 10,
            'diskon_2_tipe'  => 'none',
            'diskon_2_nilai' => 0,
            'diskon_3_tipe'  => 'none',
            'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none',
            'diskon_4_nilai' => 0,
        ], $overrides));
    }

    private function buildItem(array $overrides = []): array
    {
        return array_merge([
            'product_id'     => $this->product->id,
            'unit'           => 'PCS',
            'konversi'       => 1,
            'qty'            => 1,
            'qty_base'       => 1,
            'harga_satuan'   => 10000,
            'diskon_1_tipe'  => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe'  => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe'  => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none', 'diskon_4_nilai' => 0,
            'diskon_5_tipe'  => 'none', 'diskon_5_nilai' => 0,
            'diskon_total'   => 0,
            'jumlah'         => 10000,
        ], $overrides);
    }

    private function baseCheckoutData(array $overrides = []): array
    {
        return array_merge([
            'terminal_id'  => $this->terminal->id,
            'shift_id'     => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id'  => $this->customer->id,
            'items'        => [$this->buildItem()],
            'payments'     => [
                ['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 10000],
            ],
        ], $overrides);
    }

    // ──────────────────────────────────────────────────────────────────
    // Tests
    // ──────────────────────────────────────────────────────────────────
    #[Test]
    public function checkout_applies_promo_percent_discount_to_item(): void
    {
        // Promo: 10% off all items → 10% × 10000 = 1000
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Diskon 10%']);
        $this->addDetail($promo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);

        // Frontend sends no discount (accurate preview or zero)
        $sales = $this->action->execute($this->baseCheckoutData([
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 9000]],
        ]));

        $detail = $sales->details->first();

        $this->assertEquals(1000, $detail->diskon_1_hasil, 'Backend should apply 10% = 1000');
        $this->assertEquals(1000, $detail->diskon_total);
        $this->assertEquals(9000, $detail->jumlah);
        $this->assertEquals(9000, $sales->subtotal);
        $this->assertEquals(9000, $sales->grand_total);
    }
    #[Test]
    public function checkout_overrides_frontend_diskon_1_with_promo_from_db(): void
    {
        // Promo in DB: 10% off semua
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Legit 10%']);
        $this->addDetail($promo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);

        // Frontend sends FAKE 80% discount on slot-1 (anti-fraud attempt)
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem([
                'diskon_1_tipe'  => 'percent',
                'diskon_1_nilai' => 80,   // FAKE — should be overridden
                'diskon_total'   => 8000,
                'jumlah'         => 2000,
            ])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 9000]],
        ]));

        $detail = $sales->details->first();

        // Backend must override slot-1 with the real promo value (10%, not 80%)
        $this->assertEquals('percent', $detail->diskon_1_tipe);
        $this->assertEquals(10, $detail->diskon_1_nilai);
        $this->assertEquals(1000, $detail->diskon_1_hasil, 'Backend should cap to real promo 10% = 1000, ignoring fake 80%');
        $this->assertEquals(9000, $detail->jumlah);
        $this->assertEquals(9000, $sales->grand_total);
    }
    #[Test]
    public function checkout_records_promo_id_in_sales_detail(): void
    {
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Promo ID Test']);
        $this->addDetail($promo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);

        $sales = $this->action->execute($this->baseCheckoutData([
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 9000]],
        ]));

        $detail = $sales->details->first();

        $this->assertNotNull($detail->promo_id, 'promo_id must be saved in sales detail');
        $this->assertEquals($promo->id, $detail->promo_id);
    }
    #[Test]
    public function checkout_ignores_inactive_promo(): void
    {
        // Promo with status=inactive (not approved) — must be ignored
        $inactivePromo = DocPromo::create([
            'kode_promo'    => 'PM-INACT',
            'nama_promo'    => 'Inactive Promo',
            'tanggal_mulai' => today()->toDateString(),
            'status'        => 'inactive',
            'created_by'    => $this->user->id,
        ]);
        $inactivePromo->details()->create([
            'target_type'    => 'semua',
            'min_qty'        => 1,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 50,   // huge — would be obvious if applied
            'diskon_2_tipe'  => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe'  => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe'  => 'none', 'diskon_4_nilai' => 0,
        ]);

        $sales = $this->action->execute($this->baseCheckoutData());

        $detail = $sales->details->first();
        $this->assertEquals(0, $detail->diskon_1_hasil, 'Inactive promo must not be applied');
        $this->assertNull($detail->promo_id);
        $this->assertEquals(10000, $sales->grand_total);
    }
    #[Test]
    public function checkout_ignores_future_promo(): void
    {
        // Promo starts tomorrow — upcoming, not yet effective
        $futurePromo = $this->makeApprovedPromo([
            'nama_promo'    => 'Future Promo',
            'tanggal_mulai' => today()->addDay()->toDateString(),
        ]);
        $this->addDetail($futurePromo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 50]);

        $sales = $this->action->execute($this->baseCheckoutData());

        $detail = $sales->details->first();
        $this->assertEquals(0, $detail->diskon_1_hasil, 'Future promo must not be applied');
        $this->assertNull($detail->promo_id);
        $this->assertEquals(10000, $sales->grand_total);
    }
    #[Test]
    public function checkout_ignores_expired_promo(): void
    {
        // Promo ended yesterday
        $expiredPromo = $this->makeApprovedPromo([
            'nama_promo'      => 'Expired Promo',
            'tanggal_mulai'   => today()->subDays(5)->toDateString(),
            'tanggal_selesai' => today()->subDay()->toDateString(),
        ]);
        $this->addDetail($expiredPromo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 50]);

        $sales = $this->action->execute($this->baseCheckoutData());

        $detail = $sales->details->first();
        $this->assertEquals(0, $detail->diskon_1_hasil, 'Expired promo must not be applied');
        $this->assertNull($detail->promo_id);
        $this->assertEquals(10000, $sales->grand_total);
    }
    #[Test]
    public function checkout_does_not_apply_promo_when_min_qty_not_met(): void
    {
        // Promo requires min_qty=3, but cart has qty=1
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Min Qty 3']);
        $this->addDetail($promo, [
            'min_qty'        => 3,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 20,
        ]);

        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 1, 'qty_base' => 1])],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(0, $detail->diskon_1_hasil, 'Promo should not apply when qty < min_qty');
        $this->assertNull($detail->promo_id);
        $this->assertEquals(10000, $sales->grand_total);
    }
    #[Test]
    public function checkout_applies_promo_when_min_qty_is_met(): void
    {
        // Promo requires min_qty=3, cart has qty=3 → should apply
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Min Qty 3 Met']);
        $this->addDetail($promo, [
            'min_qty'        => 3,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 10,
        ]);

        // qty=3, harga=10000 → bruto=30000, 10% = 3000
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 3, 'qty_base' => 3, 'jumlah' => 27000])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 27000]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(3000, $detail->diskon_1_hasil, '10% of 30000 = 3000');
        $this->assertEquals($promo->id, $detail->promo_id);
        $this->assertEquals(27000, $detail->jumlah);
        $this->assertEquals(27000, $sales->grand_total);
    }
    #[Test]
    public function checkout_no_promo_in_db_leaves_diskon_slots_at_none(): void
    {
        // No promos in DB at all
        $sales = $this->action->execute($this->baseCheckoutData());

        $detail = $sales->details->first();
        $this->assertEquals('none', $detail->diskon_1_tipe);
        $this->assertEquals(0, $detail->diskon_1_hasil);
        $this->assertNull($detail->promo_id);
        $this->assertEquals(10000, $sales->grand_total);
    }
    #[Test]
    public function checkout_applies_best_promo_when_multiple_active(): void
    {
        // Promo A: 5%  = 500
        $promoA = $this->makeApprovedPromo(['nama_promo' => 'Promo A 5%']);
        $this->addDetail($promoA, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 5]);

        // Promo B: 15% = 1500 → should win
        $promoB = $this->makeApprovedPromo(['nama_promo' => 'Promo B 15%']);
        $this->addDetail($promoB, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 15]);

        $sales = $this->action->execute($this->baseCheckoutData([
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 8500]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(1500, $detail->diskon_1_hasil, 'Best promo (15% = 1500) should be chosen');
        $this->assertEquals($promoB->id, $detail->promo_id);
        $this->assertEquals(8500, $sales->grand_total);
    }

    // ──────────────────────────────────────────────────────────────────
    // EDGE CASES TAMBAHAN (galak): override flag, target grup/kategori,
    // cap nominal, multi-slot, setting disable, targeting customer
    // ──────────────────────────────────────────────────────────────────

    /**
     * override_promo=true (kasir "Hapus Semua Diskon Item") WAJIB dihormati:
     * slot promo TIDAK di-auto-derive, FE slots dipertahankan apa adanya,
     * dan promo_id di-clear (laporan tidak salah atribusi).
     */
    #[Test]
    public function checkout_menghormati_flag_override_promo_dan_clear_promo_id(): void
    {
        // Ada promo aktif 10% yang NORMALNYA akan terpasang
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Diskon 10%']);
        $this->addDetail($promo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);

        // Kasir set override_promo → semua slot promo (1-4) tetap none (dari buildItem)
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['override_promo' => true])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 10000]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(0, $detail->diskon_1_hasil, 'Promo tidak boleh terpasang saat override_promo=true');
        $this->assertNull($detail->promo_id, 'promo_id harus di-clear saat override');
        $this->assertEquals(10000, $sales->grand_total);
    }

    /**
     * Promo target GRUP: produk yang grup_id-nya cocok harus dapat diskon.
     */
    #[Test]
    public function checkout_menerapkan_promo_target_grup_yang_cocok(): void
    {
        $tipeProduk = \App\Models\MasterTipe::create([
            'ulid'       => (string) Str::ulid(),
            'kode_tipe'  => 'TIPEP',
            'nama_tipe'  => 'Tipe Produk',
            'status'     => 'active',
            'created_by' => $this->user->id,
        ]);
        $kategoriProduk = \App\Models\MasterKategori::create([
            'ulid'          => (string) Str::ulid(),
            'tipe_id'       => $tipeProduk->id,
            'kode_kategori' => 'KTGP',
            'nama_kategori' => 'Kategori Produk',
            'status'        => 'active',
            'created_by'    => $this->user->id,
        ]);
        $grup = \App\Models\MasterGrup::create([
            'ulid'        => (string) Str::ulid(),
            'kategori_id' => $kategoriProduk->id,
            'kode_grup'   => 'GRPA',
            'nama_grup'   => 'Grup A',
            'status'      => 'active',
            'created_by'  => $this->user->id,
        ]);

        $produkGrup = MasterProduk::factory()->create([
            'nama_produk' => 'Produk Grup',
            'avg_cost'    => 5000,
            'harga_4'     => 10000,
            'unit_4'      => 'PCS',
            'konversi_4'  => 1,
            'grup_id'     => $grup->id,
            'status'      => 'active',
        ]);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $produkGrup->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 100, 'avg_cost' => 5000]
        );
        StockCard::$skipObserver = false;

        // Promo target grup → 20%
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Grup 20%']);
        $this->addDetail($promo, [
            'target_type'    => 'grup',
            'target_id'      => $grup->id,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 20,
        ]);

        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['product_id' => $produkGrup->id])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 8000]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(2000, $detail->diskon_1_hasil, '20% dari 10000 = 2000');
        $this->assertEquals($promo->id, $detail->promo_id);
        $this->assertEquals(8000, $sales->grand_total);
    }

    /**
     * Promo target GRUP berbeda: produk dengan grup lain TIDAK dapat diskon.
     */
    #[Test]
    public function checkout_tidak_menerapkan_promo_target_grup_yang_tidak_cocok(): void
    {
        // product default punya grup_id = null → promo target grup id apapun tidak match
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Grup Lain']);
        $this->addDetail($promo, [
            'target_type'    => 'grup',
            'target_id'      => 999,
            'diskon_1_tipe'  => 'percent',
            'diskon_1_nilai' => 50,
        ]);

        $sales = $this->action->execute($this->baseCheckoutData());

        $detail = $sales->details->first();
        $this->assertEquals(0, $detail->diskon_1_hasil, 'Produk grup tidak cocok tidak boleh dapat diskon');
        $this->assertNull($detail->promo_id);
        $this->assertEquals(10000, $sales->grand_total);
    }

    /**
     * Promo nominal yang LEBIH BESAR dari jumlah baris harus di-cap ke jumlah baris
     * (calculateDiscountLevel nominal => min(nilai, base)); jumlah tidak boleh negatif.
     */
    #[Test]
    public function checkout_cap_diskon_nominal_ke_jumlah_baris(): void
    {
        // Promo nominal 25000 sedangkan jumlah baris cuma 10000 → di-cap 10000
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Nominal Besar']);
        $this->addDetail($promo, ['diskon_1_tipe' => 'nominal', 'diskon_1_nilai' => 25000]);

        $sales = $this->action->execute($this->baseCheckoutData([
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 0]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(10000, $detail->diskon_1_hasil, 'Nominal 25000 di-cap ke jumlah baris 10000');
        $this->assertEquals(0, $detail->jumlah, 'Jumlah baris tidak boleh negatif');
        $this->assertEquals($promo->id, $detail->promo_id);
        $this->assertEquals(0, $sales->grand_total);
    }

    /**
     * Promo multi-slot recursive (10% lalu 10%) di-rebuild backend dengan nilai eksak.
     * bruto 10000 → slot1 1000 (sisa 9000) → slot2 900 → total 1900 → jumlah 8100.
     */
    #[Test]
    public function checkout_menerapkan_promo_dua_slot_recursive_nilai_eksak(): void
    {
        $promo = $this->makeApprovedPromo(['nama_promo' => 'Bertingkat 10+10']);
        $this->addDetail($promo, [
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10,
            'diskon_2_tipe' => 'percent', 'diskon_2_nilai' => 10,
            'diskon_3_tipe' => 'none',    'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none',    'diskon_4_nilai' => 0,
        ]);

        $sales = $this->action->execute($this->baseCheckoutData([
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 8100]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(1000, $detail->diskon_1_hasil, 'slot1: 10% x 10000');
        $this->assertEquals(900, $detail->diskon_2_hasil, 'slot2 recursive: 10% x 9000');
        $this->assertEquals(1900, $detail->diskon_total);
        $this->assertEquals(8100, $detail->jumlah);
        $this->assertEquals($promo->id, $detail->promo_id);
        $this->assertEquals(8100, $sales->grand_total);
    }

    /**
     * Saat setting promo.enabled = false, auto-apply promo dimatikan total:
     * getActivePromos mengembalikan kosong → tidak ada diskon.
     */
    #[Test]
    public function checkout_tidak_menerapkan_promo_saat_setting_dimatikan(): void
    {
        SettingService::set('promo.enabled', false, 'boolean');

        $promo = $this->makeApprovedPromo(['nama_promo' => 'Harusnya Diabaikan']);
        $this->addDetail($promo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 30]);

        $sales = $this->action->execute($this->baseCheckoutData());

        $detail = $sales->details->first();
        $this->assertEquals(0, $detail->diskon_1_hasil, 'promo.enabled=false harus matikan auto-apply');
        $this->assertNull($detail->promo_id);
        $this->assertEquals(10000, $sales->grand_total);
    }

    /**
     * Promo bertarget customer_category tertentu HANYA berlaku untuk customer
     * yang kategori-nya cocok — di-resolve backend dari customer di DB, bukan FE.
     */
    #[Test]
    public function checkout_menerapkan_promo_customer_category_yang_cocok(): void
    {
        $gold = \App\Models\MasterKategoriCustomer::create([
            'ulid'          => (string) Str::ulid(),
            'kode_kategori' => 'GOLDC',
            'nama_kategori' => 'Gold',
            'status'        => 'active',
        ]);
        $silver = \App\Models\MasterKategoriCustomer::create([
            'ulid'          => (string) Str::ulid(),
            'kode_kategori' => 'SILVERC',
            'nama_kategori' => 'Silver',
            'status'        => 'active',
        ]);

        // Customer ini Gold
        $goldCustomer = MasterCustomer::create([
            'ulid'                 => (string) Str::ulid(),
            'kode_customer'        => 'CUST-GOLD',
            'nama'                 => 'Gold Customer',
            'telepon'              => '08100000099',
            'jenis'                => 'spesifik',
            'kategori_customer_id' => $gold->id,
            'status'               => 'active',
            'created_by'           => $this->user->id,
        ]);

        // Promo khusus Silver (tidak cocok dengan Gold customer)
        $silverPromo = $this->makeApprovedPromo(['nama_promo' => 'Silver 40%', 'customer_category_id' => $silver->id]);
        $this->addDetail($silverPromo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 40]);

        // Promo khusus Gold (cocok)
        $goldPromo = $this->makeApprovedPromo(['nama_promo' => 'Gold 10%', 'customer_category_id' => $gold->id]);
        $this->addDetail($goldPromo, ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);

        $sales = $this->action->execute($this->baseCheckoutData([
            'customer_id' => $goldCustomer->id,
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 9000]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(1000, $detail->diskon_1_hasil, 'Hanya promo Gold (10%) berlaku, bukan Silver 40%');
        $this->assertEquals($goldPromo->id, $detail->promo_id);
        $this->assertEquals(9000, $sales->grand_total);
    }
}
