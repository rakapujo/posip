<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ResetAuditLogTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'settings.reset', 'guard_name' => 'web']);
        $this->admin = User::factory()->create(['password' => bcrypt('secret123')]);
        $this->admin->givePermissionTo('settings.reset');
    }

    public function test_successful_reset_creates_activity_log(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'brand',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertOk();

        $activity = Activity::where('log_name', 'Reset')->latest()->first();

        $this->assertNotNull($activity, 'Activity log harus tercatat saat reset');
        $this->assertEquals('Reset data target: brand', $activity->description);
        $this->assertEquals($this->admin->id, $activity->causer_id);
        $this->assertEquals('brand', $activity->properties['target']);
        $this->assertArrayHasKey('ip', $activity->properties->toArray());
    }

    public function test_wrong_password_does_not_create_audit_log(): void
    {
        $before = Activity::where('log_name', 'Reset')->count();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'brand',
                'password' => 'wrong',
                'backup_acknowledged' => true,
            ])
            ->assertStatus(422);

        $this->assertEquals($before, Activity::where('log_name', 'Reset')->count());
    }

    public function test_reset_requires_backup_acknowledged(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'brand',
                'password' => 'secret123',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['backup_acknowledged']);
    }

    public function test_unauthorized_user_does_not_create_audit_log(): void
    {
        $kasir = User::factory()->create(['password' => bcrypt('secret123')]);
        $before = Activity::where('log_name', 'Reset')->count();

        $this->actingAs($kasir)
            ->postJson('/api/v1/reset', [
                'target' => 'brand',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertForbidden();

        $this->assertEquals($before, Activity::where('log_name', 'Reset')->count());
    }

    public function test_reset_audit_log_captures_target_variant(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'settings',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertOk();

        $activity = Activity::where('log_name', 'Reset')->latest()->first();
        $this->assertEquals('settings', $activity->properties['target']);
        $this->assertEquals('Reset data target: settings', $activity->description);
    }

    // ====================================================================
    // EDGE CASE: efek nyata truncate + hitung terhapus + permission counts
    // ====================================================================

    /** Reset 'brand' benar-benar mengosongkan tabel: 3 baris → 0 baris. */
    public function test_reset_brand_actually_truncates_rows(): void
    {
        foreach (['B1', 'B2', 'B3'] as $i => $kode) {
            DB::table('master_brand')->insert([
                'ulid' => (string) Str::ulid(),
                'kode_brand' => $kode,
                'nama_brand' => 'Brand ' . $kode,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        $this->assertSame(3, DB::table('master_brand')->count());

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', ['target' => 'brand', 'password' => 'secret123',
                'backup_acknowledged' => true])
            ->assertOk()
            ->assertJsonPath('message', "Reset 'brand' berhasil");

        $this->assertSame(0, DB::table('master_brand')->count(), 'Tabel brand harus kosong setelah reset');
    }

    /** Target tidak dikenal → 422 + tabel TIDAK tersentuh (rollback aman). */
    public function test_invalid_target_returns_422_and_leaves_data_intact(): void
    {
        DB::table('master_brand')->insert([
            'ulid' => (string) Str::ulid(),
            'kode_brand' => 'KEEP',
            'nama_brand' => 'Tetap Ada',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', ['target' => 'tabel_ngawur', 'password' => 'secret123',
                'backup_acknowledged' => true])
            ->assertStatus(422)
            ->assertJsonPath('message', "Target reset 'tabel_ngawur' tidak valid");

        $this->assertSame(1, DB::table('master_brand')->count(), 'Data tidak boleh hilang untuk target invalid');
        $this->assertSame(0, Activity::where('log_name', 'Reset')->count(), 'Invalid target tidak boleh menulis audit executed');
    }

    public function test_refuse_sales_when_stock_card_exists_and_no_audit(): void
    {
        $produk = \App\Models\MasterProduk::factory()->create();
        $wh = \App\Models\MasterWarehouse::factory()->create(['status' => 'active']);
        \App\Models\StockCard::create([
            'product_id' => $produk->id,
            'warehouse_id' => $wh->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => now(),
            'qty_in' => 1,
            'qty_out' => 0,
            'qty_balance' => 1,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'sales',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertStatus(422);

        $this->assertSame(0, Activity::where('log_name', 'Reset')->count());
    }

    public function test_refuse_brand_when_produk_exists(): void
    {
        \App\Models\MasterProduk::factory()->create();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'brand',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertStatus(422)
            ->assertJsonFragment(['message' => "Reset 'brand' ditolak karena masih ada data terkait. Gunakan Reset Master (atau Reset Semua)."]);
    }

    /**
     * Audit log mencatat ip + user_agent (bukan hanya target).
     * Properties harus berisi ketiga key yang ditulis controller.
     */
    public function test_audit_log_records_ip_and_user_agent_properties(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', ['target' => 'brand', 'password' => 'secret123',
                'backup_acknowledged' => true])
            ->assertOk();

        $props = Activity::where('log_name', 'Reset')->latest()->first()->properties->toArray();

        $this->assertArrayHasKey('target', $props);
        $this->assertArrayHasKey('ip', $props);
        $this->assertArrayHasKey('user_agent', $props);
        $this->assertSame('brand', $props['target']);
    }

    /** Reset 'settings' me-reseed default → tabel settings TIDAK kosong setelahnya. */
    public function test_reset_settings_reseeds_defaults_not_empty(): void
    {
        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', ['target' => 'settings', 'password' => 'secret123',
                'backup_acknowledged' => true])
            ->assertOk();

        $this->assertGreaterThan(0, DB::table('settings')->count(), 'Settings harus di-reseed, bukan dibiarkan kosong');
    }

    // ====================================================================
    // EDGE CASE: endpoint counts
    // ====================================================================

    /** counts menolak user tanpa permission settings.reset (403). */
    public function test_counts_requires_permission(): void
    {
        $kasir = User::factory()->create(['password' => bcrypt('secret123')]);

        $this->actingAs($kasir)
            ->getJson('/api/v1/reset/counts')
            ->assertForbidden();
    }

    /** counts mengembalikan hitungan EKSAK per tabel (brand=2, sisanya 0). */
    public function test_counts_returns_exact_row_counts(): void
    {
        foreach (['CX', 'CY'] as $kode) {
            DB::table('master_brand')->insert([
                'ulid' => (string) Str::ulid(),
                'kode_brand' => $kode,
                'nama_brand' => 'Brand ' . $kode,
                'status' => 'active',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $res = $this->actingAs($this->admin)
            ->getJson('/api/v1/reset/counts')
            ->assertOk();

        $this->assertSame(2, $res->json('data.brand'));
        $this->assertSame(0, $res->json('data.produk'));
        $this->assertSame(0, $res->json('data.sales'));
        $this->assertSame(0, $res->json('data.stock_card'));
    }

    public function test_refuse_serial_intake_when_stock_card_exists(): void
    {
        $produk = \App\Models\MasterProduk::factory()->create();
        $wh = \App\Models\MasterWarehouse::factory()->create(['status' => 'active']);
        \App\Models\StockCard::create([
            'product_id' => $produk->id,
            'warehouse_id' => $wh->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => now(),
            'qty_in' => 1,
            'qty_out' => 0,
            'qty_balance' => 1,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'serial_intake',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertStatus(422);
    }

    public function test_refuse_supplier_when_stock_card_exists(): void
    {
        $produk = \App\Models\MasterProduk::factory()->create();
        $wh = \App\Models\MasterWarehouse::factory()->create(['status' => 'active']);
        \App\Models\StockCard::create([
            'product_id' => $produk->id,
            'warehouse_id' => $wh->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => now(),
            'qty_in' => 1,
            'qty_out' => 0,
            'qty_balance' => 1,
            'cost_per_unit' => 0,
            'total_cost' => 0,
            'created_by' => $this->admin->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'supplier',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertStatus(422);
    }

    public function test_refuse_customer_piutang_when_sales_exist(): void
    {
        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        DB::table('doc_sales')->insert([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'SL-TEST-1',
            'tanggal' => now(),
            'terminal_id' => 1,
            'shift_id' => 1,
            'warehouse_id' => 1,
            'customer_id' => 1,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'customer_piutang',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertStatus(422);
    }

    public function test_produk_reset_clears_promo_tables(): void
    {
        $promoId = DB::table('doc_promo')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_promo' => 'PRM-RST',
            'nama_promo' => 'Promo Reset',
            'status' => 'draft',
            'tanggal_mulai' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('doc_promo_details')->insert([
            'promo_id' => $promoId,
            'target_type' => 'semua',
            'target_id' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'produk',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertOk();

        $this->assertSame(0, DB::table('doc_promo')->count());
        $this->assertSame(0, DB::table('doc_promo_details')->count());
    }

    public function test_refuse_promo_when_sales_detail_has_promo_id(): void
    {
        $promoId = DB::table('doc_promo')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_promo' => 'PRM-REF',
            'nama_promo' => 'Promo Ref',
            'status' => 'approved',
            'tanggal_mulai' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $produk = \App\Models\MasterProduk::factory()->create();

        \Illuminate\Support\Facades\Schema::disableForeignKeyConstraints();
        $salesId = DB::table('doc_sales')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'SL-PRM-1',
            'tanggal' => now(),
            'terminal_id' => 1,
            'shift_id' => 1,
            'warehouse_id' => 1,
            'customer_id' => 1,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId,
            'product_id' => $produk->id,
            'unit' => 'PCS',
            'konversi' => 1,
            'qty' => 1,
            'qty_base' => 1,
            'harga_satuan' => 1000,
            'jumlah' => 1000,
            'promo_id' => $promoId,
        ]);
        \Illuminate\Support\Facades\Schema::enableForeignKeyConstraints();

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'promo',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertStatus(422);

        $this->assertSame(1, DB::table('doc_promo')->count());
    }

    public function test_reset_settings_clears_setting_service_cache(): void
    {
        \App\Services\SettingService::all();
        $this->assertTrue(\Illuminate\Support\Facades\Cache::has('app_settings'));

        $this->actingAs($this->admin)
            ->postJson('/api/v1/reset', [
                'target' => 'settings',
                'password' => 'secret123',
                'backup_acknowledged' => true,
            ])
            ->assertOk();

        $this->assertFalse(\Illuminate\Support\Facades\Cache::has('app_settings'));
    }
}
