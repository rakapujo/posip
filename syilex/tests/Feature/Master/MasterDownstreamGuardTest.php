<?php

namespace Tests\Feature\Master;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\SupplierHutang;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MasterDownstreamGuardTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MasterSupplier $supplier;

    protected MasterWarehouse $warehouse;

    protected MasterProduk $product;

    protected MasterProduk $poProduct;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        foreach ([
            'po.create', 'po.edit', 'po.approve', 'retur-beli.create', 'retur-beli.lock', 'retur-beli.approve',
            'serial-intake.create', 'pembayaran-hutang.create', 'pembayaran-hutang.complete',
            'transfer.create',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'po.create', 'po.edit', 'po.approve', 'retur-beli.create', 'retur-beli.lock', 'retur-beli.approve',
            'serial-intake.create', 'pembayaran-hutang.create', 'pembayaran-hutang.complete',
            'transfer.create',
        ]);

        $this->supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP-DG',
            'nama_supplier' => 'Supplier Downstream',
            'nama_pic' => 'PIC',
            'telepon' => '08123456789',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->warehouse = MasterWarehouse::factory()->create([
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->poProduct = MasterProduk::factory()->create([
            'status' => 'active',
            'is_serial' => false,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);

        $this->product = MasterProduk::factory()->create([
            'status' => 'active',
            'is_serial' => true,
        ]);

        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 50, 'avg_cost' => 5000]
        );

        InventoryStock::updateOrCreate(
            ['product_id' => $this->poProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 50, 'avg_cost' => 5000]
        );
    }

    public function test_po_update_rejects_inactive_supplier(): void
    {
        $inactive = MasterSupplier::create([
            'kode_supplier' => 'SUP-PO-UPD',
            'nama_supplier' => 'Inactive PO Update',
            'nama_pic' => 'PIC',
            'telepon' => '08111',
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $poId = DB::table('doc_purchase_order')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PO-UPD-01',
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poUlid = DB::table('doc_purchase_order')->where('id', $poId)->value('ulid');

        $this->actingAs($this->user)
            ->putJson("/api/v1/purchase-orders/{$poUlid}", [
                'tanggal_po' => now()->toDateString(),
                'supplier_id' => $inactive->id,
                'warehouse_id' => $this->warehouse->id,
                'details' => [[
                    'product_id' => $this->poProduct->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_po_update_rejects_inactive_warehouse(): void
    {
        $inactiveWh = MasterWarehouse::factory()->create([
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $poId = DB::table('doc_purchase_order')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PO-UPD-02',
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $poUlid = DB::table('doc_purchase_order')->where('id', $poId)->value('ulid');

        $this->actingAs($this->user)
            ->putJson("/api/v1/purchase-orders/{$poUlid}", [
                'tanggal_po' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $inactiveWh->id,
                'details' => [[
                    'product_id' => $this->poProduct->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_purchase_return_create_rejects_inactive_supplier(): void
    {
        $inactive = MasterSupplier::create([
            'kode_supplier' => 'SUP-RET',
            'nama_supplier' => 'Inactive Retur',
            'nama_pic' => 'PIC',
            'telepon' => '08111',
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-returns', [
                'tanggal' => now()->toDateString(),
                'supplier_id' => $inactive->id,
                'warehouse_id' => $this->warehouse->id,
                'details' => [[
                    'product_id' => $this->product->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_serial_intake_create_rejects_inactive_supplier(): void
    {
        $inactive = MasterSupplier::create([
            'kode_supplier' => 'SUP-SI',
            'nama_supplier' => 'Inactive Serial',
            'nama_pic' => 'PIC',
            'telepon' => '08111',
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/serial-intakes', [
                'product_id' => $this->product->ulid,
                'warehouse_id' => $this->warehouse->ulid,
                'supplier_id' => $inactive->ulid,
                'units' => [[
                    'serial_number' => 'SN-001',
                    'harga_modal' => 1000000,
                    'harga_jual' => 1200000,
                    'grade' => 'A',
                    'battery_condition' => 'Original',
                    'battery_health' => 90,
                    'account_status' => 'unlocked',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_pembayaran_hutang_create_rejects_inactive_supplier(): void
    {
        $inactive = MasterSupplier::create([
            'kode_supplier' => 'SUP-PH',
            'nama_supplier' => 'Inactive Hutang',
            'nama_pic' => 'PIC',
            'telepon' => '08111',
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $hutangId = DB::table('supplier_hutang')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $inactive->id,
            'tanggal' => now(),
            'nominal_awal' => 50000,
            'nominal_terbayar' => 0,
            'sisa_hutang' => 50000,
            'status' => 'unpaid',
            'created_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/pembayaran-hutangs', [
                'tanggal' => now()->toDateString(),
                'supplier_id' => $inactive->id,
                'metode_pembayaran' => 'cash',
                'details' => [[
                    'hutang_id' => $hutangId,
                    'nominal_dibayar' => 10000,
                    'sumber' => 'cash',
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_transfer_create_rejects_inactive_warehouse(): void
    {
        $activeTo = MasterWarehouse::factory()->create([
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $inactiveFrom = MasterWarehouse::factory()->create([
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/transfers', [
                'warehouse_from_id' => $inactiveFrom->id,
                'warehouse_to_id' => $activeTo->id,
                'tanggal' => now()->toDateString(),
                'details' => [[
                    'product_id' => $this->poProduct->id,
                    'qty' => 1,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_from_id']);
    }

    public function test_po_create_rejects_inactive_warehouse(): void
    {
        $inactiveWh = MasterWarehouse::factory()->create([
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-orders', [
                'tanggal_po' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $inactiveWh->id,
                'details' => [[
                    'product_id' => $this->poProduct->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_po_create_rejects_inactive_product(): void
    {
        $inactiveProduct = MasterProduk::factory()->create([
            'status' => 'inactive',
            'is_serial' => false,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-orders', [
                'tanggal_po' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'details' => [[
                    'product_id' => $inactiveProduct->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.product_id']);
    }

    public function test_po_create_rejects_serial_product(): void
    {
        $this->actingAs($this->user)
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
            ->assertStatus(422)
            ->assertJsonValidationErrors(['details.0.product_id']);
    }

    public function test_po_approve_rejects_after_supplier_deactivated(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-orders', [
                'tanggal_po' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'details' => [[
                    'product_id' => $this->poProduct->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 2,
                    'harga_per_unit' => 5000,
                ]],
            ])
            ->assertCreated();

        $poUlid = $response->json('data.purchase_order.ulid');

        $this->supplier->update(['status' => 'inactive']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/purchase-orders/{$poUlid}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_purchase_return_create_rejects_inactive_warehouse(): void
    {
        $inactiveWh = MasterWarehouse::factory()->create([
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-returns', [
                'tanggal' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $inactiveWh->id,
                'details' => [[
                    'product_id' => $this->product->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_purchase_return_lock_rejects_after_warehouse_deactivated(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-returns', [
                'tanggal' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'details' => [[
                    'product_id' => $this->poProduct->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertCreated();

        $returUlid = $response->json('data.purchase_return.ulid');

        $this->warehouse->update(['status' => 'inactive']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/purchase-returns/{$returUlid}/lock")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_pembayaran_hutang_complete_rejects_after_supplier_deactivated(): void
    {
        $hutangId = DB::table('supplier_hutang')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $this->supplier->id,
            'tanggal' => now(),
            'nominal_awal' => 50000,
            'nominal_terbayar' => 0,
            'sisa_hutang' => 50000,
            'status' => 'unpaid',
            'created_at' => now(),
        ]);

        $pembayaranUlid = (string) Str::ulid();
        $pembayaranId = DB::table('doc_pembayaran_hutang')->insertGetId([
            'ulid' => $pembayaranUlid,
            'nomor_dokumen' => 'PBH-DG-01',
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'metode_pembayaran' => 'cash',
            'status' => 'draft',
            'total_bayar_cash' => 10000,
            'total_bayar_deposit' => 0,
            'total_pembayaran' => 10000,
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('doc_pembayaran_hutang_detail')->insert([
            'ulid' => (string) Str::ulid(),
            'pembayaran_id' => $pembayaranId,
            'hutang_id' => $hutangId,
            'nominal_dibayar' => 10000,
            'sumber' => 'cash',
        ]);

        $this->supplier->update(['status' => 'inactive']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/pembayaran-hutangs/{$pembayaranUlid}/complete")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_po_approve_rejects_after_warehouse_deactivated(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-orders', [
                'tanggal_po' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'details' => [[
                    'product_id' => $this->poProduct->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 2,
                    'harga_per_unit' => 5000,
                ]],
            ])
            ->assertCreated();

        $poUlid = $response->json('data.purchase_order.ulid');

        $this->warehouse->update(['status' => 'inactive']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/purchase-orders/{$poUlid}/approve")
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }

    public function test_purchase_return_approve_rejects_after_warehouse_deactivated(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-returns', [
                'tanggal' => now()->toDateString(),
                'supplier_id' => $this->supplier->id,
                'warehouse_id' => $this->warehouse->id,
                'details' => [[
                    'product_id' => $this->poProduct->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertCreated();

        $returUlid = $response->json('data.purchase_return.ulid');

        $this->actingAs($this->user)
            ->postJson("/api/v1/purchase-returns/{$returUlid}/lock")
            ->assertOk();

        $this->warehouse->update(['status' => 'inactive']);

        $this->actingAs($this->user)
            ->postJson("/api/v1/purchase-returns/{$returUlid}/approve", [
                'nilai_diakui' => 1000,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['warehouse_id']);
    }
}
