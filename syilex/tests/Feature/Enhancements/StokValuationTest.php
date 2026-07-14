<?php

namespace Tests\Feature\Enhancements;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * E2 — Valuation per Warehouse endpoint di halaman Inventory → Stok.
 */
class StokValuationTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewerWithHpp;
    protected User $viewerNoHpp;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['stok.view', 'stok.view_hpp'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $this->viewerWithHpp = User::factory()->create();
        $this->viewerWithHpp->givePermissionTo(['stok.view', 'stok.view_hpp']);

        $this->viewerNoHpp = User::factory()->create();
        $this->viewerNoHpp->givePermissionTo('stok.view');
    }

    public function test_requires_stok_view_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertForbidden();
    }

    public function test_requires_stok_view_hpp_permission(): void
    {
        $this->actingAs($this->viewerNoHpp)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertForbidden();
    }

    public function test_aggregates_per_warehouse(): void
    {
        $wh1 = MasterWarehouse::factory()->create(['nama_warehouse' => 'WH A', 'status' => 'active']);
        $wh2 = MasterWarehouse::factory()->create(['nama_warehouse' => 'WH B', 'status' => 'active']);

        // MasterProdukObserver auto-create inventory_stock rows. Update values.
        $p1 = MasterProduk::factory()->create(['avg_cost' => 1000, 'status' => 'active']);
        $p2 = MasterProduk::factory()->create(['avg_cost' => 2000, 'status' => 'active']);

        DB::table('inventory_stock')
            ->where('product_id', $p1->id)->where('warehouse_id', $wh1->id)
            ->update(['qty' => 10]); // 10 × 1000 = 10.000
        DB::table('inventory_stock')
            ->where('product_id', $p2->id)->where('warehouse_id', $wh1->id)
            ->update(['qty' => 5]); // 5 × 2000 = 10.000  (WH A total: 20.000)
        DB::table('inventory_stock')
            ->where('product_id', $p1->id)->where('warehouse_id', $wh2->id)
            ->update(['qty' => 3]); // 3 × 1000 = 3.000   (WH B total: 3.000)

        $response = $this->actingAs($this->viewerWithHpp)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertOk();

        $data = $response->json('data');

        $this->assertEquals(23_000, $data['grand_total_value']);
        $this->assertEquals(18, $data['grand_total_qty']); // 10+5+3

        $items = collect($data['items'])->keyBy('nama_warehouse');
        $this->assertEquals(20_000, $items->get('WH A')['value_total']);
        $this->assertEquals(2, $items->get('WH A')['product_count']);
        $this->assertEqualsWithDelta(86.96, $items->get('WH A')['percent'], 0.1);

        $this->assertEquals(3_000, $items->get('WH B')['value_total']);
        $this->assertEquals(1, $items->get('WH B')['product_count']); // p2 belum punya stock di WH B
    }

    public function test_inactive_warehouse_excluded(): void
    {
        $active = MasterWarehouse::factory()->create(['nama_warehouse' => 'ACTIVE', 'status' => 'active']);
        $inactive = MasterWarehouse::factory()->create(['nama_warehouse' => 'INACTIVE', 'status' => 'inactive']);
        $p = MasterProduk::factory()->create(['avg_cost' => 1000, 'status' => 'active']);

        DB::table('inventory_stock')
            ->where('product_id', $p->id)->where('warehouse_id', $active->id)
            ->update(['qty' => 5]);
        // Inactive warehouse may or may not have row (observer only creates for active)
        DB::table('inventory_stock')->insertOrIgnore([
            'product_id' => $p->id, 'warehouse_id' => $inactive->id, 'qty' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->viewerWithHpp)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertOk();

        $items = collect($response->json('data.items'))->keyBy('nama_warehouse');
        $this->assertTrue($items->has('ACTIVE'));
        $this->assertFalse($items->has('INACTIVE'));
    }

    public function test_empty_returns_zero_grand_totals(): void
    {
        $response = $this->actingAs($this->viewerWithHpp)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertOk();

        $data = $response->json('data');
        $this->assertEquals(0, $data['grand_total_value']);
        $this->assertEquals(0, $data['grand_total_qty']);
        $this->assertEmpty($data['items']);
    }

    // ==================== EDGE CASES (galak, assertion eksak) ====================

    /**
     * Seed inventory_stock + stock_card PURCHASE padanan agar invariant
     * SUM(stock_card) === inventory_stock.qty terjaga (pola tests/Feature/Serial).
     */
    private function seedStockWithCard(MasterProduk $product, int $warehouseId, int $qty, float $avgCost): void
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouseId],
            ['qty' => $qty, 'avg_cost' => $avgCost]
        );
        if ($qty !== 0) {
            StockCard::record([
                'product_id' => $product->id, 'warehouse_id' => $warehouseId,
                'transaction_type' => 'PURCHASE', 'tanggal' => now(),
                'qty_in' => $qty, 'qty_out' => 0, 'cost_per_unit' => $avgCost,
            ]);
        }
        StockCard::$skipObserver = false;
    }

    /**
     * Valuasi = Σ(qty × p.avg_cost) EKSAK lintas banyak produk dalam 1 gudang,
     * dengan avg_cost desimal. Sekaligus data:verify hijau (stock_card padanan).
     */
    public function test_valuasi_eksak_dengan_avg_cost_desimal_dan_data_verify(): void
    {
        $wh = MasterWarehouse::factory()->create(['nama_warehouse' => 'WH-DEC', 'status' => 'active']);
        $p1 = MasterProduk::factory()->create(['avg_cost' => 1500.50, 'status' => 'active']);
        $p2 = MasterProduk::factory()->create(['avg_cost' => 250.25, 'status' => 'active']);

        $this->seedStockWithCard($p1, $wh->id, 4, 1500.50); // 4 × 1500.50 = 6002.00
        $this->seedStockWithCard($p2, $wh->id, 8, 250.25);  // 8 × 250.25  = 2002.00

        $data = $this->actingAs($this->viewerWithHpp)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertOk()
            ->json('data');

        $items = collect($data['items'])->keyBy('nama_warehouse');
        $this->assertEquals(8004.00, $items->get('WH-DEC')['value_total']); // 6002 + 2002
        $this->assertEquals(12, $items->get('WH-DEC')['qty_total']); // 4 + 8
        $this->assertEquals(2, $items->get('WH-DEC')['product_count']);
        $this->assertEquals(8004.00, $data['grand_total_value']);
        $this->assertEquals(12, $data['grand_total_qty']);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * product_count hanya menghitung produk dengan qty != 0 (COUNT DISTINCT CASE
     * WHEN s.qty != 0). Produk dengan qty 0 TIDAK dihitung, tapi value tetap 0.
     */
    public function test_product_count_abaikan_qty_nol(): void
    {
        $wh = MasterWarehouse::factory()->create(['nama_warehouse' => 'WH-ZERO', 'status' => 'active']);
        $pAda = MasterProduk::factory()->create(['avg_cost' => 1000, 'status' => 'active']);
        $pNol = MasterProduk::factory()->create(['avg_cost' => 9999, 'status' => 'active']);

        $this->seedStockWithCard($pAda, $wh->id, 5, 1000); // dihitung
        $this->seedStockWithCard($pNol, $wh->id, 0, 9999); // qty 0 → tidak dihitung

        $data = $this->actingAs($this->viewerWithHpp)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertOk()
            ->json('data');

        $row = collect($data['items'])->firstWhere('nama_warehouse', 'WH-ZERO');
        $this->assertEquals(1, $row['product_count']); // hanya pAda
        $this->assertEquals(5_000, $row['value_total']); // qty 0 × 9999 = 0 → 5000
        $this->assertEquals(5, $row['qty_total']);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Produk non-aktif (status != active) dikecualikan dari valuasi (WHERE p.status
     * = active). Stok-nya tidak boleh ikut dijumlahkan.
     */
    public function test_produk_non_aktif_dikecualikan(): void
    {
        $wh = MasterWarehouse::factory()->create(['nama_warehouse' => 'WH-INACT-P', 'status' => 'active']);
        $aktif = MasterProduk::factory()->create(['avg_cost' => 1000, 'status' => 'active']);
        // Produk aktif dulu (observer buat inventory_stock), lalu seed stok, baru nonaktifkan
        $nonaktif = MasterProduk::factory()->create(['avg_cost' => 1000, 'status' => 'active']);

        $this->seedStockWithCard($aktif, $wh->id, 10, 1000);   // 10.000
        $this->seedStockWithCard($nonaktif, $wh->id, 50, 1000); // harus diabaikan
        DB::table('master_produk')->where('id', $nonaktif->id)->update(['status' => 'inactive']);

        $data = $this->actingAs($this->viewerWithHpp)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertOk()
            ->json('data');

        $row = collect($data['items'])->firstWhere('nama_warehouse', 'WH-INACT-P');
        $this->assertEquals(10_000, $row['value_total']); // hanya produk aktif
        $this->assertEquals(10, $row['qty_total']);
        $this->assertEquals(1, $row['product_count']);
    }

    /**
     * Produk soft-deleted dikecualikan (WHERE p.deleted_at IS NULL).
     */
    public function test_produk_soft_deleted_dikecualikan(): void
    {
        $wh = MasterWarehouse::factory()->create(['nama_warehouse' => 'WH-DEL', 'status' => 'active']);
        $hidup = MasterProduk::factory()->create(['avg_cost' => 2000, 'status' => 'active']);
        $dihapus = MasterProduk::factory()->create(['avg_cost' => 2000, 'status' => 'active']);

        $this->seedStockWithCard($hidup, $wh->id, 3, 2000);   // 6.000
        $this->seedStockWithCard($dihapus, $wh->id, 100, 2000); // diabaikan
        $dihapus->delete(); // soft delete

        $data = $this->actingAs($this->viewerWithHpp)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertOk()
            ->json('data');

        $row = collect($data['items'])->firstWhere('nama_warehouse', 'WH-DEL');
        $this->assertEquals(6_000, $row['value_total']);
        $this->assertEquals(3, $row['qty_total']);
        $this->assertEquals(1, $row['product_count']);
    }

    /**
     * Persen tiap warehouse dibulatkan 2 desimal & berjumlah 100% saat dua gudang
     * bernilai sama besar (50/50 eksak).
     */
    public function test_persen_per_warehouse_eksak_50_50(): void
    {
        $whA = MasterWarehouse::factory()->create(['nama_warehouse' => 'PCT-A', 'status' => 'active']);
        $whB = MasterWarehouse::factory()->create(['nama_warehouse' => 'PCT-B', 'status' => 'active']);
        $p = MasterProduk::factory()->create(['avg_cost' => 1000, 'status' => 'active']);

        $this->seedStockWithCard($p, $whA->id, 10, 1000); // 10.000
        $this->seedStockWithCard($p, $whB->id, 10, 1000); // 10.000

        $items = collect($this->actingAs($this->viewerWithHpp)
            ->getJson('/api/v1/inventory/stocks/valuation-by-warehouse')
            ->assertOk()
            ->json('data.items'))->keyBy('nama_warehouse');

        $this->assertEquals(50.0, $items->get('PCT-A')['percent']);
        $this->assertEquals(50.0, $items->get('PCT-B')['percent']);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
}
