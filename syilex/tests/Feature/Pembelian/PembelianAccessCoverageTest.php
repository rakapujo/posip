<?php

namespace Tests\Feature\Pembelian;

use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 3 — cluster Pembelian: permission matrix HTTP (PO, Retur, Pembayaran Hutang, Supplier Hutang).
 */
class PembelianAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected MasterSupplier $supplier;

    protected MasterWarehouse $warehouse;

    protected MasterProduk $product;

    protected string $poUlid;

    protected string $returUlid;

    protected string $pembayaranUlid;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'po.view', 'po.create', 'po.edit', 'po.delete', 'po.approve',
            'retur-beli.view', 'retur-beli.create', 'retur-beli.update', 'retur-beli.delete',
            'retur-beli.lock', 'retur-beli.approve',
            'pembayaran-hutang.view', 'pembayaran-hutang.create', 'pembayaran-hutang.update',
            'pembayaran-hutang.delete', 'pembayaran-hutang.complete',
            'hutang.view', 'hutang.view_nominal', 'laporan.export',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo([
            'po.view', 'retur-beli.view', 'pembayaran-hutang.view', 'hutang.view',
        ]);

        $actor = User::factory()->create();

        $this->supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP-PBL',
            'nama_supplier' => 'Supplier Pembelian',
            'nama_pic' => 'PIC',
            'telepon' => '08123',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $this->warehouse = MasterWarehouse::factory()->create([
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'status' => 'active',
            'is_serial' => false,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);

        $this->poUlid = (string) Str::ulid();
        DB::table('doc_purchase_order')->insert([
            'ulid' => $this->poUlid,
            'nomor_dokumen' => 'PO-ACC-01',
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->returUlid = (string) Str::ulid();
        DB::table('doc_purchase_return')->insert([
            'ulid' => $this->returUlid,
            'nomor_dokumen' => 'RET-ACC-01',
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->pembayaranUlid = (string) Str::ulid();
        DB::table('doc_pembayaran_hutang')->insert([
            'ulid' => $this->pembayaranUlid,
            'nomor_dokumen' => 'PBH-ACC-01',
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'metode_pembayaran' => 'cash',
            'status' => 'draft',
            'total_bayar_cash' => 0,
            'total_bayar_deposit' => 0,
            'total_pembayaran' => 0,
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_po_index_forbidden_without_view_permission(): void
    {
        $denied = User::factory()->create();

        $this->actingAs($denied)
            ->getJson('/api/v1/purchase-orders')
            ->assertForbidden();
    }

    public function test_po_index_ok_with_view_permission(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/purchase-orders')
            ->assertOk();
    }

    public function test_po_create_forbidden_without_create_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/purchase-orders', [
                'tanggal_po' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'details' => [[
                    'product_id' => $this->product->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_po_approve_forbidden_without_approve_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson("/api/v1/purchase-orders/{$this->poUlid}/approve")
            ->assertForbidden();
    }

    public function test_retur_index_forbidden_without_view_permission(): void
    {
        $denied = User::factory()->create();

        $this->actingAs($denied)
            ->getJson('/api/v1/purchase-returns')
            ->assertForbidden();
    }

    public function test_retur_lock_forbidden_without_lock_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson("/api/v1/purchase-returns/{$this->returUlid}/lock")
            ->assertForbidden();
    }

    public function test_pembayaran_index_forbidden_without_view_permission(): void
    {
        $denied = User::factory()->create();

        $this->actingAs($denied)
            ->getJson('/api/v1/pembayaran-hutangs')
            ->assertForbidden();
    }

    public function test_pembayaran_complete_forbidden_without_complete_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson("/api/v1/pembayaran-hutangs/{$this->pembayaranUlid}/complete")
            ->assertForbidden();
    }

    public function test_supplier_hutang_index_forbidden_without_view_permission(): void
    {
        $denied = User::factory()->create();

        $this->actingAs($denied)
            ->getJson('/api/v1/supplier-hutangs')
            ->assertForbidden();
    }

    public function test_supplier_hutang_aging_summary_forbidden_without_view_nominal(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('hutang.view');

        $this->actingAs($viewer)
            ->getJson('/api/v1/supplier-hutangs/aging-summary')
            ->assertForbidden();
    }

    public function test_supplier_hutang_export_forbidden_without_laporan_export(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['hutang.view', 'hutang.view_nominal']);

        $this->actingAs($viewer)
            ->get('/api/v1/supplier-hutangs/export')
            ->assertForbidden();
    }

    public function test_po_edit_forbidden_without_edit_permission(): void
    {
        $this->actingAs($this->viewer)
            ->putJson("/api/v1/purchase-orders/{$this->poUlid}", [
                'tanggal_po' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'details' => [[
                    'product_id' => $this->product->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertForbidden();
    }

    public function test_po_delete_forbidden_without_delete_permission(): void
    {
        $this->actingAs($this->viewer)
            ->deleteJson("/api/v1/purchase-orders/{$this->poUlid}")
            ->assertForbidden();
    }

    public function test_retur_approve_forbidden_without_approve_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson("/api/v1/purchase-returns/{$this->returUlid}/approve")
            ->assertForbidden();
    }

    public function test_pembayaran_create_forbidden_without_create_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/pembayaran-hutangs', [
                'tanggal' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'metode_pembayaran' => 'cash',
                'details' => [],
            ])
            ->assertForbidden();
    }

    public function test_pembayaran_update_forbidden_without_update_permission(): void
    {
        $this->actingAs($this->viewer)
            ->putJson("/api/v1/pembayaran-hutangs/{$this->pembayaranUlid}", [
                'tanggal' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'metode_pembayaran' => 'cash',
                'details' => [],
            ])
            ->assertForbidden();
    }

    public function test_supplier_hutang_show_forbidden_without_view_permission(): void
    {
        $denied = User::factory()->create();

        $this->actingAs($denied)
            ->getJson('/api/v1/supplier-hutangs/'.(string) Str::ulid())
            ->assertForbidden();
    }
}
