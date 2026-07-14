<?php

namespace Tests\Feature\Master;

use App\Models\MasterCustomer;
use App\Models\MasterKategoriCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterTipeCustomer;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerMasterCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['customer.view', 'customer.create', 'customer.update', 'customer.delete'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'customer.view', 'customer.create', 'customer.update', 'customer.delete',
        ]);
    }

    public function test_customer_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/customers', [
                'kode_customer' => 'CUS-01',
                'nama' => 'Andi Spesifik',
                'telepon' => '08123456789',
                'jenis' => 'spesifik',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.customer.kode_customer', 'CUS-01');

        $ulid = MasterCustomer::where('kode_customer', 'CUS-01')->first()->ulid;

        $this->actingAs($this->user)
            ->putJson("/api/v1/customers/{$ulid}", [
                'nama' => 'Andi Spesifik Updated',
                'telepon' => '08123456789',
                'jenis' => 'spesifik',
                'status' => 'active',
            ])
            ->assertOk();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/customers/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.customer.status', 'inactive');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/customers/{$ulid}")
            ->assertOk();
    }

    public function test_customer_store_rejects_inactive_tipe(): void
    {
        $tipe = MasterTipeCustomer::create([
            'kode_tipe' => 'TC-IN',
            'nama_tipe' => 'Inactive Tipe',
            'diskon_tipe' => 'none',
            'diskon_nilai' => 0,
            'status' => 'inactive',
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customers', [
                'kode_customer' => 'CUS-02',
                'nama' => 'Customer Baru',
                'telepon' => '08111',
                'tipe_customer_ulid' => $tipe->ulid,
                'jenis' => 'spesifik',
                'status' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_walk_in_customer_cannot_be_deactivated_or_deleted(): void
    {
        $walkIn = MasterCustomer::create([
            'kode_customer' => 'WALKIN',
            'nama' => 'Walk In',
            'telepon' => '08000',
            'jenis' => 'walk_in',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/customers/{$walkIn->ulid}/toggle-status")
            ->assertStatus(422);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/customers/{$walkIn->ulid}")
            ->assertStatus(422);
    }

    public function test_customer_deactivate_blocked_when_default_on_terminal(): void
    {
        $customer = MasterCustomer::create([
            'kode_customer' => 'CUS-TRM',
            'nama' => 'Customer Terminal',
            'telepon' => '08123',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);
        $cash = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-CU',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        MasterPosTerminal::create([
            'kode_terminal' => 'TRM-CU',
            'nama_terminal' => 'Kasir Customer Test',
            'warehouse_id' => $warehouse->id,
            'default_customer_id' => $customer->id,
            'default_metode_pembayaran_id' => $cash->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/customers/{$customer->ulid}", [
                'nama' => $customer->nama,
                'telepon' => $customer->telepon,
                'jenis' => 'spesifik',
                'status' => 'inactive',
            ])
            ->assertStatus(422);
    }

    public function test_customer_delete_blocked_when_has_sales(): void
    {
        $customer = MasterCustomer::create([
            'kode_customer' => 'CUS-SL',
            'nama' => 'Customer Sales',
            'telepon' => '08123',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->insertCompletedSale(['customer_id' => $customer->id]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/customers/{$customer->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_customer_store_rejects_inactive_kategori(): void
    {
        $kategori = MasterKategoriCustomer::create([
            'kode_kategori' => 'KC-IN',
            'nama_kategori' => 'Inactive Kategori',
            'diskon_tipe' => 'none',
            'diskon_nilai' => 0,
            'status' => 'inactive',
        ]);

        $this->actingAs($this->user)
            ->postJson('/api/v1/customers', [
                'kode_customer' => 'CUS-KC',
                'nama' => 'Customer Kategori',
                'telepon' => '08111',
                'kategori_customer_ulid' => $kategori->ulid,
                'jenis' => 'spesifik',
                'status' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_walk_in_customer_cannot_change_jenis_to_spesifik(): void
    {
        $walkIn = MasterCustomer::create([
            'kode_customer' => 'WALKIN2',
            'nama' => 'Walk In 2',
            'telepon' => '08000',
            'jenis' => 'walk_in',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/customers/{$walkIn->ulid}", [
                'nama' => $walkIn->nama,
                'telepon' => $walkIn->telepon,
                'jenis' => 'spesifik',
                'status' => 'active',
            ])
            ->assertStatus(422);
    }

    public function test_customer_toggle_reactivate_allowed_when_default_on_terminal(): void
    {
        $customer = MasterCustomer::create([
            'kode_customer' => 'CUS-REACT',
            'nama' => 'Customer Reactivate',
            'telepon' => '08123',
            'jenis' => 'spesifik',
            'status' => 'inactive',
            'created_by' => $this->user->id,
        ]);

        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);
        $cash = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-REACT',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        MasterPosTerminal::create([
            'kode_terminal' => 'TRM-REACT',
            'nama_terminal' => 'Kasir Reactivate',
            'warehouse_id' => $warehouse->id,
            'default_customer_id' => $customer->id,
            'default_metode_pembayaran_id' => $cash->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/customers/{$customer->ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.customer.status', 'active');
    }

    public function test_customer_toggle_deactivate_blocked_when_default_on_terminal(): void
    {
        $customer = MasterCustomer::create([
            'kode_customer' => 'CUS-TGL',
            'nama' => 'Customer Toggle',
            'telepon' => '08123',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);
        $cash = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-TGL',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        MasterPosTerminal::create([
            'kode_terminal' => 'TRM-TGL',
            'nama_terminal' => 'Kasir Toggle',
            'warehouse_id' => $warehouse->id,
            'default_customer_id' => $customer->id,
            'default_metode_pembayaran_id' => $cash->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/customers/{$customer->ulid}/toggle-status")
            ->assertStatus(422);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function insertCompletedSale(array $overrides = []): int
    {
        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);
        $cash = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-SL-'.Str::upper(Str::random(3)),
            'nama_pembayaran' => 'Tunai Sales Test',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $terminalId = DB::table('master_pos_terminal')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-SL-'.Str::upper(Str::random(3)),
            'nama_terminal' => 'Kasir Sales Test',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $cash->id,
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shiftId = DB::table('pos_terminal_shifts')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminalId,
            'user_id' => $this->user->id,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('doc_sales')->insertGetId(array_merge([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-CU-01',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $terminalId,
            'shift_id' => $shiftId,
            'warehouse_id' => $warehouse->id,
            'subtotal' => 1000,
            'total_setelah_diskon' => 1000,
            'grand_total' => 1000,
            'total_bayar' => 1000,
            'kembalian' => 0,
            'status' => 'completed',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }
}
