<?php

namespace Tests\Feature\Transfer;

use App\Actions\Transfer\ApproveTransferAction;
use App\Actions\Transfer\CreateTransferAction;
use App\Actions\Transfer\UpdateTransferAction;
use App\Models\DocTransfer;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class TransferCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouseFrom;
    protected MasterWarehouse $warehouseTo;
    protected MasterProduk $product;
    protected CreateTransferAction $createAction;
    protected UpdateTransferAction $updateAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouseFrom = MasterWarehouse::factory()->create(['status' => 'active']);
        $this->warehouseTo = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Test Product',
            'avg_cost' => 10000,
            'status' => 'active',
        ]);

        // Initial stock at source warehouse = 100 (+ kartu PURCHASE padanan agar
        // invariant stock_card konsisten → data:verify lulus). Tujuan = 0 (tanpa kartu, konsisten).
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseFrom->id],
            ['qty' => 100, 'avg_cost' => 10000]
        );
        StockCard::record([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouseFrom->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 100, 'qty_out' => 0, 'cost_per_unit' => 10000,
            'avg_cost_before' => 10000, 'avg_cost_after' => 10000,
        ]);
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouseTo->id],
            ['qty' => 0, 'avg_cost' => 10000]
        );
        StockCard::$skipObserver = false;

        $this->createAction = new CreateTransferAction();
        $this->updateAction = new UpdateTransferAction();
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'tanggal' => '2026-04-12',
            'notes' => 'Test transfer',
            'details' => [
                ['product_id' => $this->product->id, 'qty' => 20],
            ],
        ], $overrides);
    }
    #[Test]
    public function create_transfer_has_draft_status_and_correct_warehouses()
    {
        $transfer = $this->createAction->execute($this->baseData());

        $this->assertEquals('draft', $transfer->status);
        $this->assertStringStartsWith('TRF-', $transfer->nomor_dokumen);
        $this->assertEquals($this->warehouseFrom->id, $transfer->warehouse_from_id);
        $this->assertEquals($this->warehouseTo->id, $transfer->warehouse_to_id);
        $this->assertEquals(1, $transfer->details->count());
        $this->assertEquals(20, $transfer->details->first()->qty);
    }
    #[Test]
    public function create_transfer_does_not_change_inventory_stock()
    {
        $this->createAction->execute($this->baseData());

        $fromStock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseFrom->id)
            ->first();
        $toStock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseTo->id)
            ->first();

        $this->assertEquals(100, $fromStock->qty, 'Source stock unchanged on create');
        $this->assertEquals(0, $toStock->qty, 'Destination stock unchanged on create');
    }
    #[Test]
    public function create_transfer_with_multiple_products()
    {
        $product2 = MasterProduk::factory()->create(['avg_cost' => 5000, 'status' => 'active']);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $product2->id, 'warehouse_id' => $this->warehouseFrom->id],
            ['qty' => 30, 'avg_cost' => 5000]
        );
        StockCard::$skipObserver = false;

        $transfer = $this->createAction->execute($this->baseData([
            'details' => [
                ['product_id' => $this->product->id, 'qty' => 10],
                ['product_id' => $product2->id, 'qty' => 5],
            ],
        ]));

        $this->assertEquals(2, $transfer->details->count());
    }
    #[Test]
    public function update_transfer_on_draft_replaces_details()
    {
        $transfer = $this->createAction->execute($this->baseData());

        $updated = $this->updateAction->execute($transfer->fresh(), $this->baseData([
            'notes' => 'Updated notes',
            'details' => [
                ['product_id' => $this->product->id, 'qty' => 50],
            ],
        ]));

        $this->assertEquals(1, $updated->details->count());
        $this->assertEquals(50, $updated->details->first()->qty);
    }
    #[Test]
    public function update_transfer_throws_when_already_approved()
    {
        $transfer = $this->createAction->execute($this->baseData());

        (new ApproveTransferAction())->execute($transfer);

        $this->expectException(ValidationException::class);
        $this->updateAction->execute($transfer->fresh(), $this->baseData());
    }

    // ===================== EDGE CASE TAMBAHAN (galak) =====================

    /**
     * Approve transfer memindahkan stok eksak antar gudang:
     * asal 100-20=80, tujuan 0+20=20. Qty global tetap 100. HPP tak berubah.
     * data:verify wajib 0.
     */
    #[Test]
    public function approve_transfer_memindahkan_stok_eksak_dan_qty_global_tetap()
    {
        $globalBefore = (int) InventoryStock::where('product_id', $this->product->id)->sum('qty');
        $this->assertSame(100, $globalBefore);

        $transfer = $this->createAction->execute($this->baseData()); // qty 20
        (new ApproveTransferAction())->execute($transfer);

        $this->assertSame('approved', $transfer->fresh()->status);

        $from = (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseFrom->id)->value('qty');
        $to = (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseTo->id)->value('qty');
        $this->assertSame(80, $from);
        $this->assertSame(20, $to);

        // Qty global kekal (transfer internal)
        $this->assertSame(100, (int) InventoryStock::where('product_id', $this->product->id)->sum('qty'));

        // HPP avg_before == avg_after di kedua kartu, dan produk tetap 10000
        $this->assertSame(10000.0, (float) $this->product->fresh()->avg_cost);
        $out = StockCard::where('transaction_id', $transfer->id)->where('transaction_type', 'TRANSFER_OUT')->first();
        $in = StockCard::where('transaction_id', $transfer->id)->where('transaction_type', 'TRANSFER_IN')->first();
        $this->assertSame(20.0, (float) $out->qty_out);
        $this->assertSame(20.0, (float) $in->qty_in);
        $this->assertSame((float) $out->avg_cost_before, (float) $out->avg_cost_after);
        $this->assertSame((float) $in->avg_cost_before, (float) $in->avg_cost_after);
        $this->assertSame(10000.0, (float) $out->avg_cost_after);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Transfer tepat membuat 1 stock_card OUT + 1 stock_card IN (tidak lebih).
     */
    #[Test]
    public function approve_transfer_buat_tepat_satu_kartu_out_dan_satu_in()
    {
        $transfer = $this->createAction->execute($this->baseData());
        (new ApproveTransferAction())->execute($transfer);

        $this->assertSame(1, StockCard::where('transaction_id', $transfer->id)
            ->where('transaction_type', 'TRANSFER_OUT')->count());
        $this->assertSame(1, StockCard::where('transaction_id', $transfer->id)
            ->where('transaction_type', 'TRANSFER_IN')->count());
        // Tak ada HPP_RESET (qty global > 0) maupun HPP_CORRECTION (tanpa biaya)
        $this->assertSame(0, StockCard::where('transaction_id', $transfer->id)
            ->whereIn('transaction_type', ['HPP_RESET', 'HPP_CORRECTION'])->count());
    }

    /**
     * Stok kurang di gudang asal diblok saat setting block.
     * Stok TIDAK boleh berubah & status tetap draft & tak ada kartu.
     */
    #[Test]
    public function approve_transfer_stok_asal_kurang_diblok_saat_setting_block()
    {
        SettingService::set('stock.negative_mode', 'block', 'string');

        // qty 150 > stok asal 100
        $transfer = $this->createAction->execute($this->baseData([
            'details' => [
                ['product_id' => $this->product->id, 'qty' => 150],
            ],
        ]));

        try {
            (new ApproveTransferAction())->execute($transfer);
            $this->fail('Approve seharusnya gagal karena stok asal kurang (mode block).');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $this->assertSame(100, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseFrom->id)->value('qty'));
        $this->assertSame(0, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseTo->id)->value('qty'));
        $this->assertSame('draft', $transfer->fresh()->status);
        $this->assertSame(0, StockCard::where('transaction_id', $transfer->id)->count());
    }

    /**
     * Approve hanya boleh sekali. Approve kedua ditolak & tak menggandakan kartu/stok.
     */
    #[Test]
    public function approve_transfer_kedua_kali_ditolak()
    {
        $transfer = $this->createAction->execute($this->baseData()); // 20
        (new ApproveTransferAction())->execute($transfer);

        try {
            (new ApproveTransferAction())->execute($transfer->fresh());
            $this->fail('Approve kedua seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        $this->assertSame(80, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouseFrom->id)->value('qty'));
        $this->assertSame(1, StockCard::where('transaction_id', $transfer->id)
            ->where('transaction_type', 'TRANSFER_OUT')->count());
        $this->assertSame(1, StockCard::where('transaction_id', $transfer->id)
            ->where('transaction_type', 'TRANSFER_IN')->count());
    }

    /**
     * Transfer yang sudah approved tidak bisa dihapus (hanya draft).
     */
    #[Test]
    public function transfer_approved_tidak_bisa_dihapus_via_http()
    {
        foreach (['transfer.delete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo('transfer.delete');

        $transfer = $this->createAction->execute($this->baseData());
        (new ApproveTransferAction())->execute($transfer);

        $this->deleteJson("/api/v1/transfers/{$transfer->ulid}")->assertStatus(422);

        // Masih ada di DB
        $this->assertSame(1, DocTransfer::where('id', $transfer->id)->count());
    }

    /**
     * Gudang asal == tujuan ditolak (rule different) di layer controller.
     */
    #[Test]
    public function gudang_asal_sama_dengan_tujuan_ditolak_via_http()
    {
        foreach (['transfer.create'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo('transfer.create');

        $this->postJson('/api/v1/transfers', [
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseFrom->id, // sama
            'tanggal' => '2026-04-12',
            'details' => [
                ['product_id' => $this->product->id, 'qty' => 5],
            ],
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['warehouse_to_id']);

        $this->assertSame(0, DocTransfer::count());
    }

    /**
     * Duplikat produk dalam satu transfer ditolak di layer controller.
     */
    #[Test]
    public function duplikat_produk_dalam_satu_transfer_ditolak_via_http()
    {
        foreach (['transfer.create'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo('transfer.create');

        $this->postJson('/api/v1/transfers', [
            'warehouse_from_id' => $this->warehouseFrom->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'tanggal' => '2026-04-12',
            'details' => [
                ['product_id' => $this->product->id, 'qty' => 5],
                ['product_id' => $this->product->id, 'qty' => 3],
            ],
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['details']);

        $this->assertSame(0, DocTransfer::count());
    }
}
