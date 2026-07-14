<?php

namespace Tests\Feature\Inventory;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 3 — cluster Inventory: downstream master guards (inactive warehouse/product).
 */
class InventoryDownstreamGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MasterWarehouse $warehouse;

    protected MasterWarehouse $warehouseTo;

    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('stock.negative_mode', 'block', 'string');

        foreach ([
            'adjustment.create', 'adjustment.approve',
            'transfer.create', 'transfer.approve',
            'repack.create', 'repack.approve',
            'opname.create', 'opname.approve',
            'hpp.create', 'hpp.approve',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'adjustment.create', 'adjustment.approve',
            'transfer.create', 'transfer.approve',
            'repack.create', 'repack.approve',
            'opname.create', 'opname.approve',
            'hpp.create', 'hpp.approve',
        ]);

        $this->warehouse = MasterWarehouse::factory()->create([
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->warehouseTo = MasterWarehouse::factory()->create([
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'status' => 'active',
            'is_serial' => false,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'avg_cost' => 1000,
            'harga_4' => 2000,
        ]);

        InventoryStock::updateOrCreate(
            ['warehouse_id' => $this->warehouse->id, 'product_id' => $this->product->id],
            ['qty' => 100, 'avg_cost' => 1000],
        );
    }

    public function test_adjustment_create_rejects_inactive_warehouse(): void
    {
        $inactiveWh = MasterWarehouse::factory()->create([
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/adjustments', [
                'warehouse_id' => $inactiveWh->id,
                'tanggal' => now()->toDateString(),
                'details' => [[
                    'product_id' => $this->product->id,
                    'qty' => 1,
                    'jenis' => 'debit',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_adjustment_create_rejects_inactive_product(): void
    {
        $inactiveProduct = MasterProduk::factory()->create([
            'status' => 'inactive',
            'is_serial' => false,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/adjustments', [
                'warehouse_id' => $this->warehouse->id,
                'tanggal' => now()->toDateString(),
                'details' => [[
                    'product_id' => $inactiveProduct->id,
                    'qty' => 1,
                    'jenis' => 'debit',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.product_id']);
    }

    public function test_adjustment_approve_rejects_after_warehouse_deactivated(): void
    {
        $adjustmentUlid = (string) Str::ulid();
        $adjustmentId = DB::table('doc_adjustment')->insertGetId([
            'ulid' => $adjustmentUlid,
            'nomor_dokumen' => 'ADJ-DG-01',
            'warehouse_id' => $this->warehouse->id,
            'tanggal' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('doc_adjustment_detail')->insert([
            'ulid' => (string) Str::ulid(),
            'adjustment_id' => $adjustmentId,
            'product_id' => $this->product->id,
            'jenis' => 'debit',
            'stok_sistem' => 10,
            'qty' => 1,
            'stok_akhir' => 11,
        ]);

        $this->warehouse->update(['status' => 'inactive']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/adjustments/{$adjustmentUlid}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_transfer_approve_rejects_after_warehouse_deactivated(): void
    {
        $transferUlid = (string) Str::ulid();
        $transferId = DB::table('doc_transfer')->insertGetId([
            'ulid' => $transferUlid,
            'nomor_dokumen' => 'TRF-DG-01',
            'warehouse_from_id' => $this->warehouse->id,
            'warehouse_to_id' => $this->warehouseTo->id,
            'tanggal' => now()->toDateString(),
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('doc_transfer_detail')->insert([
            'ulid' => (string) Str::ulid(),
            'transfer_id' => $transferId,
            'product_id' => $this->product->id,
            'qty' => 1,
        ]);

        $this->warehouse->update(['status' => 'inactive']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/transfers/{$transferUlid}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_from_id']);
    }

    public function test_transfer_create_rejects_inactive_product(): void
    {
        $inactiveProduct = MasterProduk::factory()->create([
            'status' => 'inactive',
            'is_serial' => false,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/transfers', [
                'warehouse_from_id' => $this->warehouse->id,
                'warehouse_to_id' => $this->warehouseTo->id,
                'tanggal' => now()->toDateString(),
                'details' => [['product_id' => $inactiveProduct->id, 'qty' => 1]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.product_id']);
    }

    public function test_opname_approve_rejects_after_warehouse_deactivated(): void
    {
        $opnameUlid = (string) Str::ulid();
        $opnameId = DB::table('doc_stock_opname')->insertGetId([
            'ulid' => $opnameUlid,
            'nomor_dokumen' => 'OPN-DG-01',
            'warehouse_id' => $this->warehouse->id,
            'tanggal_opname' => now()->toDateString(),
            'mode' => 'partial',
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('doc_stock_opname_detail')->insert([
            'ulid' => (string) Str::ulid(),
            'opname_id' => $opnameId,
            'product_id' => $this->product->id,
            'qty_system' => 10,
            'qty_physical' => 10,
            'qty_difference' => 0,
        ]);

        $this->warehouse->update(['status' => 'inactive']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/opnames/{$opnameUlid}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_hpp_correction_create_rejects_inactive_product(): void
    {
        $inactiveProduct = MasterProduk::factory()->create([
            'status' => 'inactive',
            'is_serial' => false,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/hpp-corrections', [
                'tanggal_koreksi' => now()->toDateString(),
                'details' => [[
                    'product_id' => $inactiveProduct->id,
                    'hpp_baru' => 1500,
                    'alasan' => 'KOREKSI_DATA',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.product_id']);
    }
}
