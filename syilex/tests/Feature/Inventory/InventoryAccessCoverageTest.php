<?php

namespace Tests\Feature\Inventory;

use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 3 — cluster Inventory: permission matrix HTTP.
 */
class InventoryAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected MasterWarehouse $warehouse;

    protected MasterProduk $product;

    protected string $adjustmentUlid;

    protected string $transferUlid;

    protected string $repackUlid;

    protected string $opnameUlid;

    protected string $hppUlid;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'adjustment.view', 'adjustment.create', 'adjustment.update', 'adjustment.delete', 'adjustment.approve',
            'transfer.view', 'transfer.create', 'transfer.update', 'transfer.delete', 'transfer.approve',
            'repack.view', 'repack.create', 'repack.update', 'repack.delete', 'repack.approve',
            'opname.view', 'opname.create', 'opname.update', 'opname.delete', 'opname.approve',
            'hpp.view', 'hpp.create', 'hpp.update', 'hpp.delete', 'hpp.approve',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $actor = User::factory()->create();

        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo([
            'adjustment.view', 'transfer.view', 'repack.view', 'opname.view', 'hpp.view',
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
            'avg_cost' => 1000,
            'harga_4' => 2000,
        ]);

        $this->adjustmentUlid = (string) Str::ulid();
        DB::table('doc_adjustment')->insert([
            'ulid' => $this->adjustmentUlid,
            'nomor_dokumen' => 'ADJ-ACC-01',
            'warehouse_id' => $this->warehouse->id,
            'tanggal' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $warehouseTo = MasterWarehouse::factory()->create([
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        $this->transferUlid = (string) Str::ulid();
        DB::table('doc_transfer')->insert([
            'ulid' => $this->transferUlid,
            'nomor_dokumen' => 'TRF-ACC-01',
            'warehouse_from_id' => $this->warehouse->id,
            'warehouse_to_id' => $warehouseTo->id,
            'tanggal' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->repackUlid = (string) Str::ulid();
        DB::table('doc_repack')->insert([
            'ulid' => $this->repackUlid,
            'nomor_dokumen' => 'RPK-ACC-01',
            'warehouse_id' => $this->warehouse->id,
            'tipe' => 'pecah',
            'tanggal' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->opnameUlid = (string) Str::ulid();
        DB::table('doc_stock_opname')->insert([
            'ulid' => $this->opnameUlid,
            'nomor_dokumen' => 'OPN-ACC-01',
            'warehouse_id' => $this->warehouse->id,
            'tanggal_opname' => now()->toDateString(),
            'mode' => 'partial',
            'status' => 'draft',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->hppUlid = (string) Str::ulid();
        DB::table('doc_hpp_correction')->insert([
            'ulid' => $this->hppUlid,
            'nomor_dokumen' => 'HPP-ACC-01',
            'tanggal_koreksi' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $actor->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_adjustment_index_forbidden_without_view_permission(): void
    {
        $denied = User::factory()->create();

        $this->actingAs($denied)
            ->getJson('/api/v1/adjustments')
            ->assertForbidden();
    }

    public function test_adjustment_create_forbidden_without_create_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/adjustments', [
                'warehouse_id' => $this->warehouse->id,
                'tanggal' => now()->toDateString(),
                'details' => [[
                    'product_id' => $this->product->id,
                    'qty' => 1,
                    'jenis' => 'debit',
                ]],
            ])
            ->assertForbidden();
    }

    public function test_adjustment_approve_forbidden_without_approve_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson("/api/v1/adjustments/{$this->adjustmentUlid}/approve")
            ->assertForbidden();
    }

    public function test_transfer_create_forbidden_without_create_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/transfers', [
                'warehouse_from_id' => $this->warehouse->id,
                'warehouse_to_id' => $this->warehouse->id,
                'tanggal' => now()->toDateString(),
                'details' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_transfer_approve_forbidden_without_approve_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson("/api/v1/transfers/{$this->transferUlid}/approve")
            ->assertForbidden();
    }

    public function test_repack_update_forbidden_without_update_permission(): void
    {
        $this->actingAs($this->viewer)
            ->putJson("/api/v1/repacks/{$this->repackUlid}", [
                'warehouse_id' => $this->warehouse->id,
                'tipe' => 'pecah',
                'tanggal' => now()->toDateString(),
                'inputs' => [['product_id' => $this->product->id, 'qty' => 1]],
                'outputs' => [['product_id' => $this->product->id, 'qty' => 1]],
            ])
            ->assertForbidden();
    }

    public function test_opname_delete_forbidden_without_delete_permission(): void
    {
        $this->actingAs($this->viewer)
            ->deleteJson("/api/v1/opnames/{$this->opnameUlid}")
            ->assertForbidden();
    }

    public function test_hpp_create_forbidden_without_create_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/hpp-corrections', [
                'tanggal_koreksi' => now()->toDateString(),
                'details' => [[
                    'product_id' => $this->product->id,
                    'hpp_baru' => 1500,
                    'alasan' => 'KOREKSI_DATA',
                ]],
            ])
            ->assertForbidden();
    }

    public function test_hpp_approve_forbidden_without_approve_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson("/api/v1/hpp-corrections/{$this->hppUlid}/approve")
            ->assertForbidden();
    }
}
