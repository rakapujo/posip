<?php

namespace Tests\Feature\Enhancements;

use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * E4 — Deposit Supplier usage history endpoint.
 */
class DepositUsageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;
    protected int $supplierId;
    protected int $warehouseId;
    protected int $poId;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'deposit-supplier.view', 'guard_name' => 'web']);
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo('deposit-supplier.view');

        $this->supplierId = DB::table('master_supplier')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-DU',
            'nama_supplier' => 'Supplier DU',
            'nama_pic' => 'PIC',
            'telepon' => '08000',
            'tempo_default' => 30,
            'status' => 'active',
            'created_by' => $this->viewer->id,
            'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $wh = MasterWarehouse::factory()->create(['created_by' => $this->viewer->id]);
        $this->warehouseId = $wh->id;

        $this->poId = DB::table('doc_purchase_order')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PO-DU-001',
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId,
            'status' => 'approved',
            'created_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function createDeposit(float $nominalAwal, float $nominalTerpakai = 0): array
    {
        // retur_id NOT NULL di SQLite — buat PR dummy
        $returId = DB::table('doc_purchase_return')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-' . fake()->unique()->numerify('######'),
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplierId,
            'warehouse_id' => $this->warehouseId,
            'po_id' => $this->poId,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ulid = (string) Str::ulid();
        $id = DB::table('supplier_deposit')->insertGetId([
            'ulid' => $ulid,
            'supplier_id' => $this->supplierId,
            'retur_id' => $returId,
            'tanggal' => now()->toDateString(),
            'nominal_awal' => $nominalAwal,
            'nominal_terpakai' => $nominalTerpakai,
            'sisa_deposit' => $nominalAwal - $nominalTerpakai,
            'status' => $nominalTerpakai > 0 ? ($nominalTerpakai >= $nominalAwal ? 'used_all' : 'used_partial') : 'available',
            'created_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        return ['id' => $id, 'ulid' => $ulid];
    }

    private function createPembayaranWithDepositUsage(int $depositId, float $nominalDigunakan, string $status = 'completed'): void
    {
        $pId = DB::table('doc_pembayaran_hutang')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PBH-' . fake()->unique()->numerify('######'),
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplierId,
            'total_bayar_cash' => 0,
            'total_bayar_deposit' => $nominalDigunakan,
            'total_pembayaran' => $nominalDigunakan,
            'metode_pembayaran' => 'cash',
            'status' => $status,
            'created_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('doc_pembayaran_hutang_deposit')->insert([
            'pembayaran_id' => $pId,
            'deposit_id' => $depositId,
            'nominal_digunakan' => $nominalDigunakan,
        ]);
    }

    public function test_requires_permission(): void
    {
        $dep = $this->createDeposit(1_000_000);
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson("/api/v1/supplier-deposits/{$dep['ulid']}/usage")
            ->assertForbidden();
    }

    public function test_404_for_unknown_deposit(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/supplier-deposits/01HXFAKE/usage')
            ->assertNotFound();
    }

    public function test_returns_usage_history_sorted_by_tanggal_desc(): void
    {
        $dep = $this->createDeposit(2_000_000, 1_500_000);
        $this->createPembayaranWithDepositUsage($dep['id'], 500_000);
        $this->createPembayaranWithDepositUsage($dep['id'], 1_000_000);

        $response = $this->actingAs($this->viewer)
            ->getJson("/api/v1/supplier-deposits/{$dep['ulid']}/usage")
            ->assertOk();

        $data = $response->json('data');

        $this->assertEquals(2_000_000, $data['deposit']['nominal_awal']);
        $this->assertEquals(1_500_000, $data['deposit']['nominal_terpakai']);
        $this->assertEquals(500_000, $data['deposit']['sisa_deposit']);

        $this->assertEquals(2, $data['usage_count']);
        $this->assertEquals(1_500_000, $data['total_used_from_history']);
        $this->assertCount(2, $data['items']);
        $this->assertEquals('SUP-DU', $data['items'][0]['supplier']['kode']);
    }

    public function test_empty_usage_returns_zero_items(): void
    {
        $dep = $this->createDeposit(1_000_000);

        $response = $this->actingAs($this->viewer)
            ->getJson("/api/v1/supplier-deposits/{$dep['ulid']}/usage")
            ->assertOk();

        $data = $response->json('data');
        $this->assertEquals(0, $data['usage_count']);
        $this->assertEmpty($data['items']);
    }

    public function test_other_deposits_usage_not_mixed(): void
    {
        $dep1 = $this->createDeposit(1_000_000, 300_000);
        $dep2 = $this->createDeposit(500_000, 100_000);

        $this->createPembayaranWithDepositUsage($dep1['id'], 300_000);
        $this->createPembayaranWithDepositUsage($dep2['id'], 100_000);

        $response = $this->actingAs($this->viewer)
            ->getJson("/api/v1/supplier-deposits/{$dep1['ulid']}/usage")
            ->assertOk();

        $this->assertEquals(1, $response->json('data.usage_count'));
        $this->assertEquals(300_000, $response->json('data.items.0.nominal_digunakan'));
    }

    // ==================== EDGE CASES (galak, assertion eksak) ====================

    /**
     * Endpoint usage TIDAK memfilter status pembayaran — pemakaian dari pembayaran
     * draft tetap muncul di history. Verifikasi total & count eksak termasuk draft.
     */
    public function test_usage_termasuk_pembayaran_draft(): void
    {
        $dep = $this->createDeposit(1_000_000, 700_000);
        $this->createPembayaranWithDepositUsage($dep['id'], 400_000, 'completed');
        $this->createPembayaranWithDepositUsage($dep['id'], 300_000, 'draft');

        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/supplier-deposits/{$dep['ulid']}/usage")
            ->assertOk()
            ->json('data');

        $this->assertEquals(2, $data['usage_count']);
        $this->assertEquals(700_000, $data['total_used_from_history']); // 400k + 300k draft
        $this->assertCount(2, $data['items']);
        // Salah satu item berstatus draft
        $statuses = collect($data['items'])->pluck('status')->all();
        $this->assertContains('draft', $statuses);
        $this->assertContains('completed', $statuses);
    }

    /**
     * Urutan items: tanggal pembayaran DESC. Pakai 3 tanggal berbeda agar urutan
     * tidak ambigu. Item paling baru harus di indeks 0.
     */
    public function test_items_terurut_tanggal_desc_eksak(): void
    {
        $dep = $this->createDeposit(5_000_000, 600_000);

        // Buat 3 pembayaran dengan tanggal eksplisit berbeda
        $this->createPembayaranWithDepositUsageTanggal($dep['id'], 100_000, '2024-01-10');
        $this->createPembayaranWithDepositUsageTanggal($dep['id'], 200_000, '2024-03-20'); // terbaru
        $this->createPembayaranWithDepositUsageTanggal($dep['id'], 300_000, '2024-02-15');

        $items = $this->actingAs($this->viewer)
            ->getJson("/api/v1/supplier-deposits/{$dep['ulid']}/usage")
            ->assertOk()
            ->json('data.items');

        $this->assertCount(3, $items);
        $this->assertEquals('2024-03-20', $items[0]['tanggal']);
        $this->assertEquals(200_000, $items[0]['nominal_digunakan']);
        $this->assertEquals('2024-02-15', $items[1]['tanggal']);
        $this->assertEquals('2024-01-10', $items[2]['tanggal']);
    }

    /**
     * Saldo deposit yang dilaporkan = nominal_awal - nominal_terpakai eksak,
     * dan total_used_from_history independen dari snapshot nominal_terpakai
     * (history boleh berbeda kalau ada drift). Di sini keduanya sengaja konsisten.
     */
    public function test_saldo_deposit_konsisten_eksak(): void
    {
        $dep = $this->createDeposit(3_000_000, 1_250_000);
        $this->createPembayaranWithDepositUsage($dep['id'], 750_000);
        $this->createPembayaranWithDepositUsage($dep['id'], 500_000);

        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/supplier-deposits/{$dep['ulid']}/usage")
            ->assertOk()
            ->json('data');

        $this->assertEquals(3_000_000, $data['deposit']['nominal_awal']);
        $this->assertEquals(1_250_000, $data['deposit']['nominal_terpakai']);
        $this->assertEquals(1_750_000, $data['deposit']['sisa_deposit']); // awal - terpakai
        $this->assertEquals(1_250_000, $data['total_used_from_history']); // 750k + 500k
    }

    /**
     * SupplierDeposit::use() — saldo berkurang EKSAK saat pemakaian normal
     * (≤ sisa). Memvalidasi model yang menyuplai field saldo di endpoint usage.
     */
    public function test_model_use_mengurangi_saldo_eksak(): void
    {
        $dep = $this->createDeposit(1_000_000);
        $model = \App\Models\SupplierDeposit::find($dep['id']);

        $actual = $model->use(400_000);

        $this->assertEquals(400_000.0, $actual);
        $fresh = \App\Models\SupplierDeposit::find($dep['id']);
        $this->assertEquals(400_000, $fresh->nominal_terpakai);
        $this->assertEquals(600_000, $fresh->sisa_deposit);
        $this->assertEquals('used_partial', $fresh->status);
    }

    /**
     * SupplierDeposit::use() — pemakaian MELEBIHI sisa di-cap ke sisa (tidak boleh
     * saldo negatif), status jadi used_all. Ini guard "melebihi ditolak" di level
     * model: actual_used = min(amount, sisa) dan saldo TIDAK pernah < 0.
     */
    public function test_model_use_melebihi_sisa_dibatasi_tidak_negatif(): void
    {
        $dep = $this->createDeposit(500_000, 200_000); // sisa 300k
        $model = \App\Models\SupplierDeposit::find($dep['id']);

        $actual = $model->use(1_000_000); // minta lebih dari sisa 300k

        $this->assertEquals(300_000.0, $actual); // dibatasi ke sisa
        $fresh = \App\Models\SupplierDeposit::find($dep['id']);
        $this->assertEquals(500_000, $fresh->nominal_terpakai); // 200k + 300k
        $this->assertEquals(0, $fresh->sisa_deposit); // tidak negatif
        $this->assertEquals('used_all', $fresh->status);
    }

    /**
     * Pemakaian dari supplier BERBEDA tetap dilaporkan dengan data supplier eksak
     * (deposit di-share antar pembayaran lintas supplier — endpoint hanya filter
     * by deposit_id, bukan supplier). Memastikan kode/nama supplier per item benar.
     */
    public function test_items_membawa_data_supplier_eksak(): void
    {
        $dep = $this->createDeposit(2_000_000, 400_000);

        // Supplier kedua
        $supplier2 = DB::table('master_supplier')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-DU2',
            'nama_supplier' => 'Supplier DU Dua',
            'nama_pic' => 'PIC2', 'telepon' => '08002',
            'tempo_default' => 30, 'status' => 'active',
            'created_by' => $this->viewer->id, 'updated_by' => $this->viewer->id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->createPembayaranWithDepositUsage($dep['id'], 400_000); // supplier utama (SUP-DU)
        // Pembayaran oleh supplier2 memakai deposit yang sama
        $pId = DB::table('doc_pembayaran_hutang')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PBH-S2',
            'tanggal' => now()->subDay()->toDateString(),
            'supplier_id' => $supplier2,
            'total_bayar_cash' => 0, 'total_bayar_deposit' => 100_000,
            'total_pembayaran' => 100_000, 'metode_pembayaran' => 'cash',
            'status' => 'completed', 'created_by' => $this->viewer->id,
            'created_at' => now()->subDay(), 'updated_at' => now()->subDay(),
        ]);
        DB::table('doc_pembayaran_hutang_deposit')->insert([
            'pembayaran_id' => $pId, 'deposit_id' => $dep['id'], 'nominal_digunakan' => 100_000,
        ]);

        $data = $this->actingAs($this->viewer)
            ->getJson("/api/v1/supplier-deposits/{$dep['ulid']}/usage")
            ->assertOk()
            ->json('data');

        $this->assertEquals(2, $data['usage_count']);
        $this->assertEquals(500_000, $data['total_used_from_history']); // 400k + 100k
        $bySupplier = collect($data['items'])->keyBy(fn ($i) => $i['supplier']['kode']);
        $this->assertEquals('Supplier DU', $bySupplier->get('SUP-DU')['supplier']['nama']);
        $this->assertEquals('Supplier DU Dua', $bySupplier->get('SUP-DU2')['supplier']['nama']);
        $this->assertEquals(400_000, $bySupplier->get('SUP-DU')['nominal_digunakan']);
        $this->assertEquals(100_000, $bySupplier->get('SUP-DU2')['nominal_digunakan']);
    }

    private function createPembayaranWithDepositUsageTanggal(int $depositId, float $nominalDigunakan, string $tanggal): void
    {
        $pId = DB::table('doc_pembayaran_hutang')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'PBH-' . fake()->unique()->numerify('######'),
            'tanggal' => $tanggal,
            'supplier_id' => $this->supplierId,
            'total_bayar_cash' => 0,
            'total_bayar_deposit' => $nominalDigunakan,
            'total_pembayaran' => $nominalDigunakan,
            'metode_pembayaran' => 'cash',
            'status' => 'completed',
            'created_by' => $this->viewer->id,
            'created_at' => $tanggal, 'updated_at' => $tanggal,
        ]);
        DB::table('doc_pembayaran_hutang_deposit')->insert([
            'pembayaran_id' => $pId,
            'deposit_id' => $depositId,
            'nominal_digunakan' => $nominalDigunakan,
        ]);
    }
}
