<?php

namespace Tests\Unit\Models;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class MasterProdukHppResetTest extends TestCase
{
    use RefreshDatabase;

    protected MasterProduk $product;
    protected MasterWarehouse $warehouse;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create user for auth
        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Create warehouse
        $this->warehouse = MasterWarehouse::factory()->create([
            'status' => 'active',
        ]);

        // Create product with initial HPP
        $this->product = MasterProduk::factory()->create([
            'avg_cost' => 10000,
            'status' => 'active',
        ]);
    }

    /**
     * Set qty inventory_stock + buat stock_card padanan (ADJUSTMENT_IN/OUT) supaya
     * invariant data:verify (SUM(qty_in-qty_out) == inventory_stock.qty) tetap konsisten.
     * Skip observer agar tidak ada entri ganda. Selisih dari saldo terakhir dipakai
     * sebagai qty_in/qty_out.
     */
    private function seedStock(MasterWarehouse $warehouse, int $qty, float $avgCost = 10000): void
    {
        StockCard::$skipObserver = true;

        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $warehouse->id)
            ->first();
        $current = $stock ? (int) $stock->qty : 0;

        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => $qty, 'avg_cost' => $avgCost]
        );

        $diff = $qty - $current;
        if ($diff !== 0) {
            StockCard::record([
                'product_id' => $this->product->id,
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

    /** Helper: pastikan invariant data global konsisten (exit code 0). */
    private function assertDataVerifyPasses(): void
    {
        $this->assertSame(
            0,
            Artisan::call('data:verify', ['--fail-on-mismatch' => true]),
            "data:verify --fail-on-mismatch harus 0 (invariant stok/HPP konsisten):\n" . Artisan::output()
        );
    }
    #[Test]
    public function it_resets_hpp_when_global_stock_becomes_zero()
    {
        // Setup: Update inventory stock with qty = 0 (observer may have created it)
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 10000]
        );

        // Act: Call checkAndResetHppIfStockEmpty
        $result = $this->product->checkAndResetHppIfStockEmpty(
            $this->warehouse->id,
            123,
            'TEST-001',
            now()
        );

        // Assert: HPP should be reset
        $this->assertTrue($result);
        $this->product->refresh();
        $this->assertEquals(0, $this->product->avg_cost);

        // Assert: Stock card HPP_RESET entry should be created
        $hppResetEntry = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')
            ->first();

        $this->assertNotNull($hppResetEntry);
        $this->assertEquals(0, $hppResetEntry->qty_in);
        $this->assertEquals(0, $hppResetEntry->qty_out);
        $this->assertEquals(10000, $hppResetEntry->avg_cost_before);
        $this->assertEquals(0, $hppResetEntry->avg_cost_after);
        $this->assertEquals('Auto Reset HPP (Stock Kosong)', $hppResetEntry->notes);
        $this->assertEquals('TEST-001', $hppResetEntry->transaction_no);
    }
    #[Test]
    public function it_does_not_reset_hpp_when_global_stock_is_positive()
    {
        // Setup: Update inventory stock with positive qty
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 10000]
        );

        // Act
        $result = $this->product->checkAndResetHppIfStockEmpty(
            $this->warehouse->id,
            123,
            'TEST-001',
            now()
        );

        // Assert: HPP should NOT be reset
        $this->assertFalse($result);
        $this->product->refresh();
        $this->assertEquals(10000, $this->product->avg_cost);

        // Assert: No HPP_RESET entry
        $hppResetEntry = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')
            ->first();
        $this->assertNull($hppResetEntry);
    }
    #[Test]
    public function it_does_not_reset_hpp_when_already_zero()
    {
        // Setup: Product with HPP already 0
        $this->product->update(['avg_cost' => 0]);

        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );

        // Act
        $result = $this->product->checkAndResetHppIfStockEmpty(
            $this->warehouse->id,
            123,
            'TEST-001',
            now()
        );

        // Assert: Should return false (no reset needed)
        $this->assertFalse($result);

        // Assert: No HPP_RESET entry created
        $hppResetEntry = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')
            ->first();
        $this->assertNull($hppResetEntry);
    }
    #[Test]
    public function it_resets_hpp_when_stock_is_negative()
    {
        // Setup: Update inventory stock with negative qty (allowed by settings)
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => -5, 'avg_cost' => 10000]
        );

        // Act
        $result = $this->product->checkAndResetHppIfStockEmpty(
            $this->warehouse->id,
            123,
            'TEST-001',
            now()
        );

        // Assert: HPP should be reset (negative stock counts as "empty")
        $this->assertTrue($result);
        $this->product->refresh();
        $this->assertEquals(0, $this->product->avg_cost);
    }
    #[Test]
    public function it_considers_global_stock_across_multiple_warehouses()
    {
        // Setup: Create second warehouse
        $warehouse2 = MasterWarehouse::factory()->create(['status' => 'active']);

        // Warehouse 1: 0 qty (observer may have created it)
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 10000]
        );

        // Warehouse 2: 10 qty (observer creates when warehouse is created and product is active)
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $warehouse2->id],
            ['qty' => 10, 'avg_cost' => 10000]
        );

        // Act: Check from warehouse 1 (which has 0 stock)
        $result = $this->product->checkAndResetHppIfStockEmpty(
            $this->warehouse->id,
            123,
            'TEST-001',
            now()
        );

        // Assert: HPP should NOT be reset because global stock = 10
        $this->assertFalse($result);
        $this->product->refresh();
        $this->assertEquals(10000, $this->product->avg_cost);
    }
    #[Test]
    public function it_syncs_avg_cost_to_all_inventory_stocks_on_reset()
    {
        // Setup: Create multiple warehouses with stock
        $warehouse2 = MasterWarehouse::factory()->create(['status' => 'active']);

        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 10000]
        );

        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $warehouse2->id],
            ['qty' => 0, 'avg_cost' => 10000]
        );

        // Act
        $result = $this->product->checkAndResetHppIfStockEmpty(
            $this->warehouse->id,
            123,
            'TEST-001',
            now()
        );

        // Assert: Both inventory_stocks should have avg_cost = 0
        $this->assertTrue($result);

        $stock1 = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $stock2 = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $warehouse2->id)
            ->first();

        $this->assertEquals(0, $stock1->avg_cost);
        $this->assertEquals(0, $stock2->avg_cost);
    }

    // ==================== EDGE CASE: HPP_RESET eksak + invariant data ====================
    #[Test]
    public function reset_hpp_membuat_entri_hpp_reset_eksak_dan_invariant_data_konsisten()
    {
        // Seed stok 5 dulu (dengan stock_card padanan), lalu turunkan ke 0.
        $this->seedStock($this->warehouse, 5, 10000);
        $this->seedStock($this->warehouse, 0, 10000); // ADJUSTMENT_OUT 5 → saldo 0

        // Sanity: stok global = 0
        $this->assertSame(0, (int) InventoryStock::where('product_id', $this->product->id)->sum('qty'));

        // Act
        $result = $this->product->checkAndResetHppIfStockEmpty(
            $this->warehouse->id,
            999,
            'RESET-EKSAK-01',
            now()
        );

        // Assert: reset terjadi
        $this->assertTrue($result);
        $this->product->refresh();
        $this->assertEquals(0, $this->product->avg_cost);

        // Assert: entri HPP_RESET eksak
        $reset = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')
            ->first();
        $this->assertNotNull($reset);
        $this->assertEquals(0, $reset->qty_in);
        $this->assertEquals(0, $reset->qty_out);
        $this->assertEquals(0, $reset->qty_balance);          // tipe NO_QTY → balance 0
        $this->assertEquals(0, $reset->cost_per_unit);
        $this->assertEquals(0, $reset->total_cost);
        $this->assertEquals(10000, $reset->avg_cost_before);  // before EKSAK
        $this->assertEquals(0, $reset->avg_cost_after);        // after EKSAK
        $this->assertEquals(999, $reset->transaction_id);
        $this->assertEquals('RESET-EKSAK-01', $reset->transaction_no);
        $this->assertEquals('Auto Reset HPP (Stock Kosong)', $reset->notes);
        $this->assertEquals($this->warehouse->id, $reset->warehouse_id);

        // Assert: invariant global tetap konsisten (HPP_RESET qty=0 tidak ganggu saldo)
        $this->assertDataVerifyPasses();
    }
    #[Test]
    public function reset_hanya_membuat_tepat_satu_entri_hpp_reset_meski_dipanggil_dua_kali()
    {
        // Panggilan pertama mereset HPP→0, panggilan kedua sudah avg=0 → tidak buat entri lagi.
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 10000]
        );

        $first = $this->product->checkAndResetHppIfStockEmpty($this->warehouse->id, 1, 'X1', now());
        $this->product->refresh();
        $second = $this->product->checkAndResetHppIfStockEmpty($this->warehouse->id, 2, 'X2', now());

        $this->assertTrue($first);
        $this->assertFalse($second); // sudah 0 → guard "avg_cost > 0" gagal

        $count = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')
            ->count();
        $this->assertEquals(1, $count);
    }

    // ==================== EDGE CASE: recalculateAvgCost weighted-average eksak ====================
    #[Test]
    public function recalculate_avg_cost_weighted_average_eksak()
    {
        // current: 10 pcs @ 10000 (dari setUp). Tambah 30 pcs @ 20000.
        // Avg = (10*10000 + 30*20000) / 40 = (100000 + 600000)/40 = 700000/40 = 17500
        $this->seedStock($this->warehouse, 10, 10000);

        $new = $this->product->recalculateAvgCost(30, 20000);

        $this->assertEquals(17500, $new);
        $this->product->refresh();
        $this->assertEquals(17500, $this->product->avg_cost);
    }
    #[Test]
    public function recalculate_avg_cost_dari_nol_stok_menghasilkan_cost_baru_penuh()
    {
        // Stok awal 0, avg awal 0 → tambah 8 @ 12500 → avg = 12500 persis.
        $this->product->update(['avg_cost' => 0]);
        $this->product->refresh();
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );

        $new = $this->product->recalculateAvgCost(8, 12500);

        $this->assertEquals(12500, $new);
        $this->product->refresh();
        $this->assertEquals(12500, $this->product->avg_cost);
    }
    #[Test]
    public function recalculate_avg_cost_pecahan_dihitung_presisi()
    {
        // current 3 @ 10000, tambah 4 @ 17500.
        // (3*10000 + 4*17500)/7 = (30000 + 70000)/7 = 100000/7 = 14285.7142857...
        // avg_cost di-cast decimal:4 → 14285.7143
        $this->seedStock($this->warehouse, 3, 10000);

        $this->product->recalculateAvgCost(4, 17500);
        $this->product->refresh();

        $this->assertEquals(14285.7143, round((float) $this->product->avg_cost, 4));
    }
    #[Test]
    public function recalculate_avg_cost_guard_division_by_zero_menjaga_avg_lama()
    {
        // Stok global 0 (totalQty = 0 + 0). Tambah qty 0 → totalQty <= 0 → kembalikan avg lama.
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 10000]
        );

        $new = $this->product->recalculateAvgCost(0, 99999);

        // Guard: totalQty <= 0 → avg lama (10000) dipertahankan, cost baru DIABAIKAN.
        $this->assertEquals(10000, $new);
        $this->product->refresh();
        $this->assertEquals(10000, $this->product->avg_cost);
    }
    #[Test]
    public function recalculate_avg_cost_guard_saat_qty_negatif_total_nol()
    {
        // current stok 5 @ 10000, kurangi 5 (newQty = -5) → totalQty = 0 → guard aktif.
        $this->seedStock($this->warehouse, 5, 10000);

        $new = $this->product->recalculateAvgCost(-5, 50000);

        $this->assertEquals(10000, $new); // avg lama dipertahankan
        $this->product->refresh();
        $this->assertEquals(10000, $this->product->avg_cost);
    }
    #[Test]
    public function recalculate_avg_cost_lintas_gudang_pakai_total_stok_global()
    {
        // Weighted average pakai total_stock GLOBAL (semua gudang), bukan per-gudang.
        $warehouse2 = MasterWarehouse::factory()->create(['status' => 'active']);
        $this->seedStock($this->warehouse, 4, 10000);
        $this->seedStock($warehouse2, 6, 10000); // global = 10 @ 10000

        // Tambah 10 @ 14000 → (10*10000 + 10*14000)/20 = 240000/20 = 12000
        $new = $this->product->recalculateAvgCost(10, 14000);

        $this->assertEquals(12000, $new);
    }
}
