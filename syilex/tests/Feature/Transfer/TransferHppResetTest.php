<?php

namespace Tests\Feature\Transfer;

use App\Actions\Transfer\ApproveTransferAction;
use App\Models\DocTransfer;
use App\Models\DocTransferDetail;
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

class TransferHppResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouseFrom;
    protected MasterWarehouse $warehouseTo;
    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'transfer.view']);
        Permission::create(['name' => 'transfer.create']);
        Permission::create(['name' => 'transfer.approve']);

        // Create role with permissions
        $role = Role::create(['name' => 'admin']);
        $role->givePermissionTo(['transfer.view', 'transfer.create', 'transfer.approve']);

        // Create user
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');
        $this->actingAs($this->user);

        // Create warehouses
        $this->warehouseFrom = MasterWarehouse::factory()->create([
            'kode_warehouse' => 'WH-FROM',
            'nama_warehouse' => 'Gudang Asal',
            'status' => 'active',
        ]);

        $this->warehouseTo = MasterWarehouse::factory()->create([
            'kode_warehouse' => 'WH-TO',
            'nama_warehouse' => 'Gudang Tujuan',
            'status' => 'active',
        ]);

        // Create product
        $this->product = MasterProduk::factory()->create([
            'avg_cost' => 15000,
            'status' => 'active',
        ]);
    }

    /**
     * Seed stok awal + stock_card padanan supaya invariant data:verify konsisten.
     */
    private function seedStock(MasterWarehouse $warehouse, int $qty, float $avgCost): void
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

    private function assertDataVerifyPasses(): void
    {
        $this->assertSame(
            0,
            Artisan::call('data:verify', ['--fail-on-mismatch' => true]),
            "data:verify --fail-on-mismatch harus 0:\n" . Artisan::output()
        );
    }
    #[Test]
    public function transfer_does_not_trigger_hpp_reset_even_when_source_stock_becomes_zero()
    {
        // Setup: Source has 10, destination has 0
        // Transfer all 10 from source to destination
        // Source becomes 0, but HPP should NOT reset because global stock is still 10

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseFrom->id],
            ['qty' => 10, 'avg_cost' => 15000]
        );

        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseTo->id],
            ['qty' => 0, 'avg_cost' => 15000]
        );
        StockCard::$skipObserver = false;

        // Create transfer
        $transfer = DocTransfer::create([
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'nomor_dokumen' => 'TRF-TEST-001',
            'tanggal' => now(),
            'notes' => 'Test transfer',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocTransferDetail::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'qty' => 10, // All stock from source
        ]);

        // Act: Approve transfer
        $action = new ApproveTransferAction();
        $result = $action->execute($transfer);

        // Assert: Transfer approved
        $this->assertEquals('approved', $result->status);

        // Assert: Source stock = 0
        $stockFrom = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseFrom->id)
            ->first();
        $this->assertEquals(0, $stockFrom->qty);

        // Assert: Destination stock = 10
        $stockTo = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseTo->id)
            ->first();
        $this->assertEquals(10, $stockTo->qty);

        // Assert: HPP should NOT be reset (global stock is still 10)
        $this->product->refresh();
        $this->assertEquals(15000, $this->product->avg_cost);

        // Assert: NO HPP_RESET entry created
        $resetEntry = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')
            ->first();
        $this->assertNull($resetEntry);
    }
    #[Test]
    public function transfer_creates_correct_stock_card_entries()
    {
        // Setup
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseFrom->id],
            ['qty' => 20, 'avg_cost' => 15000]
        );

        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseTo->id],
            ['qty' => 5, 'avg_cost' => 15000]
        );
        StockCard::$skipObserver = false;

        // Create transfer
        $transfer = DocTransfer::create([
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'nomor_dokumen' => 'TRF-TEST-002',
            'tanggal' => now(),
            'notes' => 'Test transfer partial',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocTransferDetail::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'qty' => 8,
        ]);

        // Act
        $action = new ApproveTransferAction();
        $action->execute($transfer);

        // Assert: Source stock = 12
        $stockFrom = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseFrom->id)
            ->first();
        $this->assertEquals(12, $stockFrom->qty);

        // Assert: Destination stock = 13
        $stockTo = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseTo->id)
            ->first();
        $this->assertEquals(13, $stockTo->qty);

        // Assert: Check stock card for TRANSFER_OUT
        $outCard = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'TRANSFER_OUT')
            ->first();
        $this->assertNotNull($outCard);
        $this->assertEquals(8, $outCard->qty_out);
        $this->assertEquals(15000, $outCard->avg_cost_before);
        $this->assertEquals(15000, $outCard->avg_cost_after); // HPP tidak berubah

        // Assert: Check stock card for TRANSFER_IN
        $inCard = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'TRANSFER_IN')
            ->first();
        $this->assertNotNull($inCard);
        $this->assertEquals(8, $inCard->qty_in);
        $this->assertEquals(15000, $inCard->avg_cost_before);
        $this->assertEquals(15000, $inCard->avg_cost_after); // HPP tidak berubah
    }
    #[Test]
    public function transfer_hpp_remains_unchanged()
    {
        // Setup
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseFrom->id],
            ['qty' => 50, 'avg_cost' => 15000]
        );

        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseTo->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        StockCard::$skipObserver = false;

        $originalHpp = $this->product->avg_cost;

        // Create transfer
        $transfer = DocTransfer::create([
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'nomor_dokumen' => 'TRF-TEST-003',
            'tanggal' => now(),
            'notes' => 'Test HPP unchanged',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocTransferDetail::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'qty' => 30,
        ]);

        // Act
        $action = new ApproveTransferAction();
        $action->execute($transfer);

        // Assert: HPP remains the same
        $this->product->refresh();
        $this->assertEquals($originalHpp, $this->product->avg_cost);

        // Assert: Both stock cards have same HPP before and after
        $stockCards = StockCard::where('product_id', $this->product->id)->get();

        foreach ($stockCards as $card) {
            $this->assertEquals($card->avg_cost_before, $card->avg_cost_after);
        }
    }
    #[Test]
    public function transfer_global_stock_unchanged()
    {
        // Setup: Total global = 25 (15 + 10)
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseFrom->id],
            ['qty' => 15, 'avg_cost' => 15000]
        );

        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseTo->id],
            ['qty' => 10, 'avg_cost' => 15000]
        );
        StockCard::$skipObserver = false;

        $totalBefore = InventoryStock::where('product_id', $this->product->id)->sum('qty');
        $this->assertEquals(25, $totalBefore);

        // Create transfer: 15 from source to destination
        $transfer = DocTransfer::create([
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'nomor_dokumen' => 'TRF-TEST-004',
            'tanggal' => now(),
            'notes' => 'Test global stock',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        DocTransferDetail::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'qty' => 15, // Transfer all from source
        ]);

        // Act
        $action = new ApproveTransferAction();
        $action->execute($transfer);

        // Assert: Global stock unchanged
        $totalAfter = InventoryStock::where('product_id', $this->product->id)->sum('qty');
        $this->assertEquals(25, $totalAfter);

        // Assert: Source = 0, Destination = 25
        $stockFrom = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseFrom->id)
            ->first();
        $this->assertEquals(0, $stockFrom->qty);

        $stockTo = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseTo->id)
            ->first();
        $this->assertEquals(25, $stockTo->qty);

        // Assert: NO HPP_RESET (global stock > 0)
        $resetEntry = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')
            ->first();
        $this->assertNull($resetEntry);
    }

    // ==================== EDGE CASE GALAK ====================
    #[Test]
    public function transfer_habiskan_gudang_asal_tanpa_reset_dan_invariant_data_konsisten()
    {
        // Seed source 10 (dengan stock_card padanan), dest 0 (tanpa card).
        $this->seedStock($this->warehouseFrom, 10, 15000);

        $transfer = DocTransfer::create([
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'nomor_dokumen' => 'TRF-VERIFY-001',
            'tanggal' => now(),
            'notes' => 'Pindah semua',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        DocTransferDetail::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'qty' => 10,
        ]);

        (new ApproveTransferAction())->execute($transfer);

        // Source 0, dest 10, global tetap 10 → NO reset
        $this->assertEquals(0, InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseFrom->id)->first()->qty);
        $this->assertEquals(10, InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseTo->id)->first()->qty);
        $this->product->refresh();
        $this->assertEquals(15000, $this->product->avg_cost);
        $this->assertNull(
            StockCard::where('product_id', $this->product->id)
                ->where('transaction_type', 'HPP_RESET')->first()
        );

        // INVARIANT per gudang:
        //   from: ADJUSTMENT_IN 10 (seed) - TRANSFER_OUT 10 = 0 == stok 0
        //   to:   TRANSFER_IN 10 = 10 == stok 10
        $this->assertDataVerifyPasses();
    }
    #[Test]
    public function transfer_qty_balance_per_gudang_eksak()
    {
        // Source 20, dest 5. Transfer 8 → source balance 12, dest balance 13.
        $this->seedStock($this->warehouseFrom, 20, 15000);
        $this->seedStock($this->warehouseTo, 5, 15000);

        $transfer = DocTransfer::create([
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'nomor_dokumen' => 'TRF-BAL-01',
            'tanggal' => now(),
            'notes' => 'Partial',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        DocTransferDetail::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'qty' => 8,
        ]);

        (new ApproveTransferAction())->execute($transfer);

        $outCard = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'TRANSFER_OUT')->first();
        $inCard = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'TRANSFER_IN')->first();

        // qty_balance running per gudang EKSAK
        $this->assertEquals(12, $outCard->qty_balance); // 20 (seed balance) - 8
        $this->assertEquals(13, $inCard->qty_balance);  // 5 (seed balance) + 8
        $this->assertEquals(8, $outCard->qty_out);
        $this->assertEquals(8, $inCard->qty_in);

        $this->assertDataVerifyPasses();
    }
    #[Test]
    public function transfer_dengan_biaya_masuk_hpp_menaikkan_avg_dan_catat_hpp_correction_eksak()
    {
        // Source 10 @ 15000, dest 0. masuk_hpp=true, biaya_kirim=2000.
        // Setelah pindah: source 0, dest 10, qtyGlobal=10.
        // applyHppNonSerial: newAvg = 15000 + 2000/10 = 15200 EKSAK.
        $this->seedStock($this->warehouseFrom, 10, 15000);

        $transfer = DocTransfer::create([
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'nomor_dokumen' => 'TRF-HPP-01',
            'tanggal' => now(),
            'notes' => 'Biaya masuk HPP',
            'biaya_kirim' => 2000,
            'biaya_lain' => 0,
            'masuk_hpp' => true,
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        DocTransferDetail::create([
            'transfer_id' => $transfer->id,
            'product_id' => $this->product->id,
            'qty' => 10,
        ]);

        (new ApproveTransferAction())->execute($transfer);

        // avg naik ke 15200 EKSAK
        $this->product->refresh();
        $this->assertEquals(15200, $this->product->avg_cost);

        // HPP_CORRECTION dicatat (warehouse null = global), before/after EKSAK
        $corr = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_CORRECTION')->first();
        $this->assertNotNull($corr);
        $this->assertNull($corr->warehouse_id);       // global
        $this->assertEquals(15000, $corr->avg_cost_before);
        $this->assertEquals(15200, $corr->avg_cost_after);
        $this->assertEquals(0, $corr->qty_in);         // tipe NO_QTY
        $this->assertEquals(0, $corr->qty_out);
        $this->assertEquals(0, $corr->qty_balance);
        $this->assertEquals($transfer->id, $corr->transaction_id);

        // Biaya teralokasi penuh ke satu-satunya baris detail
        $detail = DocTransferDetail::where('transfer_id', $transfer->id)->first();
        $this->assertEquals(2000, $detail->biaya_dialokasikan);

        // Invariant stok TIDAK terpengaruh HPP_CORRECTION (qty 0 + warehouse null)
        $this->assertDataVerifyPasses();
    }
}
