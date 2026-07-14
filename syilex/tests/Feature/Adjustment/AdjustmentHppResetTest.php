<?php

namespace Tests\Feature\Adjustment;

use App\Actions\Adjustment\ApproveAdjustmentAction;
use App\Models\DocAdjustment;
use App\Models\DocAdjustmentDetail;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AdjustmentHppResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'adjustment.view']);
        Permission::create(['name' => 'adjustment.create']);
        Permission::create(['name' => 'adjustment.approve']);

        // Create role with permissions
        $role = Role::create(['name' => 'admin']);
        $role->givePermissionTo(['adjustment.view', 'adjustment.create', 'adjustment.approve']);

        // Create user
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        $this->actingAs($this->user);

        // Create warehouse
        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        // Create product with initial HPP
        $this->product = MasterProduk::factory()->create([
            'avg_cost' => 15000,
            'status' => 'active',
        ]);
    }

    /**
     * Seed stok awal + stock_card padanan (ADJUSTMENT_IN/OUT) supaya invariant
     * data:verify (SUM(qty_in-qty_out) per gudang == inventory_stock.qty) konsisten.
     */
    private function seedStock(MasterProduk $product, MasterWarehouse $warehouse, int $qty, float $avgCost): void
    {
        StockCard::$skipObserver = true;

        $stock = InventoryStock::where('product_id', $product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
        $current = $stock ? (int) $stock->qty : 0;

        InventoryStock::updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => $qty, 'avg_cost' => $avgCost]
        );

        $diff = $qty - $current;
        if ($diff !== 0) {
            StockCard::record([
                'product_id' => $product->id,
                'warehouse_id' => $warehouse->id,
                'transaction_type' => $diff > 0 ? 'ADJUSTMENT_IN' : 'ADJUSTMENT_OUT',
                'tanggal' => now(),
                'qty_in' => $diff > 0 ? $diff : 0,
                'qty_out' => $diff < 0 ? abs($diff) : 0,
                'cost_per_unit' => $avgCost,
                'avg_cost_before' => $avgCost,
                'avg_cost_after' => $avgCost,
                'notes' => 'Seed stok test',
            ]);
        }

        StockCard::$skipObserver = false;
    }

    private function assertDataVerifyPasses(): void
    {
        $this->assertSame(
            0,
            Artisan::call('data:verify', ['--fail-on-mismatch' => true]),
            "data:verify --fail-on-mismatch harus 0:\n" . Artisan::output()
        );
    }
    #[Test]
    public function adjustment_out_triggers_hpp_reset_when_stock_becomes_zero()
    {
        // Setup: Initial stock = 10 (skip observer to prevent extra stock cards)
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 15000]
        );
        StockCard::$skipObserver = false;

        // Create adjustment OUT for all stock (10 pcs)
        $adjustment = DocAdjustment::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'ADJ-TEST-001',
            'tanggal' => now(),
            'keterangan' => 'Test adjustment',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocAdjustmentDetail::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'jenis' => 'kredit', // OUT
            'stok_sistem' => 10,
            'qty' => 10, // All stock
            'stok_akhir' => 0,
            'notes' => 'Test rusak',
        ]);

        // Act: Approve adjustment
        $action = new ApproveAdjustmentAction();
        $result = $action->execute($adjustment);

        // Assert: Adjustment approved
        $this->assertEquals('approved', $result->status);

        // Assert: Stock should be 0
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(0, $stock->qty);

        // Assert: Product HPP should be 0
        $this->product->refresh();
        $this->assertEquals(0, $this->product->avg_cost);

        // Assert: Two stock card entries created
        $stockCards = StockCard::where('product_id', $this->product->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $stockCards);

        // First entry: ADJUSTMENT_OUT
        $adjustmentEntry = $stockCards[0];
        $this->assertEquals('ADJUSTMENT_OUT', $adjustmentEntry->transaction_type);
        $this->assertEquals(10, $adjustmentEntry->qty_out);
        $this->assertEquals(15000, $adjustmentEntry->avg_cost_before);
        $this->assertEquals(15000, $adjustmentEntry->avg_cost_after); // HPP unchanged in OUT

        // Second entry: HPP_RESET
        $resetEntry = $stockCards[1];
        $this->assertEquals('HPP_RESET', $resetEntry->transaction_type);
        $this->assertEquals(0, $resetEntry->qty_in);
        $this->assertEquals(0, $resetEntry->qty_out);
        $this->assertEquals(15000, $resetEntry->avg_cost_before);
        $this->assertEquals(0, $resetEntry->avg_cost_after);
        $this->assertEquals('Auto Reset HPP (Stock Kosong)', $resetEntry->notes);
    }
    #[Test]
    public function adjustment_out_does_not_trigger_hpp_reset_when_stock_remains_positive()
    {
        // Setup: Initial stock = 20 (skip observer to prevent extra stock cards)
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 20, 'avg_cost' => 15000]
        );
        StockCard::$skipObserver = false;

        // Create adjustment OUT for partial stock (5 pcs)
        $adjustment = DocAdjustment::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'ADJ-TEST-002',
            'tanggal' => now(),
            'keterangan' => 'Test partial adjustment',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocAdjustmentDetail::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'jenis' => 'kredit',
            'stok_sistem' => 20,
            'qty' => 5, // Partial
            'stok_akhir' => 15,
            'notes' => 'Test',
        ]);

        // Act
        $action = new ApproveAdjustmentAction();
        $result = $action->execute($adjustment);

        // Assert: Stock should be 15
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(15, $stock->qty);

        // Assert: HPP should remain unchanged
        $this->product->refresh();
        $this->assertEquals(15000, $this->product->avg_cost);

        // Assert: Only one stock card entry (no HPP_RESET)
        $stockCards = StockCard::where('product_id', $this->product->id)->get();
        $this->assertCount(1, $stockCards);
        $this->assertEquals('ADJUSTMENT_OUT', $stockCards[0]->transaction_type);
    }
    #[Test]
    public function adjustment_in_does_not_trigger_hpp_reset()
    {
        // Setup: Initial stock = 0 (skip observer to prevent extra stock cards)
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 15000]
        );
        StockCard::$skipObserver = false;

        // Create adjustment IN
        $adjustment = DocAdjustment::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'ADJ-TEST-003',
            'tanggal' => now(),
            'keterangan' => 'Test adjustment in',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocAdjustmentDetail::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'jenis' => 'debit', // IN
            'stok_sistem' => 0,
            'qty' => 10,
            'stok_akhir' => 10,
            'notes' => 'Koreksi masuk',
        ]);

        // Act
        $action = new ApproveAdjustmentAction();
        $result = $action->execute($adjustment);

        // Assert: Stock should be 10
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(10, $stock->qty);

        // Assert: No HPP_RESET entry (IN doesn't trigger reset)
        $resetEntry = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')
            ->first();
        $this->assertNull($resetEntry);
    }
    #[Test]
    public function adjustment_out_hpp_unchanged_in_stock_card()
    {
        // Setup: Initial stock = 10 (skip observer to prevent extra stock cards)
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 15000]
        );
        StockCard::$skipObserver = false;

        // Create adjustment OUT for partial stock
        $adjustment = DocAdjustment::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'ADJ-TEST-004',
            'tanggal' => now(),
            'keterangan' => 'Test',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocAdjustmentDetail::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'jenis' => 'kredit',
            'stok_sistem' => 10,
            'qty' => 3,
            'stok_akhir' => 7,
            'notes' => 'Test hilang',
        ]);

        // Act
        $action = new ApproveAdjustmentAction();
        $action->execute($adjustment);

        // Assert: In ADJUSTMENT_OUT, avg_cost_before == avg_cost_after (HPP tidak berubah)
        $stockCard = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'ADJUSTMENT_OUT')
            ->first();

        $this->assertEquals(15000, $stockCard->avg_cost_before);
        $this->assertEquals(15000, $stockCard->avg_cost_after);
    }

    // ==================== EDGE CASE GALAK ====================
    #[Test]
    public function adjustment_out_habis_total_reset_hpp_dan_invariant_data_konsisten()
    {
        // Seed stok 10 dengan stock_card padanan supaya data:verify valid.
        $this->seedStock($this->product, $this->warehouse, 10, 15000);

        $adjustment = DocAdjustment::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'ADJ-VERIFY-001',
            'tanggal' => now(),
            'keterangan' => 'Habis total',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocAdjustmentDetail::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'jenis' => 'kredit',
            'stok_sistem' => 10,
            'qty' => 10,
            'stok_akhir' => 0,
            'notes' => 'Rusak semua',
        ]);

        (new ApproveAdjustmentAction())->execute($adjustment);

        // Stok 0, HPP 0
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(0, $stock->qty);
        $this->product->refresh();
        $this->assertEquals(0, $this->product->avg_cost);

        // HPP_RESET: before 15000, after 0, qty 0, balance 0
        $reset = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')->first();
        $this->assertNotNull($reset);
        $this->assertEquals(15000, $reset->avg_cost_before);
        $this->assertEquals(0, $reset->avg_cost_after);
        $this->assertEquals(0, $reset->qty_in);
        $this->assertEquals(0, $reset->qty_out);
        $this->assertEquals($adjustment->id, $reset->transaction_id);
        $this->assertEquals('ADJ-VERIFY-001', $reset->transaction_no);

        // INVARIANT: qty_in-qty_out per gudang == inventory_stock.qty
        // (seed +10, ADJUSTMENT_OUT -10, HPP_RESET 0 → net 0 == stok 0)
        $this->assertDataVerifyPasses();
    }
    #[Test]
    public function adjustment_in_merekalkulasi_hpp_weighted_average_eksak()
    {
        // §2B: ADJUSTMENT_IN memicu recalc HPP weighted-average.
        // Seed stok 10 @ 15000. Adjustment IN 30 pcs.
        // recalculateAvgCost dipanggil dengan cost = oldHpp (15000) → avg tetap 15000.
        // Untuk memastikan FORMULA jalan (bukan kebetulan), kita pakai produk dengan
        // avg & cost beda lewat skenario terpisah di test berikutnya. Di sini cek IN tidak reset
        // dan HPP after == weighted avg dari (oldQty*oldAvg + addQty*oldAvg)/(total) == oldAvg.
        $this->seedStock($this->product, $this->warehouse, 10, 15000);

        $adjustment = DocAdjustment::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'ADJ-IN-RECALC',
            'tanggal' => now(),
            'keterangan' => 'Koreksi masuk',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocAdjustmentDetail::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'jenis' => 'debit',
            'stok_sistem' => 10,
            'qty' => 30,
            'stok_akhir' => 40,
            'notes' => 'Tambah',
        ]);

        (new ApproveAdjustmentAction())->execute($adjustment);

        // Stok 40
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(40, $stock->qty);

        // HPP after = (10*15000 + 30*15000)/40 = 15000 (cost_per_unit IN = oldHpp by design)
        $inCard = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'ADJUSTMENT_IN')
            ->where('transaction_id', $adjustment->id)
            ->first();
        $this->assertNotNull($inCard);
        $this->assertEquals(15000, $inCard->avg_cost_before);
        $this->assertEquals(15000, $inCard->avg_cost_after);
        $this->assertEquals(30, $inCard->qty_in);
        $this->assertEquals(0, $inCard->qty_out);

        // Tidak ada HPP_RESET (stok positif)
        $this->assertNull(
            StockCard::where('product_id', $this->product->id)
                ->where('transaction_type', 'HPP_RESET')->first()
        );

        $this->assertDataVerifyPasses();
    }
    #[Test]
    public function adjustment_out_di_satu_gudang_tidak_reset_jika_gudang_lain_masih_isi()
    {
        // Stok global tetap > 0 → TIDAK reset meski gudang asal jadi 0.
        $warehouse2 = MasterWarehouse::factory()->create(['status' => 'active']);
        $this->seedStock($this->product, $this->warehouse, 5, 15000);
        $this->seedStock($this->product, $warehouse2, 8, 15000); // global 13

        $adjustment = DocAdjustment::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'ADJ-MULTIWH',
            'tanggal' => now(),
            'keterangan' => 'Kosongkan gudang 1',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocAdjustmentDetail::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'jenis' => 'kredit',
            'stok_sistem' => 5,
            'qty' => 5,
            'stok_akhir' => 0,
            'notes' => 'Habiskan gudang 1',
        ]);

        (new ApproveAdjustmentAction())->execute($adjustment);

        // Gudang 1 = 0, global = 8 → HPP TIDAK reset
        $this->product->refresh();
        $this->assertEquals(15000, $this->product->avg_cost);
        $this->assertEquals(8, (int) InventoryStock::where('product_id', $this->product->id)->sum('qty'));

        $this->assertNull(
            StockCard::where('product_id', $this->product->id)
                ->where('transaction_type', 'HPP_RESET')->first()
        );

        $this->assertDataVerifyPasses();
    }
    #[Test]
    public function adjustment_out_balance_stock_card_eksak_dan_invariant_konsisten_partial()
    {
        // Partial OUT: stok 20 → 12 (qty 8). qty_balance EKSAK = 12.
        $this->seedStock($this->product, $this->warehouse, 20, 15000);

        $adjustment = DocAdjustment::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'ADJ-BAL-01',
            'tanggal' => now(),
            'keterangan' => 'Partial',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocAdjustmentDetail::create([
            'adjustment_id' => $adjustment->id,
            'product_id' => $this->product->id,
            'jenis' => 'kredit',
            'stok_sistem' => 20,
            'qty' => 8,
            'stok_akhir' => 12,
            'notes' => 'Hilang 8',
        ]);

        (new ApproveAdjustmentAction())->execute($adjustment);

        $outCard = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'ADJUSTMENT_OUT')
            ->where('transaction_id', $adjustment->id)
            ->first();
        $this->assertNotNull($outCard);
        $this->assertEquals(8, $outCard->qty_out);
        $this->assertEquals(12, $outCard->qty_balance); // saldo running EKSAK

        $this->assertDataVerifyPasses();
    }
}
