<?php

namespace Tests\Feature\Reset;

use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Reset Database wajib ikut membersihkan tabel modul serial
 * (doc_serial_intake, serial_units, doc_serial_change*) + counts menampilkannya.
 */
class ResetSerialTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $produk;
    protected MasterWarehouse $wh;
    protected MasterSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['settings.reset', 'serial-intake.view', 'serial-intake.create', 'serial-intake.approve'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create(['password' => bcrypt('secret123')]);
        $this->admin->givePermissionTo(['settings.reset', 'serial-intake.view', 'serial-intake.create', 'serial-intake.approve']);
        $this->actingAs($this->admin);

        $this->produk = MasterProduk::create([
            'kode_produk' => 'LAP_M2', 'nama_produk' => 'MacBook Air M2', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
        $this->wh = MasterWarehouse::create([
            'kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang Utama', 'is_saleable' => true, 'status' => 'active',
        ]);
        $this->supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP1', 'nama_supplier' => 'PT Test Supplier',
            'nama_pic' => 'Budi', 'telepon' => '08123456789', 'status' => 'active',
        ]);
    }

    private function approveIntake(): void
    {
        $ulid = $this->postJson('/api/v1/serial-intakes', [
            'product_id' => $this->produk->ulid,
            'warehouse_id' => $this->wh->ulid,
            'supplier_id' => $this->supplier->ulid,
            'units' => [
                ['serial_number' => 'SN-A', 'harga_modal' => 10000000, 'harga_jual' => 12000000, 'grade' => 'A', 'battery_condition' => 'Original', 'battery_health' => 90, 'account_status' => 'unlocked'],
            ],
        ])->json('data.serial_intake.ulid');
        $this->postJson("/api/v1/serial-intakes/{$ulid}/approve")->assertOk();
    }

    private function reset(string $target)
    {
        return $this->postJson('/api/v1/reset', ['target' => $target, 'password' => 'secret123']);
    }
    #[Test]
    public function reset_serial_intake_clears_serial_tables_and_serial_hutang()
    {
        $this->approveIntake();
        $this->assertGreaterThan(0, DB::table('serial_units')->count());
        $this->assertGreaterThan(0, DB::table('doc_serial_intake')->count());
        $this->assertGreaterThan(0, DB::table('supplier_hutang')->whereNotNull('serial_intake_id')->count());

        $this->reset('serial_intake')->assertOk();

        $this->assertEquals(0, DB::table('serial_units')->count());
        $this->assertEquals(0, DB::table('doc_serial_intake')->count());
        $this->assertEquals(0, DB::table('supplier_hutang')->whereNotNull('serial_intake_id')->count());
    }
    #[Test]
    public function reset_transaksi_group_clears_serial()
    {
        $this->approveIntake();

        $this->reset('transaksi')->assertOk();

        $this->assertEquals(0, DB::table('doc_serial_intake')->count());
        $this->assertEquals(0, DB::table('serial_units')->count());
    }
    #[Test]
    public function counts_include_serial_tables()
    {
        $this->approveIntake();

        $res = $this->getJson('/api/v1/reset/counts')->assertOk();
        $this->assertEquals(1, $res->json('data.serial_intake'));
        $this->assertEquals(1, $res->json('data.serial_units'));
    }

    // ───────────────────────── EDGE CASE TAMBAHAN (galak) ─────────────────────────
    #[Test]
    public function intake_yang_disetujui_lolos_data_verify_sebelum_reset(): void
    {
        // Pre-kondisi (CLAUDE.md): skenario menyentuh stok → invariant harus konsisten.
        $this->approveIntake();

        \Artisan::call('data:verify', ['--json' => true]);
        $json = json_decode(\Artisan::output(), true);

        $this->assertSame('ok', $json['status'], 'Approve intake harus menjaga semua invariant');
        $this->assertSame(0, $json['report']['serial_stock_consistency']['mismatches']);
        $this->assertSame(0, $json['report']['serial_sold_integrity']['mismatches']);
        $this->assertSame(0, $json['report']['stock_consistency']['mismatches']);
    }
    #[Test]
    public function reset_membutuhkan_password_benar()
    {
        $this->approveIntake();

        $res = $this->postJson('/api/v1/reset', ['target' => 'serial_intake', 'password' => 'salah-banget']);
        $res->assertStatus(422)->assertJson(['success' => false, 'message' => 'Password salah']);

        // Data TIDAK boleh tersentuh saat password salah.
        $this->assertGreaterThan(0, DB::table('serial_units')->count());
        $this->assertGreaterThan(0, DB::table('doc_serial_intake')->count());
    }
    #[Test]
    public function reset_tanpa_permission_settings_reset_ditolak()
    {
        $this->approveIntake();

        // User baru tanpa settings.reset.
        $noPerm = User::factory()->create(['password' => bcrypt('secret123')]);
        $res = $this->actingAs($noPerm)
            ->postJson('/api/v1/reset', ['target' => 'serial_intake', 'password' => 'secret123']);
        $res->assertStatus(403);

        // Data tetap utuh.
        $this->assertGreaterThan(0, DB::table('serial_units')->count());
    }
    #[Test]
    public function reset_target_invalid_mengembalikan_422()
    {
        $res = $this->reset('target-ngaco-xyz');
        $res->assertStatus(422)
            ->assertJson(['success' => false, 'message' => "Target reset 'target-ngaco-xyz' tidak valid"]);
    }
    #[Test]
    public function reset_serial_intake_juga_menghapus_serial_unit_movements()
    {
        $this->approveIntake();

        // Sisipkan movement manual (flow app belum mengisinya; verifikasi reset tetap bersihkan tabel).
        $unitId = DB::table('serial_units')->value('id');
        DB::table('serial_unit_movements')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'serial_unit_id' => $unitId,
            'doc_type' => 'SERIAL_INTAKE',
            'doc_id' => DB::table('doc_serial_intake')->value('id'),
            'movement_type' => 'IN',
            'tanggal' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertGreaterThan(0, DB::table('serial_unit_movements')->count());

        $this->reset('serial_intake')->assertOk();

        $this->assertEquals(0, DB::table('serial_unit_movements')->count());
        $this->assertEquals(0, DB::table('serial_units')->count());
    }

    /**
     * Regresi bug: reset('produk') dulu GAGAL (FK supplier_hutang.serial_intake_id →
     * doc_serial_intake) bila ada hutang dari Pembelian Serial. Setelah perbaikan,
     * case 'produk' ikut men-truncate rantai hutang/deposit dulu, jadi reset SUKSES
     * dan membersihkan tabel serial + hutang.
     *
     */
    #[Test]
    public function reset_produk_dengan_hutang_serial_sukses()
    {
        $this->approveIntake();
        $this->assertGreaterThan(0, DB::table('supplier_hutang')->whereNotNull('serial_intake_id')->count());

        $this->reset('produk')->assertOk();

        // Serial + hutang ikut terbersihkan, tak ada orphan
        $this->assertSame(0, DB::table('doc_serial_intake')->count());
        $this->assertSame(0, DB::table('serial_units')->count());
        $this->assertSame(0, DB::table('supplier_hutang')->count());
    }

    /**
     * Kontrol pembuktian akar masalah: tanpa hutang serial (intake DRAFT, belum approve
     * → tidak ada supplier_hutang), reset('produk') sukses & membersihkan tabel serial.
     * Membuktikan kegagalan di test _BUG murni karena FK supplier_hutang.serial_intake_id.
     *
     */
    #[Test]
    public function reset_produk_tanpa_hutang_serial_sukses_membersihkan_serial()
    {
        // Bikin unit serial langsung (tanpa approve → tanpa supplier_hutang).
        DB::table('serial_units')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'product_id' => $this->produk->id,
            'warehouse_id' => $this->wh->id,
            'serial_number' => 'SN-NOHUTANG',
            'harga_modal' => 1000,
            'status' => 'tersedia',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertEquals(0, DB::table('supplier_hutang')->count());
        $this->assertGreaterThan(0, DB::table('serial_units')->count());

        $this->reset('produk')->assertOk();

        $this->assertEquals(0, DB::table('serial_units')->count());
        $this->assertEquals(0, DB::table('master_produk')->count());
    }
    #[Test]
    public function reset_master_membersihkan_tabel_serial()
    {
        $this->approveIntake();

        $this->reset('master')->assertOk();

        $this->assertEquals(0, DB::table('serial_units')->count());
        $this->assertEquals(0, DB::table('doc_serial_intake')->count());
        $this->assertEquals(0, DB::table('master_produk')->count());
    }
    #[Test]
    public function reset_all_membersihkan_tabel_serial()
    {
        $this->approveIntake();

        $this->reset('all')->assertOk();

        $this->assertEquals(0, DB::table('serial_units')->count());
        $this->assertEquals(0, DB::table('doc_serial_intake')->count());
        $this->assertEquals(0, DB::table('serial_unit_movements')->count());
    }
    #[Test]
    public function reset_serial_change_tidak_menghapus_serial_units_atau_intake()
    {
        // reset('serial_change') HANYA membersihkan doc_serial_change*, bukan unit/intake.
        $this->approveIntake();
        $unitsBefore = DB::table('serial_units')->count();
        $intakeBefore = DB::table('doc_serial_intake')->count();
        $this->assertGreaterThan(0, $unitsBefore);

        $this->reset('serial_change')->assertOk();

        $this->assertEquals(0, DB::table('doc_serial_change')->count());
        $this->assertEquals(0, DB::table('doc_serial_change_detail')->count());
        // Unit & intake TIDAK boleh ikut terhapus.
        $this->assertEquals($unitsBefore, DB::table('serial_units')->count());
        $this->assertEquals($intakeBefore, DB::table('doc_serial_intake')->count());
    }
    #[Test]
    public function reset_serial_hpp_correction_tidak_menghapus_serial_units()
    {
        $this->approveIntake();
        $unitsBefore = DB::table('serial_units')->count();

        $this->reset('serial_hpp_correction')->assertOk();

        $this->assertEquals(0, DB::table('doc_serial_hpp_correction')->count());
        $this->assertEquals(0, DB::table('doc_serial_hpp_correction_detail')->count());
        $this->assertEquals($unitsBefore, DB::table('serial_units')->count());
    }
    #[Test]
    public function reset_supplier_membersihkan_serial_dan_hutang_serial()
    {
        // Supplier reset harus turut menghapus intake serial + unit + hutang sumber serial.
        $this->approveIntake();
        $this->assertGreaterThan(0, DB::table('serial_units')->count());

        $this->reset('supplier')->assertOk();

        $this->assertEquals(0, DB::table('serial_units')->count());
        $this->assertEquals(0, DB::table('doc_serial_intake')->count());
        $this->assertEquals(0, DB::table('master_supplier')->count());
        $this->assertEquals(0, DB::table('supplier_hutang')->whereNotNull('serial_intake_id')->count());
    }
}
