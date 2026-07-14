<?php

namespace Tests\Feature\Master;

use App\Models\InventoryStock;
use App\Models\MasterGrup;
use App\Models\MasterKategori;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterTipe;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class RelatedMasterCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MasterTipe $tipe;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'kategori.view', 'kategori.create', 'kategori.update', 'kategori.delete',
            'grup.view', 'grup.create', 'grup.update', 'grup.delete',
            'warehouse.view', 'warehouse.create', 'warehouse.update', 'warehouse.delete',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'kategori.view', 'kategori.create', 'kategori.update', 'kategori.delete',
            'grup.view', 'grup.create', 'grup.update', 'grup.delete',
            'warehouse.view', 'warehouse.create', 'warehouse.update', 'warehouse.delete',
        ]);

        $this->tipe = MasterTipe::create([
            'kode_tipe' => 'TP-01',
            'nama_tipe' => 'Elektronik',
            'status' => 'active',
        ]);
    }

    public function test_kategori_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/kategoris', [
                'tipe_ulid' => $this->tipe->ulid,
                'kode_kategori' => 'KT-01',
                'nama_kategori' => 'Handphone',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.kategori.kode_kategori', 'KT-01');

        $ulid = MasterKategori::first()->ulid;

        $this->actingAs($this->user)
            ->putJson("/api/v1/kategoris/{$ulid}", [
                'tipe_ulid' => $this->tipe->ulid,
                'nama_kategori' => 'Handphone Updated',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.kategori.nama_kategori', 'Handphone Updated');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/kategoris/{$ulid}")
            ->assertOk();

        $this->assertDatabaseMissing('master_kategori', ['ulid' => $ulid]);
    }

    public function test_kategori_delete_blocked_when_has_grup(): void
    {
        $kategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-02',
            'nama_kategori' => 'Laptop',
            'status' => 'active',
        ]);

        MasterGrup::create([
            'kategori_id' => $kategori->id,
            'kode_grup' => 'GR-01',
            'nama_grup' => 'Grup A',
            'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/kategoris/{$kategori->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_grup_crud_lifecycle_via_api(): void
    {
        $kategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-03',
            'nama_kategori' => 'Tablet',
            'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/grups', [
                'kategori_ulid' => $kategori->ulid,
                'kode_grup' => 'GR-02',
                'nama_grup' => 'Grup B',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.grup.kode_grup', 'GR-02');

        $ulid = MasterGrup::first()->ulid;

        $this->actingAs($this->user)
            ->patchJson("/api/v1/grups/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.grup.status', 'inactive');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/grups/{$ulid}")
            ->assertOk();
    }

    public function test_warehouse_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/warehouses', [
                'kode_warehouse' => 'WH-01',
                'nama_warehouse' => 'Gudang Utama',
                'alamat' => 'Jl. Test',
                'pic_name' => 'Budi',
                'pic_phone' => '08123456789',
                'is_saleable' => true,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.warehouse.kode_warehouse', 'WH-01');

        $ulid = MasterWarehouse::first()->ulid;

        $this->actingAs($this->user)
            ->getJson("/api/v1/warehouses/{$ulid}")
            ->assertOk()
            ->assertJsonPath('data.warehouse.nama_warehouse', 'Gudang Utama');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/warehouses/{$ulid}")
            ->assertOk();
    }

    public function test_kategori_store_rejected_when_tipe_inactive(): void
    {
        $inactiveTipe = MasterTipe::create([
            'kode_tipe' => 'TP-IN',
            'nama_tipe' => 'Inactive Tipe',
            'status' => 'inactive',
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/kategoris', [
                'tipe_ulid' => $inactiveTipe->ulid,
                'kode_kategori' => 'KT-IN',
                'nama_kategori' => 'Should Fail',
                'status' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_grup_delete_blocked_when_has_product(): void
    {
        $kategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-PR',
            'nama_kategori' => 'For Product',
            'status' => 'active',
        ]);

        $grup = MasterGrup::create([
            'kategori_id' => $kategori->id,
            'kode_grup' => 'GR-PR',
            'nama_grup' => 'Grup Product',
            'status' => 'active',
        ]);

        MasterProduk::factory()->create([
            'grup_id' => $grup->id,
            'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/grups/{$grup->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_warehouse_deactivate_blocked_when_used_by_terminal(): void
    {
        $warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $cash = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-WH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        MasterPosTerminal::create([
            'kode_terminal' => 'TRM-WH',
            'nama_terminal' => 'Terminal WH',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $cash->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/warehouses/{$warehouse->ulid}/toggle-status")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_kategori_update_rejected_when_changing_to_inactive_tipe(): void
    {
        $kategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-UPD',
            'nama_kategori' => 'Update Tipe',
            'status' => 'active',
        ]);

        $inactiveTipe = MasterTipe::create([
            'kode_tipe' => 'TP-UPD-IN',
            'nama_tipe' => 'Inactive For Update',
            'status' => 'inactive',
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/kategoris/{$kategori->ulid}", [
                'tipe_ulid' => $inactiveTipe->ulid,
                'nama_kategori' => $kategori->nama_kategori,
                'status' => 'active',
            ])
            ->assertStatus(422);
    }

    public function test_kategori_deactivate_blocked_when_has_grup(): void
    {
        $kategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-DEACT',
            'nama_kategori' => 'Deactivate Block',
            'status' => 'active',
        ]);

        MasterGrup::create([
            'kategori_id' => $kategori->id,
            'kode_grup' => 'GR-DEACT',
            'nama_grup' => 'Grup Deact',
            'status' => 'active',
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/kategoris/{$kategori->ulid}/toggle-status")
            ->assertStatus(422);
    }

    public function test_grup_store_rejected_when_kategori_inactive(): void
    {
        $kategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-GR-IN',
            'nama_kategori' => 'Inactive Kategori',
            'status' => 'inactive',
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/grups', [
                'kategori_ulid' => $kategori->ulid,
                'kode_grup' => 'GR-IN',
                'nama_grup' => 'Should Fail',
                'status' => 'active',
            ])
            ->assertStatus(422);
    }

    public function test_grup_deactivate_blocked_when_has_product(): void
    {
        $kategori = MasterKategori::create([
            'tipe_id' => $this->tipe->id,
            'kode_kategori' => 'KT-GR-PR',
            'nama_kategori' => 'For Grup Product',
            'status' => 'active',
        ]);

        $grup = MasterGrup::create([
            'kategori_id' => $kategori->id,
            'kode_grup' => 'GR-DEACT-PR',
            'nama_grup' => 'Grup Product Deact',
            'status' => 'active',
        ]);

        MasterProduk::factory()->create(['grup_id' => $grup->id, 'status' => 'active']);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/grups/{$grup->ulid}/toggle-status")
            ->assertStatus(422);
    }

    public function test_warehouse_delete_blocked_when_used_by_terminal(): void
    {
        $warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $cash = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-WH-DEL',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        MasterPosTerminal::create([
            'kode_terminal' => 'TRM-WH-DEL',
            'nama_terminal' => 'Terminal WH Delete',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $cash->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/warehouses/{$warehouse->ulid}")
            ->assertStatus(422);
    }

    public function test_warehouse_delete_blocked_when_has_stock(): void
    {
        $warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $product = MasterProduk::factory()->create(['status' => 'active']);

        InventoryStock::updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => 10, 'avg_cost' => 1000]
        );

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/warehouses/{$warehouse->ulid}")
            ->assertStatus(422);
    }

    public function test_warehouse_deactivate_blocked_when_has_stock(): void
    {
        $warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $product = MasterProduk::factory()->create(['status' => 'active']);

        InventoryStock::updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => 5, 'avg_cost' => 1000]
        );

        $this->actingAs($this->user)
            ->putJson("/api/v1/warehouses/{$warehouse->ulid}", [
                'nama_warehouse' => $warehouse->nama_warehouse,
                'is_saleable' => true,
                'status' => 'inactive',
            ])
            ->assertStatus(422);
    }

    public function test_warehouse_delete_blocked_when_has_stock_card_history(): void
    {
        $warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $product = MasterProduk::factory()->create(['status' => 'active']);

        StockCard::record([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => now()->toDateString(),
            'qty_in' => 10,
            'qty_out' => 0,
            'cost_per_unit' => 1000,
            'avg_cost_before' => 0,
            'avg_cost_after' => 1000,
        ]);

        DB::table('inventory_stock')->updateOrInsert(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => 0, 'avg_cost' => 1000, 'updated_at' => now(), 'created_at' => now()]
        );

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/warehouses/{$warehouse->ulid}")
            ->assertStatus(422);
    }
}
