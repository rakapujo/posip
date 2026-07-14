<?php

namespace Tests\Feature\Master;

use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MetodePembayaranMasterCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['metode-bayar.view', 'metode-bayar.create', 'metode-bayar.update', 'metode-bayar.delete'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'metode-bayar.view', 'metode-bayar.create', 'metode-bayar.update', 'metode-bayar.delete',
        ]);
    }

    public function test_tunai_metode_pembayaran_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/metode-pembayarans', [
                'kode_pembayaran' => 'CASH-01',
                'nama_pembayaran' => 'Kas Tunai',
                'metode' => 'tunai',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.metode_pembayaran.kode_pembayaran', 'CASH-01')
            ->assertJsonPath('data.metode_pembayaran.metode', 'tunai');

        $ulid = MasterMetodePembayaran::first()->ulid;

        $this->actingAs($this->user)
            ->putJson("/api/v1/metode-pembayarans/{$ulid}", [
                'nama_pembayaran' => 'Kas Tunai Updated',
                'metode' => 'tunai',
                'status' => 'active',
            ])
            ->assertOk();

        $this->actingAs($this->user)
            ->patchJson("/api/v1/metode-pembayarans/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.metode_pembayaran.status', 'inactive');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/metode-pembayarans/{$ulid}")
            ->assertOk();
    }

    public function test_non_tunai_store_normalizes_and_persists_fields(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/metode-pembayarans', [
                'kode_pembayaran' => 'QRIS-01',
                'nama_pembayaran' => 'QRIS Toko',
                'metode' => 'non_tunai',
                'jenis' => 'qris',
                'nama_akun' => 'Toko QRIS',
                'biaya_tambahan_tipe' => 'percent',
                'biaya_tambahan_nilai' => 0.7,
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.metode_pembayaran.jenis', 'qris')
            ->assertJsonPath('data.metode_pembayaran.biaya_tambahan_tipe', 'percent');
    }

    public function test_non_tunai_rejects_percent_biaya_over_100(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/metode-pembayarans', [
                'kode_pembayaran' => 'BAD-QR',
                'nama_pembayaran' => 'QRIS Invalid',
                'metode' => 'non_tunai',
                'jenis' => 'qris',
                'biaya_tambahan_tipe' => 'percent',
                'biaya_tambahan_nilai' => 150,
                'status' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['biaya_tambahan_nilai']);
    }

    public function test_switch_to_tunai_clears_non_tunai_fields_on_update(): void
    {
        $metode = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'TRF-01',
            'nama_pembayaran' => 'Transfer',
            'metode' => 'non_tunai',
            'jenis' => 'bank',
            'nama_akun' => 'BCA',
            'biaya_tambahan_tipe' => 'nominal',
            'biaya_tambahan_nilai' => 2500,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/metode-pembayarans/{$metode->ulid}", [
                'nama_pembayaran' => 'Tunai Baru',
                'metode' => 'tunai',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.metode_pembayaran.metode', 'tunai')
            ->assertJsonPath('data.metode_pembayaran.jenis', null)
            ->assertJsonPath('data.metode_pembayaran.biaya_tambahan_tipe', 'none');

        $metode->refresh();
        $this->assertSame('tunai', $metode->metode);
        $this->assertNull($metode->jenis);
        $this->assertSame('none', $metode->biaya_tambahan_tipe);
        $this->assertSame('0.00', $metode->biaya_tambahan_nilai);
    }

    public function test_deactivate_blocked_when_used_by_terminal(): void
    {
        $metode = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-TRM',
            'nama_pembayaran' => 'Tunai Terminal',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);

        MasterPosTerminal::create([
            'kode_terminal' => 'TRM-MP',
            'nama_terminal' => 'Kasir MP',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $metode->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/metode-pembayarans/{$metode->ulid}/toggle-status")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_delete_blocked_when_used_by_sales_payment(): void
    {
        $metode = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-SL',
            'nama_pembayaran' => 'Tunai Sales',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $saleId = $this->insertCompletedSale();

        DB::table('doc_sales_payments')->insert([
            'sales_id' => $saleId,
            'metode_pembayaran_id' => $metode->id,
            'nominal' => 5000,
            'biaya_tambahan' => 0,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/metode-pembayarans/{$metode->ulid}")
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_non_tunai_update_rejects_percent_biaya_over_100(): void
    {
        $metode = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'QR-UPD',
            'nama_pembayaran' => 'QRIS Update',
            'metode' => 'non_tunai',
            'jenis' => 'qris',
            'biaya_tambahan_tipe' => 'percent',
            'biaya_tambahan_nilai' => 1,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->actingAs($this->user)
            ->putJson("/api/v1/metode-pembayarans/{$metode->ulid}", [
                'nama_pembayaran' => 'QRIS Update',
                'metode' => 'non_tunai',
                'jenis' => 'qris',
                'biaya_tambahan_tipe' => 'percent',
                'biaya_tambahan_nilai' => 150,
                'status' => 'active',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['biaya_tambahan_nilai']);
    }

    public function test_delete_blocked_when_used_as_allowed_terminal_payment(): void
    {
        $metode = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'QR-ALW',
            'nama_pembayaran' => 'QRIS Allowed',
            'metode' => 'non_tunai',
            'jenis' => 'qris',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $defaultCash = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-ALW',
            'nama_pembayaran' => 'Tunai Default',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);
        $terminalId = DB::table('master_pos_terminal')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-ALW',
            'nama_terminal' => 'Kasir Allowed',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $defaultCash->id,
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('pos_terminal_payment_methods')->insert([
            'terminal_id' => $terminalId,
            'metode_pembayaran_id' => $metode->id,
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/metode-pembayarans/{$metode->ulid}")
            ->assertStatus(422);
    }

    public function test_deactivate_allowed_when_only_used_in_sales_history(): void
    {
        $metode = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-HIST',
            'nama_pembayaran' => 'Tunai History',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $saleId = $this->insertCompletedSale();
        DB::table('doc_sales_payments')->insert([
            'sales_id' => $saleId,
            'metode_pembayaran_id' => $metode->id,
            'nominal' => 5000,
            'biaya_tambahan' => 0,
        ]);

        $this->actingAs($this->user)
            ->patchJson("/api/v1/metode-pembayarans/{$metode->ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.metode_pembayaran.status', 'inactive');
    }

    private function insertCompletedSale(): int
    {
        $warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);
        $terminalCash = MasterMetodePembayaran::create([
            'kode_pembayaran' => 'CASH-TRM-'.Str::upper(Str::random(3)),
            'nama_pembayaran' => 'Tunai Terminal Sales',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $terminalId = DB::table('master_pos_terminal')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-MP-'.Str::upper(Str::random(3)),
            'nama_terminal' => 'Kasir MP Sales',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $terminalCash->id,
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

        $customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'C-MP-'.Str::upper(Str::random(3)),
            'nama' => 'Customer MP Sales',
            'telepon' => '08123',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->user->id,
            'updated_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-MP-01',
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $terminalId,
            'shift_id' => $shiftId,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customerId,
            'subtotal' => 5000,
            'total_setelah_diskon' => 5000,
            'grand_total' => 5000,
            'total_bayar' => 5000,
            'kembalian' => 0,
            'status' => 'completed',
            'created_by' => $this->user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
