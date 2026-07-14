<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\InventoryStock;
use App\Models\StockCard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Spatie\Permission\Models\Permission;

class StockCardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterProduk $product;
    protected MasterWarehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'stok.view']);
        Permission::create(['name' => 'stok.view_hpp']);

        // Create user with permissions
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['stok.view', 'stok.view_hpp']);

        // Create warehouse
        $this->warehouse = MasterWarehouse::create([
            'kode_warehouse' => 'WH001',
            'nama_warehouse' => 'Gudang Utama',
            'status' => 'active',
        ]);

        // Create product with all required fields
        $this->product = MasterProduk::create([
            'kode_produk' => 'TEST001',
            'nama_produk' => 'Test Product',
            'unit_1' => 'PCS',
            'konversi_1' => 1,
            'harga_1' => 10000,
            'unit_2' => 'PCS',
            'konversi_2' => 1,
            'harga_2' => 10000,
            'unit_3' => 'PCS',
            'konversi_3' => 1,
            'harga_3' => 10000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'harga_4' => 10000,
            'minimum_stok' => 10,
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
        ]);
    }

    #[Test]
    public function it_returns_empty_data_when_no_product_selected()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/stock-cards');

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'stock_cards' => [],
                    'product' => null,
                ]
            ]);
    }

    #[Test]
    public function it_returns_product_not_found_for_invalid_product()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/stock-cards?product_id=invalid-ulid');

        $response->assertStatus(404);
    }

    #[Test]
    public function it_returns_stock_cards_for_valid_product()
    {
        // Update existing inventory stock (auto-created by MasterProdukObserver)
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $stock->update(['qty' => 100, 'avg_cost' => 10000]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/stock-cards?product_id=' . $this->product->ulid);

        $response->assertOk()
            ->assertJsonStructure([
                'success',
                'data' => [
                    'stock_cards',
                    'product' => [
                        'ulid',
                        'kode_produk',
                        'nama_produk',
                    ],
                    'warehouses',
                    'transaction_types',
                    'pagination',
                    'can_view_hpp',
                ]
            ]);
    }

    #[Test]
    public function it_returns_summary_with_balances_from_inventory_stock()
    {
        // Update existing inventory stock without stock_card entries
        // (MasterProdukObserver already created the record with qty=0)
        StockCard::$skipObserver = true;
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $stock->update(['qty' => 50, 'avg_cost' => 10000]);
        StockCard::$skipObserver = false;

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/stock-cards/summary?product_id=' . $this->product->ulid);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'summary' => [
                        'opening_balance' => 50,  // Should fallback to inventory_stock
                        'total_in' => 0,
                        'total_out' => 0,
                        'ending_balance' => 50,   // Should fallback to inventory_stock
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_returns_summary_with_balances_from_stock_card()
    {
        // Create stock_card entry
        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => now(),
            'qty_in' => 100,
            'qty_out' => 0,
            'qty_balance' => 100,
            'cost_per_unit' => 10000,
            'total_cost' => 1000000,
            'avg_cost_before' => 0,
            'avg_cost_after' => 10000,
            'notes' => 'Test',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/stock-cards/summary?product_id=' . $this->product->ulid);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'data' => [
                    'summary' => [
                        'opening_balance' => 0,
                        'total_in' => 100,
                        'total_out' => 0,
                        'ending_balance' => 100,
                    ]
                ]
            ]);
    }

    #[Test]
    public function it_filters_by_warehouse()
    {
        // Create another warehouse
        $warehouse2 = MasterWarehouse::create([
            'kode_warehouse' => 'WH002',
            'nama_warehouse' => 'Gudang Kedua',
            'status' => 'active',
        ]);

        // Create stock_card entries for both warehouses
        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => now(),
            'qty_in' => 100,
            'qty_out' => 0,
            'qty_balance' => 100,
            'cost_per_unit' => 10000,
            'total_cost' => 1000000,
            'avg_cost_before' => 0,
            'avg_cost_after' => 10000,
        ]);

        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $warehouse2->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => now(),
            'qty_in' => 50,
            'qty_out' => 0,
            'qty_balance' => 50,
            'cost_per_unit' => 10000,
            'total_cost' => 500000,
            'avg_cost_before' => 0,
            'avg_cost_after' => 10000,
        ]);

        // Filter by first warehouse
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/stock-cards?product_id=' . $this->product->ulid . '&warehouse_id=' . $this->warehouse->id);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.stock_cards'));
    }

    #[Test]
    public function it_hides_hpp_columns_without_permission()
    {
        // Remove hpp permission
        $this->user->revokePermissionTo('stok.view_hpp');

        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => now(),
            'qty_in' => 100,
            'qty_out' => 0,
            'qty_balance' => 100,
            'cost_per_unit' => 10000,
            'total_cost' => 1000000,
            'avg_cost_before' => 0,
            'avg_cost_after' => 10000,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/stock-cards?product_id=' . $this->product->ulid);

        $response->assertOk()
            ->assertJson(['data' => ['can_view_hpp' => false]]);

        // Check that HPP fields are not in the response
        $stockCard = $response->json('data.stock_cards.0');
        $this->assertArrayNotHasKey('cost_per_unit', $stockCard);
        $this->assertArrayNotHasKey('total_cost', $stockCard);
    }

    #[Test]
    public function it_requires_stok_view_permission()
    {
        // Create user without permission
        $userWithoutPermission = User::factory()->create();

        $response = $this->actingAs($userWithoutPermission)
            ->getJson('/api/v1/inventory/stock-cards?product_id=' . $this->product->ulid);

        $response->assertStatus(403);
    }

    #[Test]
    public function inventory_stock_observer_creates_stock_card_on_create()
    {
        // Create a NEW warehouse as INACTIVE (so no auto-inventory_stock from MasterWarehouseObserver)
        $newWarehouse = MasterWarehouse::create([
            'kode_warehouse' => 'WH999',
            'nama_warehouse' => 'Gudang Test Observer',
            'status' => 'inactive',
        ]);

        // Reset observer flag
        StockCard::$skipObserver = false;

        // Create inventory stock for the new warehouse (should trigger observer)
        $stock = InventoryStock::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $newWarehouse->id,
            'qty' => 75,
            'avg_cost' => 15000,
        ]);

        // Check stock_card was created
        $this->assertDatabaseHas('stock_card', [
            'product_id' => $this->product->id,
            'warehouse_id' => $newWarehouse->id,
            'qty_in' => 75,
            'qty_balance' => 75,
        ]);
    }

    #[Test]
    public function inventory_stock_observer_creates_stock_card_on_update()
    {
        // Get existing stock (auto-created by MasterProdukObserver with qty=0)
        // Update it without triggering observer first
        StockCard::$skipObserver = true;
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $stock->update(['qty' => 100, 'avg_cost' => 10000]);
        StockCard::$skipObserver = false;

        // Update stock (should trigger observer)
        $stock->qty = 150;
        $stock->save();

        // Check stock_card was created for the difference
        $this->assertDatabaseHas('stock_card', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'qty_in' => 50,  // diff = 150 - 100
            'qty_balance' => 150,
        ]);
    }

    #[Test]
    public function adjust_stock_skips_observer()
    {
        // Use adjustStock which should skip observer
        $stock = InventoryStock::adjustStock(
            $this->product->id,
            $this->warehouse->id,
            100,
            10000
        );

        // Stock should be created
        $this->assertEquals(100, $stock->qty);

        // But no stock_card should be created
        $this->assertDatabaseMissing('stock_card', [
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
        ]);
    }

    // ==================== EDGE CASE GALAK: StockCard::record() ====================

    #[Test]
    public function record_mengambil_avg_before_dari_produk_jika_tidak_diberikan()
    {
        // Produk avg_cost global = 7777. record() tanpa avg_cost_before → ambil dari produk.
        StockCard::$skipObserver = true;
        $this->product->update(['avg_cost' => 7777]);
        StockCard::$skipObserver = false;

        $card = StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE',
            'tanggal' => now(),
            'qty_in' => 5,
            'qty_out' => 0,
            // sengaja TIDAK kirim avg_cost_before & avg_cost_after
        ]);

        // before di-resolve dari produk; after fallback = before
        $this->assertEquals(7777, $card->avg_cost_before);
        $this->assertEquals(7777, $card->avg_cost_after);
        // cost_per_unit fallback = avgCostBefore; total_cost = (in+out)*cost = 5*7777
        $this->assertEquals(7777, $card->cost_per_unit);
        $this->assertEquals(5 * 7777, $card->total_cost);
    }

    #[Test]
    public function record_menghitung_qty_balance_berurutan_dari_saldo_terakhir()
    {
        // Dua entri berurutan di gudang sama: balance harus akumulatif EKSAK.
        $c1 = StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE',
            'tanggal' => now(),
            'qty_in' => 100,
            'avg_cost_before' => 0,
            'avg_cost_after' => 10000,
        ]);
        $c2 = StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'SALES',
            'tanggal' => now(),
            'qty_out' => 30,
            'avg_cost_before' => 10000,
            'avg_cost_after' => 10000,
        ]);
        $c3 = StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'SALES_RETURN',
            'tanggal' => now(),
            'qty_in' => 5,
            'avg_cost_before' => 10000,
            'avg_cost_after' => 10000,
        ]);

        $this->assertEquals(100, $c1->qty_balance); // 0 + 100
        $this->assertEquals(70, $c2->qty_balance);  // 100 - 30
        $this->assertEquals(75, $c3->qty_balance);  // 70 + 5
    }

    #[Test]
    public function record_tipe_no_qty_selalu_balance_nol_walau_ada_saldo_sebelumnya()
    {
        // Saldo gudang sudah 100 via PURCHASE.
        StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE',
            'tanggal' => now(),
            'qty_in' => 100,
            'avg_cost_before' => 0,
            'avg_cost_after' => 10000,
        ]);

        // HPP_RESET (NO_QTY) → balance HARUS 0, tidak mewarisi saldo 100.
        $reset = StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'HPP_RESET',
            'tanggal' => now(),
            'avg_cost_before' => 10000,
            'avg_cost_after' => 0,
            'notes' => 'Reset',
        ]);

        $this->assertEquals(0, $reset->qty_balance);
        $this->assertEquals(0, $reset->qty_in);
        $this->assertEquals(0, $reset->qty_out);
    }

    #[Test]
    public function record_warehouse_null_menghasilkan_balance_nol()
    {
        // HPP_CORRECTION global (warehouse null) → balance 0.
        $corr = StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => null,
            'transaction_type' => 'HPP_CORRECTION',
            'tanggal' => now(),
            'avg_cost_before' => 10000,
            'avg_cost_after' => 12000,
            'notes' => 'Koreksi global',
        ]);

        $this->assertNull($corr->warehouse_id);
        $this->assertEquals(0, $corr->qty_balance);
        $this->assertEquals(10000, $corr->avg_cost_before);
        $this->assertEquals(12000, $corr->avg_cost_after);
    }

    #[Test]
    public function record_mencatat_created_by_dari_user_terautentikasi()
    {
        $this->actingAs($this->user);

        $card = StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE',
            'tanggal' => now(),
            'qty_in' => 1,
            'avg_cost_before' => 10000,
            'avg_cost_after' => 10000,
        ]);

        $this->assertEquals($this->user->id, $card->created_by);
    }

    #[Test]
    public function invariant_sum_qty_in_minus_qty_out_sama_dengan_inventory_qty()
    {
        // Bangun saldo via observer: update inventory → observer buat stock_card padanan.
        // Mulai dari record observer-created (qty=0), naikkan ke 40 lalu turunkan ke 25.
        StockCard::$skipObserver = true;
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $stock->update(['qty' => 0, 'avg_cost' => 10000]);
        StockCard::$skipObserver = false;

        // +40 (observer ADJUSTMENT_IN 40)
        $stock->qty = 40;
        $stock->save();
        // -15 (observer ADJUSTMENT_OUT 15) → 25
        $stock->qty = 25;
        $stock->save();

        // inventory_stock.qty == 25
        $stock->refresh();
        $this->assertEquals(25, $stock->qty);

        // SUM(qty_in - qty_out) dari stock_card == 25
        $computed = (int) StockCard::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->sum('qty_in')
            - (int) StockCard::where('product_id', $this->product->id)
                ->where('warehouse_id', $this->warehouse->id)
                ->sum('qty_out');
        $this->assertEquals(25, $computed);

        // data:verify harus hijau (exit 0)
        $this->assertSame(
            0,
            Artisan::call('data:verify', ['--fail-on-mismatch' => true]),
            "data:verify --fail-on-mismatch harus 0:\n" . Artisan::output()
        );
    }

    #[Test]
    public function data_verify_mendeteksi_inkonsistensi_saat_stock_card_tidak_padan()
    {
        // Naikkan inventory TANPA stock_card padanan (skip observer) → mismatch.
        StockCard::$skipObserver = true;
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $stock->update(['qty' => 99, 'avg_cost' => 10000]); // tidak ada stock_card
        StockCard::$skipObserver = false;

        // Computed dari stock_card = 0, stored = 99 → mismatch → exit 1
        $this->assertSame(
            1,
            Artisan::call('data:verify', ['--fail-on-mismatch' => true]),
            'data:verify HARUS mendeteksi mismatch (exit 1) saat inventory naik tanpa stock_card padanan'
        );
    }
}
