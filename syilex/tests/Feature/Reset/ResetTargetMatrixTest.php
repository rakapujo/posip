<?php

namespace Tests\Feature\Reset;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 3 — Reset Database: matrix target 'master' / 'inventory' / 'transaksi'
 * (belum tercakup penuh oleh ResetSerialTest, yang fokus modul serial) +
 * aturan `refuseIfHasNonDraft` untuk reset dokumen individual (repack/adjustment)
 * yang punya baris non-draft. Study: ResetController::reset()/refuseIfHasNonDraft.
 */
class ResetTargetMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected MasterWarehouse $warehouse;

    protected MasterSupplier $supplier;

    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'settings.reset', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['password' => bcrypt('secret123')]);
        $this->admin->givePermissionTo('settings.reset');
        $this->actingAs($this->admin);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $this->supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP-RM', 'nama_supplier' => 'Supplier Reset Matrix',
            'nama_pic' => 'PIC', 'telepon' => '08000', 'tempo_default' => 30, 'status' => 'active',
        ]);
        $this->product = MasterProduk::factory()->create(['avg_cost' => 5000, 'status' => 'active']);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 20, 'avg_cost' => 5000]
        );
        StockCard::record([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 20, 'qty_out' => 0, 'cost_per_unit' => 5000,
        ]);
        StockCard::$skipObserver = false;
    }

    private function reset(string $target)
    {
        return $this->postJson('/api/v1/reset', [
            'target' => $target,
            'password' => 'secret123',
            'backup_acknowledged' => true,
        ]);
    }

    private function makePoWithHutang(): int
    {
        $poId = DB::table('doc_purchase_order')->insertGetId([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'nomor_dokumen' => 'PO-RM-' . fake()->unique()->numerify('######'),
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'approved',
            'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('supplier_hutang')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'supplier_id' => $this->supplier->id,
            'po_id' => $poId,
            'tanggal' => now()->toDateString(),
            'nominal_awal' => 100000, 'nominal_terbayar' => 0, 'sisa_hutang' => 100000,
            'status' => 'unpaid', 'created_at' => now(),
        ]);

        return $poId;
    }

    // ==================== TARGET: inventory ====================
    #[Test]
    public function reset_inventory_refuses_when_purchase_order_exists(): void
    {
        $this->makePoWithHutang();

        $this->assertGreaterThan(0, DB::table('stock_card')->count());
        $this->assertGreaterThan(0, DB::table('doc_purchase_order')->count());

        $this->reset('inventory')
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        // Ledger & dokumen tetap utuh saat refuse.
        $this->assertGreaterThan(0, DB::table('stock_card')->count());
        $this->assertGreaterThan(0, DB::table('inventory_stock')->count());
        $this->assertGreaterThan(0, DB::table('doc_purchase_order')->count());
    }

    #[Test]
    public function reset_inventory_clears_stock_when_no_open_docs(): void
    {
        $this->assertGreaterThan(0, DB::table('stock_card')->count());
        $this->assertEquals(0, DB::table('doc_purchase_order')->count());
        $this->assertEquals(0, DB::table('doc_sales')->count());

        $this->reset('inventory')->assertOk()->assertJsonPath('message', "Reset 'inventory' berhasil");

        $this->assertEquals(0, DB::table('stock_card')->count());
        $this->assertEquals(0, DB::table('inventory_stock')->count());
        $this->assertEquals(0, DB::table('serial_units')->count());
        $this->assertEquals(0, DB::table('serial_unit_movements')->count());
        $this->assertGreaterThan(0, DB::table('master_produk')->count());
    }

    // ==================== TARGET: transaksi ====================
    #[Test]
    public function reset_transaksi_clears_purchase_and_stock_but_keeps_master(): void
    {
        $this->makePoWithHutang();

        $this->reset('transaksi')->assertOk();

        $this->assertEquals(0, DB::table('doc_purchase_order')->count());
        $this->assertEquals(0, DB::table('supplier_hutang')->count());
        $this->assertEquals(0, DB::table('stock_card')->count());
        $this->assertEquals(0, DB::table('inventory_stock')->count());

        // avg_cost produk direset ke 0 (lihat ResetController::resetTransaksi).
        $this->assertEquals(0, (float) $this->product->fresh()->avg_cost);

        // Master tetap ada.
        $this->assertGreaterThan(0, DB::table('master_produk')->count());
        $this->assertGreaterThan(0, DB::table('master_supplier')->count());
        $this->assertGreaterThan(0, DB::table('master_warehouse')->count());
    }

    // ==================== TARGET: master ====================
    #[Test]
    public function reset_master_clears_master_tables_and_dependent_transaksi(): void
    {
        $this->makePoWithHutang();

        $this->reset('master')->assertOk();

        $this->assertEquals(0, DB::table('master_produk')->count());
        $this->assertEquals(0, DB::table('master_supplier')->count());
        $this->assertEquals(0, DB::table('master_warehouse')->count());
        // Transaksi ikut terhapus dulu (FK dependency) sebelum master.
        $this->assertEquals(0, DB::table('doc_purchase_order')->count());
        $this->assertEquals(0, DB::table('supplier_hutang')->count());
    }

    // ==================== refuseIfHasNonDraft: repack ====================
    #[Test]
    public function reset_repack_refuses_when_non_draft_document_exists(): void
    {
        $repackId = DB::table('doc_repack')->insertGetId([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'nomor_dokumen' => 'RPK-RM-001',
            'warehouse_id' => $this->warehouse->id,
            'tipe' => 'pecah',
            'tanggal' => now()->toDateString(),
            'biaya_repack' => 0,
            'total_cost_input' => 0, 'total_cost_output' => 0,
            'status' => 'approved', // non-draft
            'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->reset('repack');
        $res->assertStatus(422);
        $this->assertStringContainsString('masih ada dokumen non-draft', $res->json('message'));

        // Data TIDAK ikut terhapus.
        $this->assertDatabaseHas('doc_repack', ['id' => $repackId]);
    }
    #[Test]
    public function reset_repack_succeeds_when_all_draft(): void
    {
        DB::table('doc_repack')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'nomor_dokumen' => 'RPK-RM-002',
            'warehouse_id' => $this->warehouse->id,
            'tipe' => 'pecah',
            'tanggal' => now()->toDateString(),
            'biaya_repack' => 0,
            'total_cost_input' => 0, 'total_cost_output' => 0,
            'status' => 'draft',
            'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->reset('repack')->assertOk();

        $this->assertEquals(0, DB::table('doc_repack')->count());
    }

    // ==================== refuseIfHasNonDraft: adjustment ====================
    #[Test]
    public function reset_adjustment_refuses_when_non_draft_document_exists(): void
    {
        $adjId = DB::table('doc_adjustment')->insertGetId([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'nomor_dokumen' => 'ADJ-RM-001',
            'warehouse_id' => $this->warehouse->id,
            'tanggal' => now()->toDateString(),
            'status' => 'approved',
            'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $res = $this->reset('adjustment');
        $res->assertStatus(422);
        $this->assertStringContainsString('masih ada dokumen non-draft', $res->json('message'));
        $this->assertDatabaseHas('doc_adjustment', ['id' => $adjId]);
    }
    #[Test]
    public function reset_adjustment_succeeds_when_all_draft(): void
    {
        DB::table('doc_adjustment')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'nomor_dokumen' => 'ADJ-RM-002',
            'warehouse_id' => $this->warehouse->id,
            'tanggal' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->admin->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->reset('adjustment')->assertOk();

        $this->assertEquals(0, DB::table('doc_adjustment')->count());
    }

    // ==================== counts endpoint sanity across matrix ====================
    #[Test]
    public function counts_reflect_zero_after_full_all_reset(): void
    {
        $this->makePoWithHutang();

        $this->reset('all')->assertOk();

        $res = $this->getJson('/api/v1/reset/counts')->assertOk();
        foreach (['produk', 'supplier', 'purchase_order', 'inventory_stock', 'stock_card', 'warehouse'] as $key) {
            $this->assertEquals(0, $res->json("data.{$key}"), "counts.{$key} harus 0 setelah reset all");
        }
    }
}
