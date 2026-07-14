<?php

namespace Tests\Feature\PurchaseOrder;

use App\Actions\PurchaseOrder\ApprovePurchaseOrderAction;
use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Models\DocPurchaseOrder;
use App\Models\HistoryHargaBeli;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\SupplierHutang;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class ApprovePurchaseOrderActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterSupplier $supplier;
    protected MasterProduk $product;
    protected CreatePurchaseOrderAction $createAction;
    protected ApprovePurchaseOrderAction $approveAction;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->supplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-001',
            'nama_supplier' => 'Test Supplier',
            'nama_pic' => 'John Doe',
            'telepon' => '08123456789',
            'tempo_default' => 14,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Test Product',
            'avg_cost' => 5000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 50, 'avg_cost' => 5000]
        );
        StockCard::$skipObserver = false;

        $this->createAction = new CreatePurchaseOrderAction();
        $this->approveAction = new ApprovePurchaseOrderAction();
    }

    private function createDraftPo(float $hargaPerUnit = 6000, int $qty = 10): DocPurchaseOrder
    {
        return $this->createAction->execute([
            'tanggal_po' => '2026-04-12',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => $qty,
                    'harga_per_unit' => $hargaPerUnit,
                ],
            ],
        ]);
    }

    /**
     * Seed saldo awal dengan stock_card padanan agar invariant
     * SUM(qty_in - qty_out) === inventory_stock.qty terpenuhi (data:verify HIJAU).
     * setUp() mengisi inventory_stock=50 TANPA stock_card, jadi helper ini
     * mengganti baris itu dengan saldo awal yang konsisten.
     */
    private function seedOpeningBalanceWithStockCard(int $qty, float $cost): void
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => $qty, 'avg_cost' => $cost]
        );
        StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => '2026-04-01',
            'qty_in' => $qty,
            'qty_out' => 0,
            'cost_per_unit' => $cost,
            'avg_cost_before' => 0,
            'avg_cost_after' => $cost,
        ]);
        StockCard::$skipObserver = false;
        $this->product->update(['avg_cost' => $cost]);
    }
    #[Test]
    public function approve_po_updates_status_to_approved()
    {
        $po = $this->createDraftPo();

        $approved = $this->approveAction->execute($po);

        $this->assertEquals('approved', $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertEquals($this->user->id, $approved->approved_by);
    }
    #[Test]
    public function approve_po_increases_inventory_stock()
    {
        // Initial stock = 50, PO qty = 10 → expected 60
        $po = $this->createDraftPo(6000, 10);

        $this->approveAction->execute($po);

        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(60, $stock->qty);
    }
    #[Test]
    public function approve_po_recalculates_hpp_using_weighted_average()
    {
        // Initial: 50 pcs @ 5000 = 250000
        // PO: 10 pcs @ 6000 = 60000
        // New HPP = (50*5000 + 10*6000) / 60 = 310000 / 60 = 5166.67 (approx)
        $po = $this->createDraftPo(6000, 10);

        $this->approveAction->execute($po);

        $this->product->refresh();
        $expectedHpp = (50 * 5000 + 10 * 6000) / 60;
        $this->assertEqualsWithDelta($expectedHpp, $this->product->avg_cost, 1.0);
    }
    #[Test]
    public function approve_po_creates_stock_card_purchase_entry()
    {
        $po = $this->createDraftPo(6000, 10);

        $this->approveAction->execute($po);

        $stockCard = StockCard::where('transaction_id', $po->id)
            ->where('transaction_type', 'PURCHASE')
            ->first();

        $this->assertNotNull($stockCard);
        $this->assertEquals(10, $stockCard->qty_in);
        $this->assertEquals(0, $stockCard->qty_out);
        $this->assertEquals(5000, $stockCard->avg_cost_before);
        $this->assertGreaterThan(5000, $stockCard->avg_cost_after, 'HPP should increase after PO');
        $this->assertEquals($po->nomor_dokumen, $stockCard->transaction_no);
    }
    #[Test]
    public function approve_po_creates_supplier_hutang_with_unpaid_status()
    {
        // PO: 10 × 6000 = 60000 grand total
        $po = $this->createDraftPo(6000, 10);

        $this->approveAction->execute($po);

        $hutang = SupplierHutang::where('po_id', $po->id)->first();

        $this->assertNotNull($hutang);
        $this->assertEquals('unpaid', $hutang->status);
        $this->assertEquals(60000, $hutang->nominal_awal);
        $this->assertEquals(0, $hutang->nominal_terbayar);
        $this->assertEquals(60000, $hutang->sisa_hutang);
        $this->assertEquals($this->supplier->id, $hutang->supplier_id);
    }
    #[Test]
    public function approve_po_with_cash_payment_auto_settles_hutang()
    {
        $po = $this->createAction->execute([
            'tanggal_po' => '2026-04-12',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'cash_payment' => true,
            'cash_metode' => 'cash',
            'cash_no_referensi' => 'KW-PO-1',
            'details' => [[
                'product_id' => $this->product->id, 'unit_used' => 'PCS',
                'unit_konversi' => 1, 'qty_in_unit' => 10, 'harga_per_unit' => 6000,
            ]],
        ]);

        $this->approveAction->execute($po);

        // Hutang dibuat tapi langsung lunas
        $hutang = SupplierHutang::where('po_id', $po->id)->first();
        $this->assertNotNull($hutang);
        $this->assertSame('paid', $hutang->status);
        $this->assertEquals(0, (float) $hutang->sisa_hutang);
        $this->assertEquals((float) $hutang->nominal_awal, (float) $hutang->nominal_terbayar);

        // Pembayaran hutang otomatis & completed
        $pay = \App\Models\DocPembayaranHutang::where('supplier_id', $this->supplier->id)->first();
        $this->assertNotNull($pay);
        $this->assertSame('completed', $pay->status);
        $this->assertEquals((float) $hutang->nominal_awal, (float) $pay->total_pembayaran);
        $this->assertSame('cash', $pay->metode_pembayaran);
    }
    #[Test]
    public function approve_po_creates_history_harga_beli()
    {
        $po = $this->createDraftPo(6000, 10);

        $this->approveAction->execute($po);

        $history = HistoryHargaBeli::where('po_id', $po->id)
            ->where('product_id', $this->product->id)
            ->first();

        $this->assertNotNull($history);
        $this->assertEquals(10, $history->qty_in_unit);
        $this->assertEquals(10, $history->qty_in_base);
        $this->assertEquals(6000, $history->harga_per_unit);
        $this->assertEquals($this->supplier->id, $history->supplier_id);
    }
    #[Test]
    public function approve_po_throws_when_already_approved()
    {
        $po = $this->createDraftPo();
        $this->approveAction->execute($po);

        // Try to approve again
        $this->expectException(ValidationException::class);
        $this->approveAction->execute($po->fresh());
    }
    #[Test]
    public function approve_po_is_rolled_back_when_exception_occurs()
    {
        $po = $this->createDraftPo();

        $initialStock = 50;
        $initialHutangCount = SupplierHutang::count();

        // Simulate: try to approve an already-approved PO (throws)
        $this->approveAction->execute($po);
        $approvedStock = InventoryStock::where('product_id', $this->product->id)->first()->qty;

        try {
            $this->approveAction->execute($po->fresh());
        } catch (ValidationException $e) {
            // expected
        }

        // Stock should not change after the failed second approval
        $stockAfterFail = InventoryStock::where('product_id', $this->product->id)->first()->qty;
        $this->assertEquals($approvedStock, $stockAfterFail);

        // Only 1 hutang record (from first successful approval)
        $this->assertEquals($initialHutangCount + 1, SupplierHutang::count());
    }

    // ===================================================================
    // EDGE CASE TAMBAHAN — HPP eksak, invariant stok, lifecycle ketat
    // ===================================================================
    #[Test]
    public function approve_po_hpp_weighted_average_eksak_disimpan_4_desimal()
    {
        // 50@5000 + 10@6000 = (250000+60000)/60 = 5166.66666... → decimal:4 = 5166.6667
        $this->seedOpeningBalanceWithStockCard(50, 5000);
        $po = $this->createDraftPo(6000, 10);

        $this->approveAction->execute($po);

        $this->product->refresh();
        $this->assertSame('5166.6667', (string) $this->product->avg_cost, 'HPP weighted-avg disimpan decimal:4 eksak.');

        // inventory_stock.avg_cost ikut tersinkron
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertSame('5166.6667', (string) $stock->avg_cost);
        $this->assertEquals(60, $stock->qty);
    }
    #[Test]
    public function approve_po_menjaga_invariant_stok_data_verify_hijau()
    {
        // Saldo awal punya stock_card padanan → setelah approve, invariant tetap konsisten.
        $this->seedOpeningBalanceWithStockCard(50, 5000);
        $po = $this->createDraftPo(6000, 10);

        $this->approveAction->execute($po);

        $this->assertSame(
            0,
            Artisan::call('data:verify', ['--fail-on-mismatch' => true]),
            'Invariant stok harus konsisten setelah approve PO.'
        );
    }
    #[Test]
    public function approve_po_dengan_biaya_kirim_menaikkan_hpp_via_cost_per_unit_landed()
    {
        // 50@5000 + (10@5000 + biaya kirim 10000 → cost_per_unit (50000+10000)/10 = 6000)
        // HPP = (250000 + 10*6000)/60 = 310000/60 = 5166.6667
        $this->seedOpeningBalanceWithStockCard(50, 5000);

        $po = $this->createAction->execute([
            'tanggal_po' => '2026-04-12',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'biaya_kirim_tipe' => 'nominal',
            'biaya_kirim_nilai' => 10000,
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS', 'unit_konversi' => 1,
                'qty_in_unit' => 10, 'harga_per_unit' => 5000,
            ]],
        ]);

        // cost_per_unit landed = 6000
        $this->assertEquals(6000, $po->details->first()->cost_per_unit);

        $this->approveAction->execute($po);

        $this->product->refresh();
        $this->assertSame('5166.6667', (string) $this->product->avg_cost, 'HPP memakai cost_per_unit landed (termasuk biaya kirim).');

        // stock_card mencatat cost_per_unit landed = 6000
        $card = StockCard::where('transaction_id', $po->id)->where('transaction_type', 'PURCHASE')->first();
        $this->assertSame('6000.0000', (string) $card->cost_per_unit);
        $this->assertSame('5000.0000', (string) $card->avg_cost_before);
        $this->assertSame('5166.6667', (string) $card->avg_cost_after);
    }
    #[Test]
    public function approve_po_hutang_dan_grand_total_eksak_dengan_pajak()
    {
        // PPN 11%: 10×6000=60000 → pajak 6600 → grand_total 66600 → hutang 66600.
        SettingService::set('tax.tax_purchase_percent', 11, 'integer');
        $this->seedOpeningBalanceWithStockCard(50, 5000);

        $po = $this->createDraftPo(6000, 10);
        $this->assertEquals(66600, $po->grand_total, '60000 + PPN 6600');

        $this->approveAction->execute($po);

        $hutang = SupplierHutang::where('po_id', $po->id)->first();
        $this->assertEquals(66600, $hutang->nominal_awal, 'hutang = grand_total termasuk pajak');
        $this->assertEquals(66600, $hutang->sisa_hutang);
        $this->assertEquals(0, $hutang->nominal_terbayar);
    }
    #[Test]
    public function approve_po_hpp_tetap_saat_avg_cost_awal_nol()
    {
        // Produk baru avg_cost 0, stok 0. PO 10@7000 → HPP jadi 7000 (bukan 3500).
        $this->seedOpeningBalanceWithStockCard(0, 0);

        $po = $this->createDraftPo(7000, 10);
        $this->approveAction->execute($po);

        $this->product->refresh();
        $this->assertSame('7000.0000', (string) $this->product->avg_cost, '(0*0 + 10*7000)/10 = 7000');

        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(10, $stock->qty);
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function approve_po_double_approve_tidak_menggandakan_stok_hutang_history()
    {
        $this->seedOpeningBalanceWithStockCard(50, 5000);
        $po = $this->createDraftPo(6000, 10);

        $this->approveAction->execute($po);

        $stockAfterFirst = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first()->qty;

        try {
            $this->approveAction->execute($po->fresh());
            $this->fail('Approve kedua seharusnya melempar ValidationException.');
        } catch (ValidationException $e) {
            // expected
        }

        // Stok, hutang, history, dan stock_card PURCHASE tetap satu kali.
        $this->assertEquals($stockAfterFirst, InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first()->qty);
        $this->assertEquals(1, SupplierHutang::where('po_id', $po->id)->count());
        $this->assertEquals(1, HistoryHargaBeli::where('po_id', $po->id)->count());
        $this->assertEquals(1, StockCard::where('transaction_id', $po->id)
            ->where('transaction_type', 'PURCHASE')->count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function approve_po_multi_baris_produk_sama_menjumlah_stok_dan_running_hpp()
    {
        // Dua baris produk sama dalam satu PO: 6 + 4 = 10 pcs masuk.
        $this->seedOpeningBalanceWithStockCard(50, 5000);

        $po = $this->createAction->execute([
            'tanggal_po' => '2026-04-12',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [
                ['product_id' => $this->product->id, 'unit_used' => 'PCS', 'unit_konversi' => 1, 'qty_in_unit' => 6, 'harga_per_unit' => 6000],
                ['product_id' => $this->product->id, 'unit_used' => 'PCS', 'unit_konversi' => 1, 'qty_in_unit' => 4, 'harga_per_unit' => 6000],
            ],
        ]);

        $this->approveAction->execute($po);

        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(60, $stock->qty, 'saldo 50 + 6 + 4');

        // Dua entri stock_card PURCHASE untuk PO ini (satu per baris).
        $this->assertEquals(2, StockCard::where('transaction_id', $po->id)
            ->where('transaction_type', 'PURCHASE')->count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function approve_po_throws_when_status_bukan_draft_pesan_eksak()
    {
        $po = $this->createDraftPo();
        $this->approveAction->execute($po);

        try {
            $this->approveAction->execute($po->fresh());
            $this->fail('Seharusnya melempar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
            $this->assertEquals(
                'Hanya PO dengan status draft yang dapat disetujui.',
                $e->errors()['status'][0]
            );
        }
    }
}
