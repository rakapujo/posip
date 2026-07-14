<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CheckoutSalesAction;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CheckoutSalesActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterPosTerminal $terminal;
    protected PosTerminalShift $shift;
    protected MasterCustomer $customer;
    protected MasterMetodePembayaran $cashPayment;
    protected MasterMetodePembayaran $transferPayment;
    protected MasterProduk $product;
    protected CheckoutSalesAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable tax & rounding for predictable math
        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        // Create user and act as them
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Create warehouse
        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        // Create customer (walk-in style)
        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-001',
            'nama' => 'Walk-in Customer',
            'telepon' => '08123456789',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        // Create payment methods
        $this->cashPayment = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->transferPayment = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'TRF',
            'nama_pembayaran' => 'Transfer Bank',
            'metode' => 'non_tunai',
            'jenis' => 'bank',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        // Create POS terminal
        $this->terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-001',
            'nama_terminal' => 'Kasir 1',
            'warehouse_id' => $this->warehouse->id,
            'default_customer_id' => $this->customer->id,
            'default_metode_pembayaran_id' => $this->cashPayment->id,
            'active_user_id' => $this->user->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        // Create active shift
        $this->shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'started_at' => now(),
        ]);

        // Create product with avg_cost
        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Test Product',
            'avg_cost' => 5000,
            'harga_4' => 10000, // PCS price
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        // Initial stock = 100 (use updateOrCreate because MasterProdukObserver
        // auto-initializes inventory_stock at qty=0 when product is created).
        // Rekam stock_card PURCHASE padanan agar invarian data:verify bermakna
        // (SUM stock_card == inventory_stock.qty), seperti pola SerialSalesCheckoutTest.
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 100, 'avg_cost' => 5000]
        );
        StockCard::record([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 100, 'qty_out' => 0, 'cost_per_unit' => 5000,
        ]);
        StockCard::$skipObserver = false;

        $this->action = new CheckoutSalesAction();
    }

    private function buildItem(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'unit' => 'PCS',
            'konversi' => 1,
            'qty' => 1,
            'qty_base' => 1,
            'harga_satuan' => 10000,
            'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
            'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
            'diskon_total' => 0,
            'jumlah' => 10000,
        ], $overrides);
    }

    private function baseCheckoutData(array $overrides = []): array
    {
        return array_merge([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'items' => [$this->buildItem()],
            'payments' => [
                ['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 10000],
            ],
        ], $overrides);
    }
    #[Test]
    public function checkout_rejects_inactive_customer_via_defense_in_depth(): void
    {
        $this->customer->update(['status' => 'inactive']);

        $this->expectException(ValidationException::class);
        $this->action->execute($this->baseCheckoutData());
    }
    #[Test]
    public function checkout_rejects_inactive_warehouse_via_defense_in_depth(): void
    {
        $this->warehouse->update(['status' => 'inactive']);

        $this->expectException(ValidationException::class);
        $this->action->execute($this->baseCheckoutData());
    }
    #[Test]
    public function checkout_rejects_inactive_payment_method_via_defense_in_depth(): void
    {
        $this->cashPayment->update(['status' => 'inactive']);

        $this->expectException(ValidationException::class);
        $this->action->execute($this->baseCheckoutData());
    }
    #[Test]
    public function checkout_rejects_inactive_product_via_defense_in_depth(): void
    {
        $this->product->update(['status' => 'inactive']);

        $this->expectException(ValidationException::class);
        $this->action->execute($this->baseCheckoutData());
    }
    #[Test]
    public function checkout_happy_path_creates_sales_with_correct_total()
    {
        $sales = $this->action->execute($this->baseCheckoutData());

        $this->assertInstanceOf(DocSales::class, $sales);
        $this->assertEquals('completed', $sales->status);
        $this->assertEquals(10000, $sales->subtotal);
        $this->assertEquals(10000, $sales->grand_total);
        $this->assertEquals(10000, $sales->total_bayar);
        $this->assertEquals(0, $sales->kembalian);
        $this->assertEquals($this->user->id, $sales->created_by);
        $this->assertNotEmpty($sales->nomor_dokumen);
        $this->assertEquals(1, $sales->details->count());
        $this->assertEquals(1, $sales->payments->count());
    }
    #[Test]
    public function checkout_reduces_stock_correctly()
    {
        $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 5, 'qty_base' => 5, 'jumlah' => 50000])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 50000]],
        ]));

        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();

        $this->assertEquals(95, $stock->qty, 'Stock should reduce from 100 to 95');
    }
    #[Test]
    public function checkout_creates_stock_card_entry_with_sales_type()
    {
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 3, 'qty_base' => 3, 'jumlah' => 30000])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 30000]],
        ]));

        $stockCard = StockCard::where('transaction_id', $sales->id)
            ->where('transaction_type', 'SALES')
            ->first();

        $this->assertNotNull($stockCard, 'Stock card SALES entry should exist');
        $this->assertEquals(0, $stockCard->qty_in);
        $this->assertEquals(3, $stockCard->qty_out);
        $this->assertEquals(5000, $stockCard->avg_cost_before, 'HPP before sales = original avg_cost');
        $this->assertEquals(5000, $stockCard->avg_cost_after, 'SALES does not change HPP');
        $this->assertEquals($sales->nomor_dokumen, $stockCard->transaction_no);
    }
    #[Test]
    public function checkout_throws_validation_when_stock_insufficient()
    {
        $this->expectException(ValidationException::class);

        $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 200, 'qty_base' => 200, 'jumlah' => 2000000])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 2000000]],
        ]));
    }
    #[Test]
    public function checkout_does_not_persist_when_stock_insufficient()
    {
        try {
            $this->action->execute($this->baseCheckoutData([
                'items' => [$this->buildItem(['qty' => 200, 'qty_base' => 200, 'jumlah' => 2000000])],
                'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 2000000]],
            ]));
            $this->fail('Expected ValidationException was not thrown');
        } catch (ValidationException $e) {
            // expected
        }

        $this->assertEquals(0, DocSales::count(), 'No sales should be saved');
        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(100, $stock->qty, 'Stock should remain unchanged');
    }
    #[Test]
    public function checkout_with_multi_payment_records_all_payments()
    {
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 2, 'qty_base' => 2, 'jumlah' => 20000])],
            'payments' => [
                ['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 8000],
                ['metode_pembayaran_id' => $this->transferPayment->id, 'nominal' => 12000],
            ],
        ]));

        $this->assertEquals(2, $sales->payments->count());
        $this->assertEquals(20000, $sales->grand_total);
        $this->assertEquals(20000, $sales->total_bayar);
        $this->assertEquals(0, $sales->kembalian);
    }
    #[Test]
    public function checkout_calculates_change_when_overpaid()
    {
        $sales = $this->action->execute($this->baseCheckoutData([
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 50000]],
        ]));

        $this->assertEquals(10000, $sales->grand_total);
        $this->assertEquals(50000, $sales->total_bayar);
        $this->assertEquals(40000, $sales->kembalian);
    }
    #[Test]
    public function checkout_with_line_discount_percent()
    {
        // diskon_1-4 are always overridden by promo anti-fraud logic (set to none when no promo).
        // diskon_5 is the manual-kasir slot and is still trusted from the frontend.
        // 1 item: harga 10000, diskon_5 10% = 1000, jumlah = 9000
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem([
                'diskon_5_tipe' => 'percent',
                'diskon_5_nilai' => 10,
                'jumlah' => 9000,
            ])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 9000]],
        ]));

        $this->assertEquals(9000, $sales->subtotal);
        $this->assertEquals(9000, $sales->grand_total);

        $detail = $sales->details->first();
        $this->assertEquals(1000, $detail->diskon_5_hasil);
        $this->assertEquals(1000, $detail->diskon_total);
        $this->assertEquals(9000, $detail->jumlah);
    }
    #[Test]
    public function checkout_with_nota_discount_percent()
    {
        // Subtotal 10000, manual diskon nota (level 3) 10% = 1000, grand total = 9000
        // Level 1+2 reserved for auto customer discount (none for this test customer)
        $sales = $this->action->execute($this->baseCheckoutData([
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'percent', 'nilai' => 10],
            ],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 9000]],
        ]));

        $this->assertEquals(10000, $sales->subtotal);
        $this->assertEquals(1000, $sales->diskon_nota_3_hasil);
        $this->assertEquals(1000, $sales->total_diskon);
        $this->assertEquals(9000, $sales->total_setelah_diskon);
        $this->assertEquals(9000, $sales->grand_total);
    }
    #[Test]
    public function checkout_generates_unique_nomor_dokumen_with_inv_prefix()
    {
        $sales1 = $this->action->execute($this->baseCheckoutData());
        $sales2 = $this->action->execute($this->baseCheckoutData([
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 10000]],
        ]));

        $this->assertNotEquals($sales1->nomor_dokumen, $sales2->nomor_dokumen);
        $this->assertStringStartsWith('INV-', $sales1->nomor_dokumen);
        $this->assertStringStartsWith('INV-', $sales2->nomor_dokumen);
    }
    #[Test]
    public function checkout_auto_applies_customer_tipe_and_kategori_discount()
    {
        // Create tipe + kategori with discounts
        $tipe = \App\Models\MasterTipeCustomer::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'kode_tipe' => 'RESELLER',
            'nama_tipe' => 'Reseller',
            'diskon_tipe' => 'percent',
            'diskon_nilai' => 2,
            'status' => 'active',
        ]);

        $kategori = \App\Models\MasterKategoriCustomer::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'kode_kategori' => 'GOLD',
            'nama_kategori' => 'Gold Member',
            'diskon_tipe' => 'percent',
            'diskon_nilai' => 5,
            'status' => 'active',
        ]);

        // Update customer with tipe + kategori
        $this->customer->update([
            'tipe_customer_id' => $tipe->id,
            'kategori_customer_id' => $kategori->id,
        ]);

        // Checkout — frontend sends NO discounts, backend should auto-apply
        $sales = $this->action->execute($this->baseCheckoutData());

        // L1 = RESELLER 2%: 2% × 10000 = 200
        $this->assertEquals('percent', $sales->diskon_nota_1_tipe);
        $this->assertEquals(2, $sales->diskon_nota_1_nilai);
        $this->assertEquals(200, $sales->diskon_nota_1_hasil);

        // L2 = GOLD 5%: 5% × 9800 (recursive) = 490
        $this->assertEquals('percent', $sales->diskon_nota_2_tipe);
        $this->assertEquals(5, $sales->diskon_nota_2_nilai);
        $this->assertEquals(490, $sales->diskon_nota_2_hasil);

        // Grand total = 10000 - 200 - 490 = 9310
        $this->assertEquals(9310, $sales->grand_total);
    }
    #[Test]
    public function checkout_overrides_frontend_discount_level1_and_2_from_db()
    {
        // Customer has NO tipe/kategori discount
        // Frontend tries to inject fake 50% discount at level 1 — should be ignored
        $sales = $this->action->execute($this->baseCheckoutData([
            'discounts' => [
                ['tipe' => 'percent', 'nilai' => 50],  // FAKE — should be overridden to none
                ['tipe' => 'percent', 'nilai' => 30],  // FAKE — should be overridden to none
                ['tipe' => 'none', 'nilai' => 0],
            ],
        ]));

        // Backend overrides L1+L2 from DB (customer has no discount → none)
        $this->assertEquals(0, $sales->diskon_nota_1_hasil, 'L1 should be 0 — customer has no tipe discount');
        $this->assertEquals(0, $sales->diskon_nota_2_hasil, 'L2 should be 0 — customer has no kategori discount');
        $this->assertEquals(10000, $sales->grand_total, 'Grand total unaffected by fake discounts');
    }
    #[Test]
    public function checkout_respects_nota_discount_override_flag()
    {
        // Customer has tipe 2% + kategori 5% discounts
        $tipe = \App\Models\MasterTipeCustomer::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'kode_tipe' => 'RESELLER',
            'nama_tipe' => 'Reseller',
            'diskon_tipe' => 'percent',
            'diskon_nilai' => 2,
            'status' => 'active',
        ]);
        $kategori = \App\Models\MasterKategoriCustomer::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'kode_kategori' => 'GOLD',
            'nama_kategori' => 'Gold',
            'diskon_tipe' => 'percent',
            'diskon_nilai' => 5,
            'status' => 'active',
        ]);
        $this->customer->update([
            'tipe_customer_id' => $tipe->id,
            'kategori_customer_id' => $kategori->id,
        ]);

        // Kasir EXPLICITLY overrides slot 1 (hapus disc nota 1) — backend should honor
        $sales = $this->action->execute($this->baseCheckoutData([
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],   // Frontend sent none
                ['tipe' => 'percent', 'nilai' => 5],
                ['tipe' => 'none', 'nilai' => 0],
            ],
            'nota_discount_overrides' => [true, false, false], // kasir clicked hapus on slot 1
        ]));

        // Slot 1: override → respected → 0 (NOT forced 2%)
        $this->assertEquals(0, $sales->diskon_nota_1_hasil, 'Slot 1 respected override → 0');
        // Slot 2: not overridden → auto-derive from kategori → 5% × 10000 = 500
        $this->assertEquals(500, $sales->diskon_nota_2_hasil, 'Slot 2 auto-derived from kategori');
        // Grand total = 10000 - 0 - 500 = 9500
        $this->assertEquals(9500, $sales->grand_total);
    }
    #[Test]
    public function checkout_respects_line_promo_override_flag()
    {
        // Seed an active promo targeting the product
        $promo = \App\Models\DocPromo::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'kode_promo' => 'TEST-OVR',
            'nama_promo' => 'Test Override',
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'tanggal_selesai' => now()->addDay()->toDateString(),
            'status' => 'approved',
            'created_by' => $this->user->id,
        ]);
        \DB::table('doc_promo_details')->insert([
            'promo_id' => $promo->id,
            'target_type' => 'produk',
            'target_id' => $this->product->id,
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

        // Kasir EXPLICITLY clears line discount — sends override_promo=true + all slots 'none'
        $data = $this->baseCheckoutData();
        $data['items'][0]['override_promo'] = true;
        for ($i = 1; $i <= 5; $i++) {
            $data['items'][0]["diskon_{$i}_tipe"] = 'none';
            $data['items'][0]["diskon_{$i}_nilai"] = 0;
        }
        $sales = $this->action->execute($data);

        // Promo should NOT be applied — slot 1 stays at 'none'
        $detail = $sales->details->first();
        $this->assertEquals('none', $detail->diskon_1_tipe, 'Override respected — no auto-promo');
        $this->assertEquals(0, $detail->diskon_1_hasil);
        $this->assertNull($detail->promo_id, 'promo_id cleared for overridden line');
    }
    #[Test]
    public function checkout_rejects_manual_discount_when_promo_disabled()
    {
        SettingService::set('promo.enabled', false, 'boolean');

        $sales = $this->action->execute($this->baseCheckoutData([
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'percent', 'nilai' => 10],  // manual — should be rejected
            ],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 10000]],
        ]));

        $this->assertEquals(0, $sales->diskon_nota_3_hasil, 'Manual discount rejected when promo disabled');
        $this->assertEquals(10000, $sales->grand_total);

        // Re-enable for other tests
        SettingService::set('promo.enabled', true, 'boolean');
    }
    #[Test]
    public function checkout_caps_manual_discount_to_max_setting()
    {
        SettingService::set('promo.max_manual_discount_percent', 5, 'decimal');

        // Kasir tries 20% manual discount — should be capped to 5%
        $sales = $this->action->execute($this->baseCheckoutData([
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'percent', 'nilai' => 20],  // should be capped to 5
            ],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 9500]],
        ]));

        // 5% of 10000 = 500
        $this->assertEquals(500, $sales->diskon_nota_3_hasil, 'Manual discount capped to 5%');
        $this->assertEquals(9500, $sales->grand_total);

        // Reset
        SettingService::set('promo.max_manual_discount_percent', 100, 'decimal');
    }
    #[Test]
    public function checkout_walkin_customer_has_no_auto_discount()
    {
        // Default test customer has no tipe/kategori — behaves like walk-in
        $sales = $this->action->execute($this->baseCheckoutData());

        $this->assertEquals('none', $sales->diskon_nota_1_tipe);
        $this->assertEquals('none', $sales->diskon_nota_2_tipe);
        $this->assertEquals(0, $sales->total_diskon);
        $this->assertEquals(10000, $sales->grand_total);
    }

    // =====================================================================
    // EDGE CASE TAMBAHAN — galak, assertion eksak
    // =====================================================================

    /**

     * Pembayaran kurang 1 rupiah pun ditolak + tidak ada DocSales tersimpan + stok utuh

     */

    #[Test]
    public function checkout_menolak_pembayaran_kurang_dan_tidak_menyimpan_apa_pun()
    {
        try {
            $this->action->execute($this->baseCheckoutData([
                'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 9999]],
            ]));
            $this->fail('Seharusnya ValidationException dilempar karena pembayaran kurang');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('payments', $e->errors());
        }

        // Tidak ada dokumen/detail/payment yang tersimpan (rollback transaksi)
        $this->assertEquals(0, DocSales::count(), 'Tidak boleh ada DocSales saat bayar kurang');
        $this->assertEquals(0, \App\Models\DocSalesDetail::count());
        $this->assertEquals(0, \App\Models\DocSalesPayment::count());

        // Stok tetap 100, tak ada stock_card SALES
        $this->assertEquals(100, InventoryStock::where('product_id', $this->product->id)->first()->qty);
        $this->assertEquals(0, StockCard::where('transaction_type', 'SALES')->count());
    }

    /**

     * Bayar persis pas (exact) → kembalian 0, tidak under-pay

     */

    #[Test]
    public function checkout_bayar_persis_pas_kembalian_nol()
    {
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 3, 'qty_base' => 3, 'jumlah' => 30000])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 30000]],
        ]));

        $this->assertEquals(30000, $sales->grand_total);
        $this->assertEquals(30000, $sales->total_bayar);
        $this->assertEquals(0, $sales->kembalian);
    }

    /**

     * Multi-payment overpay → kembalian eksak = total bayar - grand total

     */

    #[Test]
    public function checkout_multi_payment_overpay_kembalian_eksak()
    {
        // Grand total 20000 (2 × 10000). Bayar 15000 cash + 10000 transfer = 25000 → kembalian 5000
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 2, 'qty_base' => 2, 'jumlah' => 20000])],
            'payments' => [
                ['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 15000],
                ['metode_pembayaran_id' => $this->transferPayment->id, 'nominal' => 10000],
            ],
        ]));

        $this->assertEquals(20000, $sales->grand_total);
        $this->assertEquals(25000, $sales->total_bayar);
        $this->assertEquals(5000, $sales->kembalian);
        $this->assertEquals(2, $sales->payments->count());
    }

    /**

     * Anti-fraud: FE inject diskon_1..4 palsu di item — tanpa promo DB harus di-nol-kan

     */

    #[Test]
    public function checkout_anti_fraud_diskon_line_1_sampai_4_diabaikan_tanpa_promo()
    {
        // Tidak ada promo aktif. FE coba kirim diskon_1 50% + diskon_2 30% di line.
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem([
                'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 50,
                'diskon_2_tipe' => 'percent', 'diskon_2_nilai' => 30,
                'diskon_3_tipe' => 'percent', 'diskon_3_nilai' => 20,
                'diskon_4_tipe' => 'percent', 'diskon_4_nilai' => 10,
                'jumlah' => 1000, // FE klaim diskon besar
            ])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 10000]],
        ]));

        $detail = $sales->details->first();
        // Semua slot 1..4 di-rebuild dari DB (none) → tidak ada diskon
        $this->assertEquals('none', $detail->diskon_1_tipe);
        $this->assertEquals('none', $detail->diskon_2_tipe);
        $this->assertEquals('none', $detail->diskon_3_tipe);
        $this->assertEquals('none', $detail->diskon_4_tipe);
        $this->assertEquals(0, $detail->diskon_1_hasil);
        $this->assertEquals(0, $detail->diskon_2_hasil);
        $this->assertEquals(0, $detail->diskon_3_hasil);
        $this->assertEquals(0, $detail->diskon_4_hasil);
        $this->assertEquals(0, $detail->diskon_total);
        // Harga penuh — bukan 1000 yang diklaim FE
        $this->assertEquals(10000, $detail->jumlah);
        $this->assertEquals(10000, $sales->grand_total);
    }

    /**

     * Promo DB aktif di-rebuild ke slot 1 walau FE kirim 'none' (anti-fraud arah sebaliknya)

     */

    #[Test]
    public function checkout_promo_db_di_rebuild_walau_fe_kirim_none()
    {
        $promo = \App\Models\DocPromo::create([
            'ulid' => (string) Str::ulid(),
            'kode_promo' => 'PROMO-REBUILD',
            'nama_promo' => 'Promo Rebuild',
            'tanggal_mulai' => now()->subDay()->toDateString(),
            'tanggal_selesai' => now()->addDay()->toDateString(),
            'status' => 'approved',
            'created_by' => $this->user->id,
        ]);
        \DB::table('doc_promo_details')->insert([
            'promo_id' => $promo->id,
            'target_type' => 'produk',
            'target_id' => $this->product->id,
            'min_qty' => 1,
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
        ]);

        // FE kirim semua slot 'none' (default buildItem) — backend tetap pasang promo 10%
        $sales = $this->action->execute($this->baseCheckoutData([
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 9000]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals('percent', $detail->diskon_1_tipe);
        $this->assertEquals(10, $detail->diskon_1_nilai);
        $this->assertEquals(1000, $detail->diskon_1_hasil, '10% × 10000 dari promo DB');
        $this->assertEquals($promo->id, $detail->promo_id);
        $this->assertEquals(9000, $detail->jumlah);
        $this->assertEquals(9000, $sales->grand_total);
    }

    /**

     * Diskon manual nominal melebihi base → dibatasi calculateDiscountLevel ke base (tidak negatif)

     */

    #[Test]
    public function checkout_diskon_5_nominal_melebihi_harga_dibatasi_ke_base()
    {
        // diskon_5 nominal 50000 untuk item harga 10000 → min(50000, 10000) = 10000, jumlah = 0
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem([
                'diskon_5_tipe' => 'nominal',
                'diskon_5_nilai' => 50000,
                'jumlah' => 0,
            ])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 0]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(10000, $detail->diskon_5_hasil, 'Diskon nominal di-cap ke base (tidak melebihi harga)');
        $this->assertEquals(0, $detail->jumlah, 'Jumlah tidak boleh negatif');
        $this->assertEquals(0, $sales->grand_total);
    }

    /**

     * hpp_at_time retail = avg_cost saat jual, dan stok berkurang eksak + invarian data konsisten

     */

    #[Test]
    public function checkout_hpp_at_time_sama_dengan_avg_cost_dan_invarian_konsisten()
    {
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 7, 'qty_base' => 7, 'jumlah' => 70000])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 70000]],
        ]));

        $detail = $sales->details->first();
        $this->assertEquals(5000, (float) $detail->hpp_at_time, 'HPP = avg_cost (5000)');

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(93, $stock->qty, '100 - 7 = 93');
        $this->assertEquals(5000, (float) $stock->avg_cost, 'SALES tidak ubah HPP');

        // Invarian stok harus konsisten setelah mutasi
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**

     * Jual seluruh stok global → HPP retail di-reset 0 + ada stock_card HPP_RESET

     */

    #[Test]
    public function checkout_menjual_seluruh_stok_mereset_hpp_ke_nol()
    {
        // Stok awal 100, jual 100 → stok global 0
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 100, 'qty_base' => 100, 'jumlah' => 1000000])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 1000000]],
        ]));

        // Detail tetap rekam HPP saat jual = 5000 (penting untuk restore saat void/retur)
        $this->assertEquals(5000, (float) $sales->details->first()->hpp_at_time);

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(0, $stock->qty);
        $this->assertEquals(0, (float) $stock->avg_cost, 'HPP di-reset 0 saat stok habis');
        $this->assertEquals(0, (float) $this->product->fresh()->avg_cost);

        // Ada stock_card HPP_RESET dengan avg_cost_before 5000 → after 0
        $reset = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')->first();
        $this->assertNotNull($reset, 'Harus ada entry HPP_RESET');
        $this->assertEquals(5000, (float) $reset->avg_cost_before);
        $this->assertEquals(0, (float) $reset->avg_cost_after);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**

     * Stok pas-pasan (tepat sama qty) → boleh; kelebihan 1 → ditolak

     */

    #[Test]
    public function checkout_stok_tepat_boleh_kelebihan_satu_ditolak()
    {
        // Tepat 100 → boleh
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 100, 'qty_base' => 100, 'jumlah' => 1000000])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 1000000]],
        ]));
        $this->assertEquals('completed', $sales->status);
        $this->assertEquals(0, InventoryStock::where('product_id', $this->product->id)->first()->qty);

        // Sekarang stok 0, coba jual 1 lagi → ditolak (block mode)
        $this->expectException(ValidationException::class);
        $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem()],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 10000]],
        ]));
    }

    /**

     * negative_mode=allow → boleh jual melebihi stok, stok jadi negatif eksak

     */

    #[Test]
    public function checkout_negative_mode_allow_membolehkan_stok_minus()
    {
        SettingService::set('stock.negative_mode', 'allow', 'string');

        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [$this->buildItem(['qty' => 130, 'qty_base' => 130, 'jumlah' => 1300000])],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 1300000]],
        ]));

        $this->assertEquals('completed', $sales->status);
        $this->assertEquals(-30, InventoryStock::where('product_id', $this->product->id)->first()->qty, '100 - 130 = -30');

        SettingService::set('stock.negative_mode', 'block', 'string');
    }

    /**

     * Diskon manual ditolak saat allow_manual_discount=false (meski promo enabled)

     */

    #[Test]
    public function checkout_diskon_manual_ditolak_saat_allow_manual_false()
    {
        SettingService::set('promo.allow_manual_discount', false, 'boolean');

        $sales = $this->action->execute($this->baseCheckoutData([
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'percent', 'nilai' => 15],
            ],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 10000]],
        ]));

        $this->assertEquals(0, $sales->diskon_nota_3_hasil, 'Diskon manual ditolak saat allow_manual_discount=false');
        $this->assertEquals(10000, $sales->grand_total);

        SettingService::set('promo.allow_manual_discount', true, 'boolean');
    }

    /**

     * Diskon manual nominal di-cap ke max_manual_discount_nominal

     */

    #[Test]
    public function checkout_diskon_manual_nominal_dibatasi_max_setting()
    {
        SettingService::set('promo.max_manual_discount_nominal', 2000, 'decimal');

        // Kasir coba potong 8000 nominal → di-cap ke 2000
        $sales = $this->action->execute($this->baseCheckoutData([
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'nominal', 'nilai' => 8000],
            ],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 8000]],
        ]));

        $this->assertEquals(2000, $sales->diskon_nota_3_hasil, 'Diskon nominal di-cap ke 2000');
        $this->assertEquals(8000, $sales->grand_total);

        SettingService::set('promo.max_manual_discount_nominal', null, 'decimal');
    }

    /**

     * Pajak penjualan eksklusif: PPN 10% ditambahkan di atas subtotal, grand_total eksak

     */

    #[Test]
    public function checkout_pajak_eksklusif_ditambahkan_di_atas_subtotal()
    {
        SettingService::set('tax.tax_sales_percent', 10, 'integer');
        SettingService::set('tax.tax_sales_name', 'PPN', 'string');

        // Subtotal 10000, PPN 10% = 1000, grand total = 11000, bayar pas
        $sales = $this->action->execute($this->baseCheckoutData([
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 11000]],
        ]));

        $this->assertEquals(10000, $sales->subtotal);
        $this->assertEquals(10, (float) $sales->pajak_persen);
        $this->assertEquals(1000, (float) $sales->pajak_nominal, 'PPN 10% × 10000 = 1000');
        $this->assertEquals(11000, $sales->grand_total);
        $this->assertEquals(0, $sales->kembalian);

        SettingService::set('tax.tax_sales_percent', 0, 'integer');
    }

    /**

     * Pajak eksklusif: bayar < grand_total (subtotal saja, lupa pajak) → ditolak

     */

    #[Test]
    public function checkout_pajak_eksklusif_bayar_lupa_pajak_ditolak()
    {
        SettingService::set('tax.tax_sales_percent', 10, 'integer');

        $this->expectException(ValidationException::class);
        try {
            $this->action->execute($this->baseCheckoutData([
                'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 10000]], // lupa +1000 pajak
            ]));
        } finally {
            SettingService::set('tax.tax_sales_percent', 0, 'integer');
        }
    }

    /**

     * Multi-line produk sama → stok dikurangi akumulatif (running stock), satu InventoryStock

     */

    #[Test]
    public function checkout_multi_line_produk_sama_mengurangi_stok_akumulatif()
    {
        $sales = $this->action->execute($this->baseCheckoutData([
            'items' => [
                $this->buildItem(['qty' => 4, 'qty_base' => 4, 'jumlah' => 40000]),
                $this->buildItem(['qty' => 6, 'qty_base' => 6, 'jumlah' => 60000]),
            ],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 100000]],
        ]));

        $this->assertEquals(2, $sales->details->count());
        $this->assertEquals(100000, $sales->grand_total);

        // 100 - 4 - 6 = 90, satu baris inventory_stock
        $rows = InventoryStock::where('product_id', $this->product->id)->get();
        $this->assertEquals(1, $rows->count());
        $this->assertEquals(90, $rows->first()->qty);

        // Dua stock_card SALES (satu per line)
        $this->assertEquals(2, StockCard::where('transaction_id', $sales->id)->where('transaction_type', 'SALES')->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
}
