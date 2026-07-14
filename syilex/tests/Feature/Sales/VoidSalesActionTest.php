<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\CheckoutSalesAction;
use App\Actions\Sales\VoidSalesAction;
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

class VoidSalesActionTest extends TestCase
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
    protected VoidSalesAction $voidAction;

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
        $this->voidAction = new VoidSalesAction();
    }

    private function doCheckout(int $qty = 5): DocSales
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
                'harga_satuan' => 10000,
                'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                'diskon_total' => 0, 'jumlah' => $qty * 10000,
            ]],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => $qty * 10000]],
        ]);
    }
    #[Test]
    public function void_sales_updates_status_to_voided()
    {
        $sales = $this->doCheckout(5);

        $voided = $this->voidAction->execute($sales->fresh(), 'Wrong item');

        $this->assertEquals('voided', $voided->status);
        $this->assertNotNull($voided->voided_at);
        $this->assertEquals($this->user->id, $voided->voided_by);
        $this->assertEquals('Wrong item', $voided->void_reason);
    }
    #[Test]
    public function void_sales_restores_stock()
    {
        // Stock 100 → checkout 5 → stock 95 → void → stock back to 100
        $sales = $this->doCheckout(5);

        $stockAfterCheckout = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(95, $stockAfterCheckout->qty);

        $this->voidAction->execute($sales->fresh(), 'Customer cancel');

        $stockAfterVoid = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(100, $stockAfterVoid->qty, 'Stock should be restored to 100');
    }
    #[Test]
    public function void_sales_creates_sales_return_stock_card_entry()
    {
        $sales = $this->doCheckout(3);

        $this->voidAction->execute($sales->fresh(), 'Mistake');

        $voidEntry = StockCard::where('transaction_id', $sales->id)
            ->where('transaction_type', 'SALES_RETURN')
            ->first();

        $this->assertNotNull($voidEntry);
        $this->assertEquals(3, $voidEntry->qty_in, 'Void = restore stock (qty_in)');
        $this->assertEquals(0, $voidEntry->qty_out);
        $this->assertStringContainsString('VOID: Mistake', $voidEntry->notes);
    }
    #[Test]
    public function void_sales_throws_when_already_voided()
    {
        $sales = $this->doCheckout(5);

        $this->voidAction->execute($sales->fresh(), 'First void');

        $this->expectException(ValidationException::class);
        $this->voidAction->execute($sales->fresh(), 'Second void');
    }

    // =====================================================================
    // EDGE CASE TAMBAHAN — galak, assertion eksak
    // =====================================================================

    /**

     * Void ganda: void kedua ditolak + status tetap voided + stok TIDAK dobel restore

     */

    #[Test]
    public function void_ganda_ditolak_dan_stok_tidak_dobel_restore()
    {
        $sales = $this->doCheckout(5);
        $this->voidAction->execute($sales->fresh(), 'First void');

        // Stok sudah balik 100 setelah void pertama
        $this->assertEquals(100, InventoryStock::where('product_id', $this->product->id)->first()->qty);

        try {
            $this->voidAction->execute($sales->fresh(), 'Second void');
            $this->fail('Void kedua seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Status tetap voided, stok tetap 100 (tidak jadi 105)
        $this->assertEquals('voided', $sales->fresh()->status);
        $this->assertEquals(100, InventoryStock::where('product_id', $this->product->id)->first()->qty);
        // Hanya satu stock_card SALES_RETURN dari void pertama
        $this->assertEquals(1, StockCard::where('transaction_id', $sales->id)
            ->where('transaction_type', 'SALES_RETURN')->count());
    }

    /**
     * Hanya status 'completed' yang bisa void. Enum doc_sales.status cuma
     * ['completed','voided'] (lihat migration create_pos_sales_tables) jadi satu-satunya
     * status non-completed adalah 'voided'. Pastikan canVoid() menolak dengan error key 'status'
     * dan TIDAK membuat stock_card SALES_RETURN tambahan.
     */
    public function void_menolak_transaksi_non_completed()
    {
        $sales = $this->doCheckout(5);
        // Paksa status voided langsung di DB tanpa lewat action (tak ada stock_card restore)
        \App\Models\DocSales::where('id', $sales->id)->update(['status' => 'voided']);

        $cardsBefore = StockCard::where('transaction_id', $sales->id)
            ->where('transaction_type', 'SALES_RETURN')->count();
        $this->assertEquals(0, $cardsBefore);

        try {
            $this->voidAction->execute($sales->fresh(), 'Coba void non-completed');
            $this->fail('Void status non-completed seharusnya ditolak');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Tidak ada stock_card restore yang dibuat
        $this->assertEquals(0, StockCard::where('transaction_id', $sales->id)
            ->where('transaction_type', 'SALES_RETURN')->count());
    }

    /**

     * Void mengembalikan stok eksak + stock_card SALES_RETURN qty_in eksak + invarian konsisten

     */

    #[Test]
    public function void_mengembalikan_stok_dan_invarian_konsisten()
    {
        $sales = $this->doCheckout(8);
        $this->assertEquals(92, InventoryStock::where('product_id', $this->product->id)->first()->qty);

        $this->voidAction->execute($sales->fresh(), 'Salah input');

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(100, $stock->qty);
        $this->assertEquals(5000, (float) $stock->avg_cost, 'HPP tetap 5000');

        $card = StockCard::where('transaction_id', $sales->id)
            ->where('transaction_type', 'SALES_RETURN')->first();
        $this->assertEquals(8, $card->qty_in);
        $this->assertEquals(0, $card->qty_out);
        $this->assertEquals(5000, (float) $card->avg_cost_before);
        $this->assertEquals(5000, (float) $card->avg_cost_after);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**

     * Void setelah HPP ter-reset 0 (stok habis) → HPP dipulihkan dari hpp_at_time detail

     */

    #[Test]
    public function void_memulihkan_hpp_yang_sempat_di_reset_nol()
    {
        // Jual seluruh 100 → stok global 0 → HPP retail di-reset 0
        $sales = $this->doCheckout(100);
        $this->assertEquals(0, (float) $this->product->fresh()->avg_cost, 'HPP ter-reset 0 saat stok habis');
        $this->assertEquals(0, InventoryStock::where('product_id', $this->product->id)->first()->qty);

        // Void → stok balik 100 + HPP dipulihkan ke 5000 (dari hpp_at_time detail)
        $this->voidAction->execute($sales->fresh(), 'Batal');

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(100, $stock->qty);
        $this->assertEquals(5000, (float) $stock->avg_cost, 'HPP dipulihkan ke 5000');
        $this->assertEquals(5000, (float) $this->product->fresh()->avg_cost);

        // stock_card void: avg_cost_before 0 → after 5000 (restore)
        $card = StockCard::where('transaction_id', $sales->id)
            ->where('transaction_type', 'SALES_RETURN')->first();
        $this->assertEquals(0, (float) $card->avg_cost_before);
        $this->assertEquals(5000, (float) $card->avg_cost_after);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**

     * Void multi-line produk sama → seluruh qty dikembalikan akumulatif

     */

    #[Test]
    public function void_multi_line_produk_sama_kembalikan_semua_qty()
    {
        $sales = $this->checkoutAction->execute([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'items' => [
                [
                    'product_id' => $this->product->id, 'unit' => 'PCS', 'konversi' => 1,
                    'qty' => 3, 'qty_base' => 3, 'harga_satuan' => 10000,
                    'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                    'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                    'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                    'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                    'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                    'diskon_total' => 0, 'jumlah' => 30000,
                ],
                [
                    'product_id' => $this->product->id, 'unit' => 'PCS', 'konversi' => 1,
                    'qty' => 7, 'qty_base' => 7, 'harga_satuan' => 10000,
                    'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                    'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                    'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                    'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                    'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                    'diskon_total' => 0, 'jumlah' => 70000,
                ],
            ],
            'payments' => [['metode_pembayaran_id' => $this->cashPayment->id, 'nominal' => 100000]],
        ]);

        // 100 - 3 - 7 = 90
        $this->assertEquals(90, InventoryStock::where('product_id', $this->product->id)->first()->qty);

        $this->voidAction->execute($sales->fresh(), 'Void multi');

        // Kembali ke 100 (3 + 7 dikembalikan)
        $this->assertEquals(100, InventoryStock::where('product_id', $this->product->id)->first()->qty);
        // Dua stock_card SALES_RETURN (satu per line)
        $this->assertEquals(2, StockCard::where('transaction_id', $sales->id)
            ->where('transaction_type', 'SALES_RETURN')->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**

     * Void diblokir bila sales sudah punya retur (canVoid = completed && tanpa retur)

     */

    #[Test]
    public function void_diblokir_bila_sudah_ada_retur()
    {
        $sales = $this->doCheckout(5);

        // Proses retur 2 dulu
        $returnAction = new \App\Actions\Sales\ProcessSalesReturnAction();
        $salesDetail = $sales->details->first();
        $returnAction->execute([
            'sales_id' => $sales->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'refund_method' => 'cash',
            'items' => [[
                'sales_detail_id' => $salesDetail->id,
                'product_id' => $this->product->id,
                'qty' => 2,
                'harga_per_base' => 10000,
            ]],
        ]);

        // Sales masih 'completed' tapi sudah ada retur → tidak bisa void
        $this->assertEquals('completed', $sales->fresh()->status);
        $this->expectException(ValidationException::class);
        $this->voidAction->execute($sales->fresh(), 'Coba void padahal ada retur');
    }
}
