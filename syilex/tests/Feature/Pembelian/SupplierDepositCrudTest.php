<?php

namespace Tests\Feature\Pembelian;

use App\Models\MasterWarehouse;
use App\Models\SupplierDeposit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 1 — Supplier Deposit CRUD via API (manual deposits + nominal masking).
 */
class SupplierDepositCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'deposit-supplier.view', 'deposit-supplier.create', 'deposit-supplier.update',
            'deposit-supplier.delete', 'hutang.view_nominal',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'deposit-supplier.view', 'deposit-supplier.create', 'deposit-supplier.update',
            'deposit-supplier.delete', 'hutang.view_nominal',
        ]);

        $this->supplierId = DB::table('master_supplier')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-DEP',
            'nama_supplier' => 'Supplier Deposit',
            'nama_pic' => 'PIC',
            'telepon' => '08000',
            'tempo_default' => 30,
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_index_forbidden_without_deposit_supplier_view(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/v1/supplier-deposits')
            ->assertForbidden();
    }

    public function test_index_allowed_with_deposit_supplier_view(): void
    {
        $this->actingAs($this->user)
            ->getJson('/api/v1/supplier-deposits')
            ->assertOk();
    }

    public function test_store_forbidden_without_deposit_supplier_create(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('deposit-supplier.view');

        $this->actingAs($viewer)
            ->postJson('/api/v1/supplier-deposits', [
                'supplier_id' => $this->supplierId,
                'tanggal' => now()->toDateString(),
                'nominal_awal' => 500000,
            ])
            ->assertForbidden();
    }

    public function test_store_manual_deposit_via_api(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/supplier-deposits', [
                'supplier_id' => $this->supplierId,
                'tanggal' => now()->toDateString(),
                'nominal_awal' => 750000,
                'no_referensi' => 'REF-001',
                'keterangan' => 'Deposit manual awal',
            ])
            ->assertCreated();

        $response->assertJsonPath('data.deposit.nominal_awal', '750000.00');
        $response->assertJsonPath('data.deposit.status', 'available');

        $this->assertDatabaseHas('supplier_deposit', [
            'supplier_id' => $this->supplierId,
            'no_referensi' => 'REF-001',
            'retur_id' => null,
        ]);
    }

    public function test_show_strips_nominal_without_hutang_view_nominal(): void
    {
        $deposit = $this->createManualDeposit(400000);

        $limited = User::factory()->create();
        $limited->givePermissionTo('deposit-supplier.view');

        $data = $this->actingAs($limited)
            ->getJson("/api/v1/supplier-deposits/{$deposit->ulid}")
            ->assertOk()
            ->json('data.deposit');

        $this->assertArrayNotHasKey('nominal_awal', $data);
        $this->assertArrayNotHasKey('nominal_terpakai', $data);
        $this->assertArrayNotHasKey('sisa_deposit', $data);
    }

    public function test_show_includes_nominal_with_hutang_view_nominal(): void
    {
        $deposit = $this->createManualDeposit(400000);

        $data = $this->actingAs($this->user)
            ->getJson("/api/v1/supplier-deposits/{$deposit->ulid}")
            ->assertOk()
            ->json('data.deposit');

        $this->assertArrayHasKey('nominal_awal', $data);
        $this->assertEquals('400000.00', $data['nominal_awal']);
    }

    public function test_update_manual_deposit_via_api(): void
    {
        $deposit = $this->createManualDeposit(400000);

        $this->actingAs($this->user)
            ->putJson("/api/v1/supplier-deposits/{$deposit->ulid}", [
                'supplier_id' => $this->supplierId,
                'tanggal' => now()->toDateString(),
                'nominal_awal' => 600000,
            ])
            ->assertOk()
            ->assertJsonPath('data.deposit.nominal_awal', '600000.00');

        $this->assertDatabaseHas('supplier_deposit', [
            'ulid' => $deposit->ulid,
            'nominal_awal' => 600000,
            'sisa_deposit' => 600000,
        ]);
    }

    public function test_update_forbidden_without_deposit_supplier_update(): void
    {
        $deposit = $this->createManualDeposit(400000);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('deposit-supplier.view');

        $this->actingAs($viewer)
            ->putJson("/api/v1/supplier-deposits/{$deposit->ulid}", [
                'supplier_id' => $this->supplierId,
                'tanggal' => now()->toDateString(),
                'nominal_awal' => 600000,
            ])
            ->assertForbidden();
    }

    public function test_update_blocked_for_retur_based_deposit(): void
    {
        $deposit = $this->createReturDeposit(500000);

        $this->actingAs($this->user)
            ->putJson("/api/v1/supplier-deposits/{$deposit->ulid}", [
                'supplier_id' => $this->supplierId,
                'tanggal' => now()->toDateString(),
                'nominal_awal' => 600000,
            ])
            ->assertStatus(422);
    }

    public function test_delete_manual_unused_deposit_via_api(): void
    {
        $deposit = $this->createManualDeposit(300000);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/supplier-deposits/{$deposit->ulid}")
            ->assertOk();

        $this->assertDatabaseMissing('supplier_deposit', ['ulid' => $deposit->ulid]);
    }

    public function test_delete_forbidden_without_deposit_supplier_delete(): void
    {
        $deposit = $this->createManualDeposit(300000);

        $editor = User::factory()->create();
        $editor->givePermissionTo(['deposit-supplier.view', 'deposit-supplier.update']);

        $this->actingAs($editor)
            ->deleteJson("/api/v1/supplier-deposits/{$deposit->ulid}")
            ->assertForbidden();
    }

    public function test_delete_blocked_when_deposit_already_used(): void
    {
        $deposit = $this->createManualDeposit(500000, 200000);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/supplier-deposits/{$deposit->ulid}")
            ->assertStatus(422);
    }

    public function test_delete_blocked_for_retur_based_deposit(): void
    {
        $deposit = $this->createReturDeposit(500000);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/supplier-deposits/{$deposit->ulid}")
            ->assertStatus(422);
    }

    private function createManualDeposit(float $nominalAwal, float $nominalTerpakai = 0): SupplierDeposit
    {
        return SupplierDeposit::create([
            'supplier_id' => $this->supplierId,
            'retur_id' => null,
            'tanggal' => now()->toDateString(),
            'nominal_awal' => $nominalAwal,
            'nominal_terpakai' => $nominalTerpakai,
            'sisa_deposit' => $nominalAwal - $nominalTerpakai,
            'status' => $nominalTerpakai > 0 ? 'used_partial' : 'available',
            'created_by' => $this->user->id,
        ]);
    }

    private function createReturDeposit(float $nominalAwal): SupplierDeposit
    {
        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);

        $poId = DB::table('doc_purchase_order')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PO-DEP-01',
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $warehouse->id,
            'status' => 'approved',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $returId = DB::table('doc_purchase_return')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-DEP-01',
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $warehouse->id,
            'po_id' => $poId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SupplierDeposit::create([
            'supplier_id' => $this->supplierId,
            'retur_id' => $returId,
            'tanggal' => now()->toDateString(),
            'nominal_awal' => $nominalAwal,
            'nominal_terpakai' => 0,
            'sisa_deposit' => $nominalAwal,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);
    }
}
