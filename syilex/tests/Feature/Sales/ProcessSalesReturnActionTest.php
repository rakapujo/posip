<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CheckoutSalesAction;
use App\Actions\Sales\ProcessSalesReturnAction;
use App\Actions\Sales\VoidSalesAction;
use App\Models\DocSales;
use App\Models\DocSalesReturn;
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
use App\Models\PosCashTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ProcessSalesReturnActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterPosTerminal $terminal;
    protected PosTerminalShift $shift;
    protected MasterCustomer $customer;
    protected MasterMetodePembayaran $cashPayment;
    protected MasterProduk $product;
    protected CheckoutSalesAction $checkoutAction;
    protected ProcessSalesReturnAction $returnAction;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-001',
            'nama' => 'Test Customer',
            'telepon' => '081234',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

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

        $this->shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'started_at' => now(),
        ]);

        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Test Product',
            'avg_cost' => 5000,
            'harga_4' => 10000,
            'unit_1' => 'PCS',
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        // Rekam stock_card PURCHASE padanan agar invarian data:verify bermakna.
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

        $this->checkoutAction = new CheckoutSalesAction();
        $this->returnAction = new ProcessSalesReturnAction();
    }

    private function doCheckout(int $qty = 5, float $harga = 10000): DocSales
    {
        return $this->checkoutAction->execute([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'items' => [[
                'product_id' => $this->product->id,
                'unit' => 'PCS', 'konversi' => 1,
                'qty' => $qty, 'qty_base' => $qty,
                'harga_satuan' => $harga,
                'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                'diskon_total' => 0, 'jumlah' => $qty * $harga,
            ]],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => $qty * $harga]],
        ]);
    }

    private function buildReturnData(DocSales $sales, int $qty, float $hargaPerBase = 10000): array
    {
        $salesDetail = $sales->details->first();

        return [
            'sales_id' => $sales->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'refund_method' => 'cash',
            'items' => [[
                'sales_detail_id' => $salesDetail->id,
                'product_id' => $this->product->id,
                'qty' => $qty,
                'harga_per_base' => $hargaPerBase,
            ]],
        ];
    }
    #[Test]
    public function process_return_creates_sales_return_document()
    {
        $sales = $this->doCheckout(5);

        $salesReturn = $this->returnAction->execute($this->buildReturnData($sales, 2));

        $this->assertInstanceOf(DocSalesReturn::class, $salesReturn);
        $this->assertEquals($sales->id, $salesReturn->sales_id);
        $this->assertStringStartsWith('RPJ-', $salesReturn->nomor_dokumen);
        $this->assertEquals(20000, $salesReturn->grand_total, '2 × 10000 = 20000');
        $this->assertEquals('cash', $salesReturn->refund_method);
    }
    #[Test]
    public function process_return_restores_stock()
    {
        // Stock 100 → checkout 5 → stock 95 → return 2 → stock 97
        $sales = $this->doCheckout(5);
        $this->assertEquals(95, InventoryStock::where('product_id', $this->product->id)->first()->qty);

        $this->returnAction->execute($this->buildReturnData($sales, 2));

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(97, $stock->qty);
    }
    #[Test]
    public function process_return_creates_sales_return_stock_card()
    {
        $sales = $this->doCheckout(5);

        $salesReturn = $this->returnAction->execute($this->buildReturnData($sales, 3));

        $returnEntry = StockCard::where('transaction_id', $salesReturn->id)
            ->where('transaction_type', 'SALES_RETURN')
            ->first();

        $this->assertNotNull($returnEntry);
        $this->assertEquals(3, $returnEntry->qty_in);
        $this->assertEquals(0, $returnEntry->qty_out);
    }
    #[Test]
    public function process_return_throws_when_qty_exceeds_max_returnable()
    {
        $sales = $this->doCheckout(5);

        // Trying to return 10 when only 5 sold
        $this->expectException(ValidationException::class);
        $this->returnAction->execute($this->buildReturnData($sales, 10));
    }
    #[Test]
    public function process_return_throws_when_sales_already_voided()
    {
        $sales = $this->doCheckout(5);

        // Void first
        (new VoidSalesAction())->execute($sales->fresh(), 'cancelled');

        $this->expectException(ValidationException::class);
        $this->returnAction->execute($this->buildReturnData($sales, 2));
    }
    #[Test]
    public function process_return_allows_partial_return_then_tracks_remainder()
    {
        $sales = $this->doCheckout(10);

        // First return: 4
        $this->returnAction->execute($this->buildReturnData($sales, 4));

        // Second return: 5 (4 already returned, max remaining = 6) → should work
        $this->returnAction->execute($this->buildReturnData($sales->fresh(), 5));

        // Third return: 2 (9 already returned, max remaining = 1) → should fail
        $this->expectException(ValidationException::class);
        $this->returnAction->execute($this->buildReturnData($sales->fresh(), 2));
    }

    // =====================================================================
    // EDGE CASE TAMBAHAN — galak, assertion eksak
    // =====================================================================

    /**
     * Harga retur di-recalc dari sales (anti-fraud): harga_per_base FE diabaikan.
     * Subtotal 50000, tanpa diskon, tanpa pajak → harga per base = 10000. Return 2 = 20000
     * walau FE klaim 99999 per base.
     */
    public function retur_mengabaikan_harga_per_base_dari_frontend()
    {
        $sales = $this->doCheckout(5); // 5 × 10000

        $salesReturn = $this->returnAction->execute(
            $this->buildReturnData($sales, 2, 99999) // FE klaim harga tinggi → diabaikan
        );

        $this->assertEquals(20000, $salesReturn->grand_total, 'Harga di-recalc dari sales, bukan FE');
        $detail = $salesReturn->details->first();
        $this->assertEquals(10000, (float) $detail->harga_satuan);
        $this->assertEquals(20000, (float) $detail->jumlah);
    }

    /**
     * Harga retur prorata TERMASUK pajak.
     * Sale 5 × 10000 = 50000, PPN 10% → pool = 55000, per base = 11000.
     * Return 2 → grand_total 22000. pajak_persen di retur 0 (sudah inklusif).
     */
    public function retur_harga_prorata_termasuk_pajak()
    {
        SettingService::set('tax.tax_sales_percent', 10, 'integer');

        $sales = $this->checkoutWithTax(5, 10000); // grand_total 55000

        $this->assertEquals(55000, $sales->grand_total);

        $salesReturn = $this->returnAction->execute($this->buildReturnData($sales, 2));

        // 2 × (55000/5) = 22000
        $this->assertEquals(22000, $salesReturn->grand_total, 'Prorata termasuk pajak');
        $this->assertEquals(0, (float) $salesReturn->pajak_persen, 'Pajak sudah inklusif di harga prorata');
        $this->assertEquals(0, (float) $salesReturn->pajak_nominal);
        $detail = $salesReturn->details->first();
        $this->assertEquals(11000, (float) $detail->harga_satuan, 'Per base = 55000/5');

        SettingService::set('tax.tax_sales_percent', 0, 'integer');
    }

    /**
     * Harga retur prorata memperhitungkan diskon nota.
     * Sale 10 × 10000 = 100000, diskon nota 3 manual 10% → total_setelah_diskon 90000, tanpa pajak.
     * pool = 90000, per base = 9000. Return 4 → 36000.
     */
    public function retur_harga_prorata_memperhitungkan_diskon_nota()
    {
        $sales = $this->checkoutAction->execute([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'items' => [[
                'product_id' => $this->product->id, 'unit' => 'PCS', 'konversi' => 1,
                'qty' => 10, 'qty_base' => 10, 'harga_satuan' => 10000,
                'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                'diskon_total' => 0, 'jumlah' => 100000,
            ]],
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'percent', 'nilai' => 10],
            ],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 90000]],
        ]);

        $this->assertEquals(90000, $sales->grand_total);

        $salesReturn = $this->returnAction->execute($this->buildReturnData($sales, 4));

        // pool = 90000 (total_setelah_diskon), per base = 9000, return 4 = 36000
        $this->assertEquals(36000, $salesReturn->grand_total, 'Prorata ikut diskon nota');
        $this->assertEquals(9000, (float) $salesReturn->details->first()->harga_satuan);
    }

    /**

     * Retur membuat refund kas (PosCashTransaction kas_keluar) sebesar grand_total

     */

    #[Test]
    public function retur_membuat_refund_kas_keluar_eksak()
    {
        $sales = $this->doCheckout(5);

        $salesReturn = $this->returnAction->execute($this->buildReturnData($sales, 3));

        $kas = PosCashTransaction::where('shift_id', $this->shift->id)
            ->where('tipe', 'kas_keluar')->get();

        $this->assertEquals(1, $kas->count(), 'Harus ada satu transaksi kas_keluar');
        $this->assertEquals(30000, (float) $kas->first()->nominal, 'Refund = grand_total retur (3 × 10000)');
        $this->assertEquals(30000, $salesReturn->grand_total);
        $this->assertStringContainsString($salesReturn->nomor_dokumen, $kas->first()->keterangan);
    }

    /**

     * Retur dari nota voided ditolak dengan error key sales_id + tak ada dokumen retur

     */

    #[Test]
    public function retur_dari_nota_voided_ditolak()
    {
        $sales = $this->doCheckout(5);
        (new VoidSalesAction())->execute($sales->fresh(), 'Void dulu');

        try {
            $this->returnAction->execute($this->buildReturnData($sales, 2));
            $this->fail('Retur dari nota voided seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('sales_id', $e->errors());
        }

        $this->assertEquals(0, DocSalesReturn::count(), 'Tidak ada dokumen retur tersimpan');
        // Tidak ada refund kas
        $this->assertEquals(0, PosCashTransaction::where('tipe', 'kas_keluar')->count());
    }

    /**

     * Qty retur melebihi max → ditolak + stok & dokumen tidak berubah

     */

    #[Test]
    public function retur_qty_berlebih_ditolak_tanpa_efek_samping()
    {
        $sales = $this->doCheckout(5); // stok 95
        $this->assertEquals(95, InventoryStock::where('product_id', $this->product->id)->first()->qty);

        try {
            $this->returnAction->execute($this->buildReturnData($sales, 6));
            $this->fail('Qty retur berlebih seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('items', $e->errors());
        }

        // Tidak ada dokumen retur, stok tetap 95, tak ada refund
        $this->assertEquals(0, DocSalesReturn::count());
        $this->assertEquals(95, InventoryStock::where('product_id', $this->product->id)->first()->qty);
        $this->assertEquals(0, PosCashTransaction::where('tipe', 'kas_keluar')->count());
    }

    /**

     * Retur multi-line (dua produk) → stok masing-masing pulih + invarian konsisten

     */

    #[Test]
    public function retur_multi_line_dua_produk_stok_pulih()
    {
        // Produk kedua
        $product2 = MasterProduk::factory()->create([
            'nama_produk' => 'Produk Dua', 'avg_cost' => 3000,
            'harga_4' => 8000, 'unit_1' => 'PCS', 'unit_4' => 'PCS', 'konversi_4' => 1, 'status' => 'active',
        ]);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $product2->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 50, 'avg_cost' => 3000]
        );
        StockCard::record([
            'product_id' => $product2->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 50, 'qty_out' => 0, 'cost_per_unit' => 3000,
        ]);
        StockCard::$skipObserver = false;

        $sales = $this->checkoutAction->execute([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id, 'unit' => 'PCS', 'konversi' => 1,
                    'qty' => 5, 'qty_base' => 5, 'harga_satuan' => 10000,
                    'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                    'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                    'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                    'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                    'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                    'diskon_total' => 0, 'jumlah' => 50000,
                ],
                [
                    'product_id' => $product2->id, 'unit' => 'PCS', 'konversi' => 1,
                    'qty' => 4, 'qty_base' => 4, 'harga_satuan' => 8000,
                    'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                    'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                    'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                    'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                    'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                    'diskon_total' => 0, 'jumlah' => 32000,
                ],
            ],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 82000]],
        ]);

        $d1 = $sales->details->firstWhere('product_id', $this->product->id);
        $d2 = $sales->details->firstWhere('product_id', $product2->id);

        // Retur 2 dari produk1 + 1 dari produk2
        $salesReturn = $this->returnAction->execute([
            'sales_id' => $sales->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'refund_method' => 'cash',
            'items' => [
                ['sales_detail_id' => $d1->id, 'product_id' => $this->product->id, 'qty' => 2, 'harga_per_base' => 10000],
                ['sales_detail_id' => $d2->id, 'product_id' => $product2->id, 'qty' => 1, 'harga_per_base' => 8000],
            ],
        ]);

        $this->assertEquals(2, $salesReturn->details->count());
        // Subtotal sale 82000, tanpa pajak. produk1 per base = 50000/5=10000; produk2 = 32000/4=8000
        // Return: 2×10000 + 1×8000 = 28000
        $this->assertEquals(28000, $salesReturn->grand_total);

        // Stok: produk1 100-5+2=97, produk2 50-4+1=47
        $this->assertEquals(97, InventoryStock::where('product_id', $this->product->id)->first()->qty);
        $this->assertEquals(47, InventoryStock::where('product_id', $product2->id)->first()->qty);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**

     * Retur menyimpan hpp_at_time dari sales (untuk valuasi) + stock_card SALES_RETURN konsisten

     */

    #[Test]
    public function retur_menyimpan_hpp_dan_invarian_konsisten()
    {
        $sales = $this->doCheckout(5);

        $salesReturn = $this->returnAction->execute($this->buildReturnData($sales, 3));

        $detail = $salesReturn->details->first();
        $this->assertEquals(5000, (float) $detail->hpp_at_time, 'HPP retur = avg_cost saat itu (5000)');

        $card = StockCard::where('transaction_id', $salesReturn->id)
            ->where('transaction_type', 'SALES_RETURN')->first();
        $this->assertEquals(3, $card->qty_in);
        $this->assertEquals(0, $card->qty_out);
        $this->assertEquals(5000, (float) $card->cost_per_unit);
        $this->assertStringContainsString($sales->nomor_dokumen, $card->notes);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Helper checkout dengan pajak aktif (tax sudah di-set ke nilai non-nol oleh pemanggil).
     */
    private function checkoutWithTax(int $qty, float $harga): DocSales
    {
        return $this->checkoutAction->execute([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'items' => [[
                'product_id' => $this->product->id, 'unit' => 'PCS', 'konversi' => 1,
                'qty' => $qty, 'qty_base' => $qty, 'harga_satuan' => $harga,
                'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                'diskon_total' => 0, 'jumlah' => $qty * $harga,
            ]],
            // bayar cukup: subtotal + 10% pajak
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => $qty * $harga * 1.1]],
        ]);
    }
}
