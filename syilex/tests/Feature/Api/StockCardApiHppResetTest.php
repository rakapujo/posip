<?php

namespace Tests\Feature\Api;

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

class StockCardApiHppResetTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::create(['name' => 'stok.view']);
        Permission::create(['name' => 'stok.view_hpp']);

        // Create role
        $role = Role::create(['name' => 'admin']);
        $role->givePermissionTo(['stok.view', 'stok.view_hpp']);

        // Create user
        $this->user = User::factory()->create();
        $this->user->assignRole('admin');

        // Create warehouse
        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        // Create product
        $this->product = MasterProduk::factory()->create([
            'avg_cost' => 10000,
            'status' => 'active',
        ]);
    }
    #[Test]
    public function stock_card_api_returns_hpp_reset_in_transaction_types()
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/stock-cards');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'transaction_types',
                ],
            ]);

        $transactionTypes = $response->json('data.transaction_types');
        $typeValues = array_column($transactionTypes, 'value');

        $this->assertContains('HPP_RESET', $typeValues);
    }
    #[Test]
    public function stock_card_api_can_filter_by_hpp_reset_type()
    {
        // Create stock - skip observer
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 10000]
        );
        StockCard::$skipObserver = false;

        // Create some stock card entries manually
        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'ADJUSTMENT_OUT',
            'tanggal' => now(),
            'qty_in' => 0,
            'qty_out' => 10,
            'qty_balance' => 0,
            'cost_per_unit' => 10000,
            'total_cost' => 100000,
            'avg_cost_before' => 10000,
            'avg_cost_after' => 10000,
            'created_by' => $this->user->id,
        ]);

        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'HPP_RESET',
            'tanggal' => now(),
            'qty_in' => 0,
            'qty_out' => 0,
            'qty_balance' => 0,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'avg_cost_before' => 10000,
            'avg_cost_after' => 0,
            'notes' => 'Auto Reset HPP (Stock Kosong)',
            'created_by' => $this->user->id,
        ]);

        // Filter by HPP_RESET
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/inventory/stock-cards?product_id={$this->product->ulid}&transaction_type=HPP_RESET");

        $response->assertStatus(200);

        $stockCards = $response->json('data.stock_cards');
        $this->assertCount(1, $stockCards);
        $this->assertEquals('HPP_RESET', $stockCards[0]['transaction_type']);
        $this->assertEquals('Reset HPP (Stock Kosong)', $stockCards[0]['transaction_type_label']);
    }
    #[Test]
    public function hpp_reset_entry_shows_correct_data_in_api_response()
    {
        // Create stock - skip observer
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        StockCard::$skipObserver = false;

        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'HPP_RESET',
            'transaction_no' => 'ADJ-TEST-001',
            'tanggal' => now(),
            'qty_in' => 0,
            'qty_out' => 0,
            'qty_balance' => 0,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'avg_cost_before' => 15000,
            'avg_cost_after' => 0,
            'notes' => 'Auto Reset HPP (Stock Kosong)',
            'created_by' => $this->user->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/inventory/stock-cards?product_id={$this->product->ulid}");

        $response->assertStatus(200);

        $stockCards = $response->json('data.stock_cards');
        $resetEntry = collect($stockCards)->firstWhere('transaction_type', 'HPP_RESET');

        $this->assertNotNull($resetEntry);
        $this->assertEquals('ADJ-TEST-001', $resetEntry['transaction_no']);
        $this->assertEquals(0, $resetEntry['qty_in']);
        $this->assertEquals(0, $resetEntry['qty_out']);
        $this->assertEquals('15000.0000', $resetEntry['avg_cost_before']);
        $this->assertEquals('0.0000', $resetEntry['avg_cost_after']);
        $this->assertEquals('Auto Reset HPP (Stock Kosong)', $resetEntry['notes']);
    }
    #[Test]
    public function hpp_movement_page_shows_hpp_reset_entries()
    {
        // Create stock - skip observer
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        StockCard::$skipObserver = false;

        // Create HPP_RESET entry
        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'HPP_RESET',
            'tanggal' => now(),
            'qty_in' => 0,
            'qty_out' => 0,
            'qty_balance' => 0,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'avg_cost_before' => 20000,
            'avg_cost_after' => 0,
            'notes' => 'Auto Reset HPP (Stock Kosong)',
            'created_by' => $this->user->id,
        ]);

        // Get HPP summary
        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/inventory/stock-cards/hpp-summary?product_id={$this->product->ulid}");

        $response->assertStatus(200);

        // HPP akhir should be 0 (from HPP_RESET)
        $summary = $response->json('data.summary');
        $this->assertEquals(0, $summary['avg_cost_akhir']);
    }

    // ====================================================================
    // EDGE CASE tambahan — galak, assertion eksak
    // ====================================================================

    /** Membuat HPP_RESET pada stok 0 TIDAK melanggar invariant stok (data:verify lulus). */
    public function test_hpp_reset_on_zero_stock_keeps_data_invariants_intact(): void
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        StockCard::$skipObserver = false;

        // HPP_RESET = tipe tanpa pergerakan qty (qty_in=qty_out=0), jadi
        // SUM(qty_in - qty_out) tetap 0 === inventory_stock.qty (0).
        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'HPP_RESET',
            'tanggal' => now(),
            'qty_in' => 0,
            'qty_out' => 0,
            'qty_balance' => 0,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'avg_cost_before' => 12345,
            'avg_cost_after' => 0,
            'notes' => 'Auto Reset HPP (Stock Kosong)',
            'created_by' => $this->user->id,
        ]);

        $exit = Artisan::call('data:verify', ['--fail-on-mismatch' => true]);
        $this->assertSame(0, $exit, 'data:verify harus 0 (tidak ada mismatch) setelah HPP_RESET pada stok 0');
    }

    /** Tanpa permission stok.view → 403 Unauthorized (bukan 200 kosong). */
    public function test_index_requires_stok_view_permission(): void
    {
        $noPerm = User::factory()->create();

        $this->actingAs($noPerm)
            ->getJson('/api/v1/inventory/stock-cards')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Unauthorized');
    }

    /** User dengan stok.view TAPI tanpa stok.view_hpp → kolom HPP DISEMBUNYIKAN. */
    public function test_hpp_columns_hidden_without_view_hpp_permission(): void
    {
        Permission::firstOrCreate(['name' => 'stok.view']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('stok.view'); // tanpa stok.view_hpp

        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'HPP_RESET',
            'tanggal' => now(),
            'qty_in' => 0,
            'qty_out' => 0,
            'qty_balance' => 0,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'avg_cost_before' => 9000,
            'avg_cost_after' => 0,
            'notes' => 'Reset',
            'created_by' => $this->user->id,
        ]);

        $res = $this->actingAs($viewer)
            ->getJson("/api/v1/inventory/stock-cards?product_id={$this->product->ulid}")
            ->assertStatus(200);

        $this->assertFalse($res->json('data.can_view_hpp'));
        $row = $res->json('data.stock_cards.0');
        $this->assertArrayNotHasKey('avg_cost_before', $row, 'Kolom HPP harus disembunyikan');
        $this->assertArrayNotHasKey('avg_cost_after', $row);
        $this->assertArrayNotHasKey('cost_per_unit', $row);
        // Kolom non-HPP tetap ada.
        $this->assertArrayHasKey('transaction_type', $row);
        $this->assertArrayHasKey('qty_balance', $row);
    }

    /** User dengan stok.view_hpp → kolom HPP terlihat & bertipe float eksak. */
    public function test_hpp_columns_visible_with_view_hpp_permission(): void
    {
        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'HPP_RESET',
            'tanggal' => now(),
            'qty_in' => 0,
            'qty_out' => 0,
            'qty_balance' => 0,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'avg_cost_before' => 17500,
            'avg_cost_after' => 0,
            'notes' => 'Reset',
            'created_by' => $this->user->id,
        ]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/inventory/stock-cards?product_id={$this->product->ulid}")
            ->assertStatus(200);

        $this->assertTrue($res->json('data.can_view_hpp'));
        $row = $res->json('data.stock_cards.0');
        // JSON menserialkan 17500.0 → 17500; bandingkan nilai numerik secara eksak.
        $this->assertEqualsWithDelta(17500.0, (float) $row['avg_cost_before'], 0.0);
        $this->assertEqualsWithDelta(0.0, (float) $row['avg_cost_after'], 0.0);
    }

    /** Tanpa product_id → daftar kosong + pagination total 0 (boundary kosong). */
    public function test_index_without_product_id_returns_empty_list(): void
    {
        $res = $this->actingAs($this->user)
            ->getJson('/api/v1/inventory/stock-cards')
            ->assertStatus(200);

        $this->assertSame([], $res->json('data.stock_cards'));
        $this->assertNull($res->json('data.product'));
        $this->assertSame(0, $res->json('data.pagination.total'));
        // transaction_types tetap disediakan untuk dropdown filter.
        $this->assertContains('HPP_RESET', array_column($res->json('data.transaction_types'), 'value'));
    }

    /** Filter transaction_type=HPP_RESET hanya mengembalikan baris HPP_RESET (bukan ADJUSTMENT_OUT). */
    public function test_filter_excludes_non_matching_transaction_types_exactly(): void
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        StockCard::$skipObserver = false;

        // 2 baris ADJUSTMENT_OUT + 1 baris HPP_RESET
        foreach ([10, 5] as $out) {
            StockCard::create([
                'product_id' => $this->product->id,
                'warehouse_id' => $this->warehouse->id,
                'transaction_type' => 'ADJUSTMENT_OUT',
                'tanggal' => now(),
                'qty_in' => 0, 'qty_out' => $out, 'qty_balance' => 0,
                'cost_per_unit' => 10000, 'total_cost' => $out * 10000,
                'avg_cost_before' => 10000, 'avg_cost_after' => 10000,
                'created_by' => $this->user->id,
            ]);
        }
        StockCard::create([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'HPP_RESET',
            'tanggal' => now(),
            'qty_in' => 0, 'qty_out' => 0, 'qty_balance' => 0,
            'cost_per_unit' => 0, 'total_cost' => 0,
            'avg_cost_before' => 10000, 'avg_cost_after' => 0,
            'notes' => 'Reset', 'created_by' => $this->user->id,
        ]);

        $res = $this->actingAs($this->user)
            ->getJson("/api/v1/inventory/stock-cards?product_id={$this->product->ulid}&transaction_type=HPP_RESET")
            ->assertStatus(200);

        $cards = $res->json('data.stock_cards');
        $this->assertCount(1, $cards);
        $this->assertSame('HPP_RESET', $cards[0]['transaction_type']);
        $this->assertSame(1, $res->json('data.pagination.total'));
    }
}
