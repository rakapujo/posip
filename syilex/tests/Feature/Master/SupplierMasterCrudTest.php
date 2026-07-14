<?php

namespace Tests\Feature\Master;

use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupplierMasterCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['supplier.view', 'supplier.create', 'supplier.update', 'supplier.delete', 'po.create'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'supplier.view', 'supplier.create', 'supplier.update', 'supplier.delete', 'po.create',
        ]);
    }

    public function test_supplier_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/suppliers', [
                'kode_supplier' => 'SUP-01',
                'nama_supplier' => 'PT Sumber Jaya',
                'nama_pic' => 'Budi',
                'telepon' => '08123456789',
                'tempo_default' => 14,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.supplier.kode_supplier', 'SUP-01');

        $ulid = MasterSupplier::first()->ulid;

        $this->actingAs($this->user)
            ->putJson("/api/v1/suppliers/{$ulid}", [
                'nama_supplier' => 'PT Sumber Jaya Updated',
                'nama_pic' => 'Budi',
                'telepon' => '08123456789',
                'status' => 'active',
            ])
            ->assertOk();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/suppliers/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.supplier.status', 'inactive');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/suppliers/{$ulid}")
            ->assertOk();
    }

    public function test_supplier_deactivate_blocked_when_outstanding_hutang(): void
    {
        $supplier = $this->createSupplier();

        DB::table('supplier_hutang')->insert([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $supplier->id,
            'tanggal' => now(),
            'nominal_awal' => 250000,
            'nominal_terbayar' => 50000,
            'sisa_hutang' => 200000,
            'status' => 'partial',
            'created_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/suppliers/{$supplier->ulid}/toggle-status")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_supplier_delete_blocked_when_has_purchase_order(): void
    {
        $supplier = $this->createSupplier();
        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);

        DB::table('doc_purchase_order')->insert([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PO-SUP-01',
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/suppliers/{$supplier->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_po_create_rejects_inactive_supplier(): void
    {
        $supplier = $this->createSupplier(['status' => 'inactive']);
        $warehouse = MasterWarehouse::factory()->create(['status' => 'active', 'created_by' => $this->user->id]);
        $productId = DB::table('master_produk')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_produk' => 'PRD-SUP',
            'nama_produk' => 'Produk Supplier Test',
            'unit_1' => 'PCS',
            'unit_2' => 'PCS',
            'unit_3' => 'PCS',
            'unit_4' => 'PCS',
            'konversi_1' => 1,
            'konversi_2' => 1,
            'konversi_3' => 1,
            'konversi_4' => 1,
            'harga_1' => 1000,
            'harga_2' => 1000,
            'harga_3' => 1000,
            'harga_4' => 1000,
            'avg_cost' => 0,
            'minimum_stok' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/purchase-orders', [
                'tanggal_po' => now()->toDateString(),
                'supplier_id' => $supplier->id,
                'warehouse_id' => $warehouse->id,
                'details' => [[
                    'product_id' => $productId,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 1,
                    'harga_per_unit' => 1000,
                ]],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['supplier_id']);
    }

    public function test_unauthorized_user_cannot_create_supplier(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson('/api/v1/suppliers', [
                'kode_supplier' => 'SUP-99',
                'nama_supplier' => 'Denied',
                'nama_pic' => 'X',
                'telepon' => '08111',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_supplier_update_deactivate_blocked_when_deposit_remains(): void
    {
        $supplier = $this->createSupplier();
        $this->seedSupplierDeposit($supplier, 25000);

        $this->actingAs($this->user)
            ->putJson("/api/v1/suppliers/{$supplier->ulid}", [
                'nama_supplier' => $supplier->nama_supplier,
                'nama_pic' => 'PIC',
                'telepon' => '08123456789',
                'status' => 'inactive',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_supplier_delete_blocked_when_has_purchase_return(): void
    {
        $supplier = $this->createSupplier();
        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);

        DB::table('doc_purchase_return')->insert([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-SUP-01',
            'tanggal' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/suppliers/{$supplier->ulid}")
            ->assertStatus(422);
    }

    public function test_supplier_delete_blocked_when_has_hutang_record(): void
    {
        $supplier = $this->createSupplier();

        DB::table('supplier_hutang')->insert([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $supplier->id,
            'tanggal' => now(),
            'nominal_awal' => 100000,
            'nominal_terbayar' => 100000,
            'sisa_hutang' => 0,
            'status' => 'paid',
            'created_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/suppliers/{$supplier->ulid}")
            ->assertStatus(422);
    }

    public function test_supplier_delete_blocked_when_has_deposit_record(): void
    {
        $supplier = $this->createSupplier();
        $this->seedSupplierDeposit($supplier, 0, 0);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/suppliers/{$supplier->ulid}")
            ->assertStatus(422);
    }

    private function createSupplier(array $overrides = []): MasterSupplier
    {
        return MasterSupplier::create(array_merge([
            'kode_supplier' => 'SUP-'.Str::upper(Str::random(4)),
            'nama_supplier' => 'Supplier Test',
            'nama_pic' => 'PIC',
            'telepon' => '08123456789',
            'status' => 'active',
            'created_by' => $this->user->id,
        ], $overrides));
    }

    private function seedSupplierDeposit(MasterSupplier $supplier, float $sisaDeposit, float $nominalAwal = 50000): void
    {
        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);
        $poId = DB::table('doc_purchase_order')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PO-DEP-'.Str::upper(Str::random(4)),
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'status' => 'approved',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $returId = DB::table('doc_purchase_return')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-DEP-'.Str::upper(Str::random(4)),
            'tanggal' => now()->toDateString(),
            'supplier_id' => $supplier->id,
            'warehouse_id' => $warehouse->id,
            'po_id' => $poId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('supplier_deposit')->insert([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $supplier->id,
            'retur_id' => $returId,
            'tanggal' => now()->toDateString(),
            'nominal_awal' => $nominalAwal,
            'nominal_terpakai' => $nominalAwal - $sisaDeposit,
            'sisa_deposit' => $sisaDeposit,
            'status' => $sisaDeposit > 0 ? 'available' : 'used_all',
            'created_by' => $this->user->id,
            'updated_at' => now(),
        ]);
    }
}
