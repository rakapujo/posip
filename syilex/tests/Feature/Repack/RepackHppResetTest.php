<?php

namespace Tests\Feature\Repack;

use App\Actions\Repack\ApproveRepackAction;
use App\Models\DocRepack;
use App\Models\DocRepackInput;
use App\Models\DocRepackOutput;
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

class RepackHppResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterProduk $inputProduct;
    protected MasterProduk $outputProduct;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'repack.view']);
        Permission::create(['name' => 'repack.create']);
        Permission::create(['name' => 'repack.approve']);

        // Create role with permissions
        $role = Role::create(['name' => 'admin']);
        $role->givePermissionTo(['repack.view', 'repack.create', 'repack.approve']);

        // Create user
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        $this->actingAs($this->user);

        // Create warehouse
        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        // Create input product (bahan)
        $this->inputProduct = MasterProduk::factory()->create([
            'kode_produk' => 'INPUT-001',
            'nama_produk' => 'Bahan Input',
            'avg_cost' => 10000,
            'status' => 'active',
        ]);

        // Create output product (hasil)
        $this->outputProduct = MasterProduk::factory()->create([
            'kode_produk' => 'OUTPUT-001',
            'nama_produk' => 'Hasil Output',
            'avg_cost' => 5000,
            'status' => 'active',
        ]);
    }

    /**
     * Seed stok awal + stock_card padanan supaya invariant data:verify konsisten.
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
    public function repack_out_triggers_hpp_reset_when_input_stock_becomes_zero()
    {
        // Setup: Input product stock = 10 (will be depleted) - skip observer
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->inputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 10000]
        );

        // Output product stock = 0
        InventoryStock::updateOrCreate(
            ['product_id' => $this->outputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 5000]
        );
        StockCard::$skipObserver = false;

        // Create repack: 10 input -> 20 output (pecah)
        $repack = DocRepack::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'RPK-TEST-001',
            'tipe' => 'pecah',
            'tanggal' => now(),
            'biaya_repack' => 0,
            'notes' => 'Test repack',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocRepackInput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->inputProduct->id,
            'qty' => 10, // All stock
        ]);

        DocRepackOutput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->outputProduct->id,
            'qty' => 20,
        ]);

        // Act: Approve repack
        $action = new ApproveRepackAction();
        $result = $action->execute($repack);

        // Assert: Repack approved
        $this->assertEquals('approved', $result->status);

        // Assert: Input stock should be 0
        $inputStock = InventoryStock::where('product_id', $this->inputProduct->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(0, $inputStock->qty);

        // Assert: Input product HPP should be 0
        $this->inputProduct->refresh();
        $this->assertEquals(0, $this->inputProduct->avg_cost);

        // Assert: HPP_RESET entry created for input product
        $resetEntry = StockCard::where('product_id', $this->inputProduct->id)
            ->where('transaction_type', 'HPP_RESET')
            ->first();

        $this->assertNotNull($resetEntry);
        $this->assertEquals(10000, $resetEntry->avg_cost_before);
        $this->assertEquals(0, $resetEntry->avg_cost_after);
        $this->assertEquals('Auto Reset HPP (Stock Kosong)', $resetEntry->notes);
    }
    #[Test]
    public function repack_creates_correct_stock_card_entries_sequence()
    {
        // Setup - skip observer
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->inputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 5, 'avg_cost' => 10000]
        );

        // Output product starts with 0 stock and 0 HPP
        $this->outputProduct->update(['avg_cost' => 0]);
        InventoryStock::updateOrCreate(
            ['product_id' => $this->outputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        StockCard::$skipObserver = false;

        $repack = DocRepack::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'RPK-TEST-002',
            'tipe' => 'pecah',
            'tanggal' => now(),
            'biaya_repack' => 5000, // Tambahan biaya
            'notes' => 'Test',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocRepackInput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->inputProduct->id,
            'qty' => 5, // All stock
        ]);

        DocRepackOutput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->outputProduct->id,
            'qty' => 10,
        ]);

        // Act
        $action = new ApproveRepackAction();
        $action->execute($repack);

        // Assert: Check stock cards for input product
        $inputStockCards = StockCard::where('product_id', $this->inputProduct->id)
            ->orderBy('id')
            ->get();

        // Should have: REPACK_OUT + HPP_RESET
        $this->assertCount(2, $inputStockCards);
        $this->assertEquals('REPACK_OUT', $inputStockCards[0]->transaction_type);
        $this->assertEquals('HPP_RESET', $inputStockCards[1]->transaction_type);

        // Assert: REPACK_OUT has correct avg_cost_before
        $this->assertEquals(10000, $inputStockCards[0]->avg_cost_before);
        $this->assertEquals(10000, $inputStockCards[0]->avg_cost_after); // HPP unchanged for OUT

        // Assert: Check stock card for output product
        $outputStockCards = StockCard::where('product_id', $this->outputProduct->id)
            ->orderBy('id')
            ->get();

        $this->assertCount(1, $outputStockCards);
        $this->assertEquals('REPACK_IN', $outputStockCards[0]->transaction_type);

        // Calculate expected HPP: (5 * 10000 + 5000) / 10 = 5500
        $expectedHpp = (5 * 10000 + 5000) / 10;
        $this->assertEquals(0, $outputStockCards[0]->avg_cost_before); // Was 0 before
        $this->assertEquals($expectedHpp, $outputStockCards[0]->avg_cost_after);
    }
    #[Test]
    public function repack_out_does_not_trigger_reset_when_stock_remains_positive()
    {
        // Setup: Input product has more stock than being used - skip observer
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->inputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 20, 'avg_cost' => 10000]
        );

        InventoryStock::updateOrCreate(
            ['product_id' => $this->outputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        StockCard::$skipObserver = false;

        $repack = DocRepack::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'RPK-TEST-003',
            'tipe' => 'pecah',
            'tanggal' => now(),
            'biaya_repack' => 0,
            'notes' => 'Test',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocRepackInput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->inputProduct->id,
            'qty' => 5, // Only use 5 of 20
        ]);

        DocRepackOutput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->outputProduct->id,
            'qty' => 10,
        ]);

        // Act
        $action = new ApproveRepackAction();
        $action->execute($repack);

        // Assert: Input stock should be 15
        $inputStock = InventoryStock::where('product_id', $this->inputProduct->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(15, $inputStock->qty);

        // Assert: Input HPP should remain unchanged
        $this->inputProduct->refresh();
        $this->assertEquals(10000, $this->inputProduct->avg_cost);

        // Assert: No HPP_RESET for input
        $resetEntry = StockCard::where('product_id', $this->inputProduct->id)
            ->where('transaction_type', 'HPP_RESET')
            ->first();
        $this->assertNull($resetEntry);
    }
    #[Test]
    public function repack_hpp_calculation_is_correct()
    {
        // Setup - skip observer
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->inputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 8000]
        );

        $this->inputProduct->update(['avg_cost' => 8000]);

        // Output product starts with existing stock and HPP
        InventoryStock::updateOrCreate(
            ['product_id' => $this->outputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 5, 'avg_cost' => 4000]
        );

        $this->outputProduct->update(['avg_cost' => 4000]);
        StockCard::$skipObserver = false;

        $repack = DocRepack::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'RPK-TEST-004',
            'tipe' => 'pecah',
            'tanggal' => now(),
            'biaya_repack' => 2000,
            'notes' => 'Test HPP calculation',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocRepackInput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->inputProduct->id,
            'qty' => 10,
        ]);

        DocRepackOutput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->outputProduct->id,
            'qty' => 20,
        ]);

        // Act
        $action = new ApproveRepackAction();
        $repack = $action->execute($repack);

        // Calculate expected:
        // Input cost: 10 * 8000 = 80000
        // Biaya repack: 2000
        // Total output cost: 82000
        // Output cost per unit: 82000 / 20 = 4100

        // New avg_cost for output:
        // Existing: 5 pcs @ 4000 = 20000
        // New: 20 pcs @ 4100 = 82000
        // Total: 25 pcs, 102000
        // Avg: 102000 / 25 = 4080

        $this->outputProduct->refresh();
        $this->assertEquals(4080, round($this->outputProduct->avg_cost));
    }

    // ==================== EDGE CASE GALAK ====================
    #[Test]
    public function repack_habis_input_reset_hpp_dengan_entri_eksak_dan_invariant_konsisten()
    {
        // Seed input 10 @ 10000 (dengan stock_card padanan). Output mulai 0 (tanpa card).
        $this->seedStock($this->inputProduct, $this->warehouse, 10, 10000);
        $this->outputProduct->update(['avg_cost' => 0]);
        InventoryStock::updateOrCreate(
            ['product_id' => $this->outputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );

        $repack = DocRepack::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'RPK-VERIFY-001',
            'tipe' => 'pecah',
            'tanggal' => now(),
            'biaya_repack' => 0,
            'notes' => 'Habis input',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        DocRepackInput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->inputProduct->id,
            'qty' => 10,
        ]);
        DocRepackOutput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->outputProduct->id,
            'qty' => 20,
        ]);

        (new ApproveRepackAction())->execute($repack);

        // Input habis → HPP_RESET eksak
        $this->inputProduct->refresh();
        $this->assertEquals(0, $this->inputProduct->avg_cost);
        $reset = StockCard::where('product_id', $this->inputProduct->id)
            ->where('transaction_type', 'HPP_RESET')->first();
        $this->assertNotNull($reset);
        $this->assertEquals(10000, $reset->avg_cost_before);
        $this->assertEquals(0, $reset->avg_cost_after);
        $this->assertEquals(0, $reset->qty_in);
        $this->assertEquals(0, $reset->qty_out);
        $this->assertEquals($repack->id, $reset->transaction_id);
        $this->assertEquals('RPK-VERIFY-001', $reset->transaction_no);

        // Output: HPP = (10*10000 + 0)/20 = 5000 EKSAK
        $this->outputProduct->refresh();
        $this->assertEquals(5000, $this->outputProduct->avg_cost);

        // Invariant: input net 0 == stok 0; output REPACK_IN 20 == stok 20
        $this->assertEquals(0, (int) InventoryStock::where('product_id', $this->inputProduct->id)->sum('qty'));
        $this->assertEquals(20, (int) InventoryStock::where('product_id', $this->outputProduct->id)->sum('qty'));
        $this->assertDataVerifyPasses();
    }
    #[Test]
    public function repack_tidak_reset_jika_input_punya_stok_di_gudang_lain()
    {
        // Input ada di 2 gudang. Repack di gudang utama menghabiskan stok gudang utama,
        // tapi global > 0 → TIDAK reset HPP.
        $warehouse2 = MasterWarehouse::factory()->create(['status' => 'active']);
        $this->seedStock($this->inputProduct, $this->warehouse, 5, 10000);
        $this->seedStock($this->inputProduct, $warehouse2, 7, 10000); // global 12

        $this->outputProduct->update(['avg_cost' => 0]);
        InventoryStock::updateOrCreate(
            ['product_id' => $this->outputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );

        $repack = DocRepack::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'RPK-MULTIWH',
            'tipe' => 'pecah',
            'tanggal' => now(),
            'biaya_repack' => 0,
            'notes' => 'Multi gudang',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        DocRepackInput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->inputProduct->id,
            'qty' => 5, // habiskan gudang utama
        ]);
        DocRepackOutput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->outputProduct->id,
            'qty' => 10,
        ]);

        (new ApproveRepackAction())->execute($repack);

        // Global input = 7 → HPP TIDAK reset
        $this->inputProduct->refresh();
        $this->assertEquals(10000, $this->inputProduct->avg_cost);
        $this->assertNull(
            StockCard::where('product_id', $this->inputProduct->id)
                ->where('transaction_type', 'HPP_RESET')->first()
        );
        $this->assertEquals(7, (int) InventoryStock::where('product_id', $this->inputProduct->id)->sum('qty'));

        $this->assertDataVerifyPasses();
    }
    #[Test]
    public function repack_dengan_biaya_repack_output_hpp_terdistribusi_eksak()
    {
        // Input 4 @ 10000 = 40000, biaya repack 8000 → total 48000.
        // Output dua produk: outputProduct 6 pcs + produk ketiga 4 pcs (total 10).
        // Per-unit cost = 48000/10 = 4800.
        // outputProduct mulai 0 → HPP after = 4800 EKSAK.
        $product3 = MasterProduk::factory()->create([
            'kode_produk' => 'OUTPUT-002',
            'nama_produk' => 'Hasil Output 2',
            'avg_cost' => 0,
            'status' => 'active',
        ]);

        $this->seedStock($this->inputProduct, $this->warehouse, 4, 10000);
        $this->outputProduct->update(['avg_cost' => 0]);
        InventoryStock::updateOrCreate(
            ['product_id' => $this->outputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        InventoryStock::updateOrCreate(
            ['product_id' => $product3->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );

        $repack = DocRepack::create([
            'warehouse_id' => $this->warehouse->id,
            'nomor_dokumen' => 'RPK-DIST',
            'tipe' => 'pecah',
            'tanggal' => now(),
            'biaya_repack' => 8000,
            'notes' => 'Distribusi biaya',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        DocRepackInput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->inputProduct->id,
            'qty' => 4,
        ]);
        DocRepackOutput::create([
            'repack_id' => $repack->id,
            'product_id' => $this->outputProduct->id,
            'qty' => 6,
        ]);
        DocRepackOutput::create([
            'repack_id' => $repack->id,
            'product_id' => $product3->id,
            'qty' => 4,
        ]);

        (new ApproveRepackAction())->execute($repack);

        // Per-unit cost = 4800 untuk kedua output (mulai 0)
        $this->outputProduct->refresh();
        $product3->refresh();
        $this->assertEquals(4800, $this->outputProduct->avg_cost);
        $this->assertEquals(4800, $product3->avg_cost);

        // REPACK_IN card eksak untuk outputProduct
        $inCard = StockCard::where('product_id', $this->outputProduct->id)
            ->where('transaction_type', 'REPACK_IN')->first();
        $this->assertEquals(6, $inCard->qty_in);
        $this->assertEquals(0, $inCard->avg_cost_before);
        $this->assertEquals(4800, $inCard->avg_cost_after);

        $this->assertDataVerifyPasses();
    }
}
