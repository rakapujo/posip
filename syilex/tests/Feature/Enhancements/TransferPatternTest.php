<?php

namespace Tests\Feature\Enhancements;

use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * E6 — Transfer Pattern summary endpoint.
 */
class TransferPatternTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;
    protected int $whPusatId;
    protected int $whCabangId;
    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['transfer.view', 'stok.view_hpp'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }
        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo(['transfer.view', 'stok.view_hpp']);

        $pusat = MasterWarehouse::factory()->create(['kode_warehouse' => 'PUSAT', 'nama_warehouse' => 'Gudang Pusat', 'status' => 'active']);
        $cabang = MasterWarehouse::factory()->create(['kode_warehouse' => 'CABANG', 'nama_warehouse' => 'Cabang A', 'status' => 'active']);
        $this->whPusatId = $pusat->id;
        $this->whCabangId = $cabang->id;

        $this->product = MasterProduk::factory()->create(['avg_cost' => 1000, 'status' => 'active']);
    }

    private function makeTransfer(int $fromId, int $toId, float $qty, string $status = 'approved', ?string $tanggal = null): int
    {
        $tanggal ??= now()->toDateString();
        $id = DB::table('doc_transfer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'TRF-' . fake()->unique()->numerify('######'),
            'warehouse_from_id' => $fromId,
            'warehouse_to_id' => $toId,
            'tanggal' => $tanggal,
            'status' => $status,
            'created_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('doc_transfer_detail')->insert([
            'ulid' => (string) Str::ulid(),
            'transfer_id' => $id,
            'product_id' => $this->product->id,
            'qty' => $qty,
        ]);
        return $id;
    }

    /**
     * Transfer dengan beberapa detail produk (frekuensi tetap 1 dokumen).
     * @param array<array{product_id:int, qty:float}> $details
     */
    private function makeTransferMultiDetail(int $fromId, int $toId, array $details, string $status = 'approved', ?string $tanggal = null): int
    {
        $tanggal ??= now()->toDateString();
        $id = DB::table('doc_transfer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'TRF-' . fake()->unique()->numerify('######'),
            'warehouse_from_id' => $fromId,
            'warehouse_to_id' => $toId,
            'tanggal' => $tanggal,
            'status' => $status,
            'created_by' => $this->viewer->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach ($details as $d) {
            DB::table('doc_transfer_detail')->insert([
                'ulid' => (string) Str::ulid(),
                'transfer_id' => $id,
                'product_id' => $d['product_id'],
                'qty' => $d['qty'],
            ]);
        }
        return $id;
    }

    public function test_requires_transfer_view_permission(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertForbidden();
    }

    public function test_aggregates_per_warehouse_pair(): void
    {
        // 3 transfer Pusat → Cabang, 1 transfer Cabang → Pusat
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 10);
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 5);
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 20);
        $this->makeTransfer($this->whCabangId, $this->whPusatId, 3);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertOk();

        $items = collect($response->json('data.items'));
        $this->assertCount(2, $items);

        // Top row: Pusat → Cabang (3× frekuensi)
        $first = $items[0];
        $this->assertEquals('PUSAT', $first['from_kode']);
        $this->assertEquals('CABANG', $first['to_kode']);
        $this->assertEquals(3, $first['frekuensi']);
        $this->assertEquals(35, $first['qty_total']); // 10+5+20
        $this->assertEquals(35_000, $first['value_total']); // 35 × 1000

        // Second: Cabang → Pusat
        $this->assertEquals(1, $items[1]['frekuensi']);
        $this->assertEquals(3, $items[1]['qty_total']);
    }

    public function test_draft_transfers_excluded(): void
    {
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 10, status: 'approved');
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 10, status: 'draft');

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals(1, $items[0]['frekuensi']);
    }

    public function test_top_sender_and_receiver_tracked(): void
    {
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 10);
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 5);
        $this->makeTransfer($this->whCabangId, $this->whPusatId, 3);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertOk();

        $this->assertEquals('PUSAT', $response->json('data.top_sender'));
        // Top receiver: Cabang 2× vs Pusat 1×
        $this->assertEquals('CABANG', $response->json('data.top_receiver'));
    }

    public function test_value_null_without_hpp_permission(): void
    {
        $noHpp = User::factory()->create();
        $noHpp->givePermissionTo('transfer.view');

        $this->makeTransfer($this->whPusatId, $this->whCabangId, 10);

        $response = $this->actingAs($noHpp)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertOk();

        $this->assertNull($response->json('data.items.0.value_total'));
    }

    public function test_date_range_filter(): void
    {
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 10, tanggal: '2020-01-15');
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 20); // today

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary?date_from=2020-01-01&date_to=2020-01-31')
            ->assertOk();

        $items = $response->json('data.items');
        $this->assertCount(1, $items);
        $this->assertEquals(10, $items[0]['qty_total']);
    }

    public function test_empty_returns_no_items(): void
    {
        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertOk();

        $this->assertEmpty($response->json('data.items'));
    }

    // ==================== EDGE CASES (galak, assertion eksak) ====================

    /**
     * frekuensi = COUNT(DISTINCT t.id). Satu transfer berisi 2 detail produk tetap
     * dihitung frekuensi 1, namun qty_total & value_total menjumlahkan semua detail.
     */
    public function test_multi_detail_satu_dokumen_frekuensi_satu(): void
    {
        $produkB = MasterProduk::factory()->create(['avg_cost' => 2000, 'status' => 'active']);

        // 1 dokumen, 2 detail: product (avg 1000) qty 4 + produkB (avg 2000) qty 3
        $this->makeTransferMultiDetail($this->whPusatId, $this->whCabangId, [
            ['product_id' => $this->product->id, 'qty' => 4],
            ['product_id' => $produkB->id, 'qty' => 3],
        ]);

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertEquals(1, $items[0]['frekuensi']); // 1 dokumen
        $this->assertEquals(7, $items[0]['qty_total']); // 4 + 3
        // value: 4×1000 + 3×2000 = 10.000
        $this->assertEquals(10_000, $items[0]['value_total']);
    }

    /**
     * Agregasi per PASANGAN gudang: pasangan (A→B) dan (B→A) terpisah, dan dua
     * dokumen pasangan sama digabung. Urut DESC by frekuensi.
     */
    public function test_agregasi_pasangan_dan_value_eksak(): void
    {
        // Pusat→Cabang: 2 dokumen (qty 10 + 5 = 15) → value 15.000
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 10);
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 5);
        // Cabang→Pusat: 1 dokumen (qty 7) → value 7.000
        $this->makeTransfer($this->whCabangId, $this->whPusatId, 7);

        $items = collect($this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertOk()
            ->json('data.items'));

        $pc = $items->first(fn ($r) => $r['from_kode'] === 'PUSAT' && $r['to_kode'] === 'CABANG');
        $cp = $items->first(fn ($r) => $r['from_kode'] === 'CABANG' && $r['to_kode'] === 'PUSAT');

        $this->assertEquals(2, $pc['frekuensi']);
        $this->assertEquals(15, $pc['qty_total']);
        $this->assertEquals(15_000, $pc['value_total']);

        $this->assertEquals(1, $cp['frekuensi']);
        $this->assertEquals(7, $cp['qty_total']);
        $this->assertEquals(7_000, $cp['value_total']);

        // Urutan DESC by frekuensi: PC (2) sebelum CP (1)
        $this->assertEquals('PUSAT', $items[0]['from_kode']);
        $this->assertEquals('CABANG', $items[0]['to_kode']);
    }

    /**
     * BOUNDARY tanggal INKLUSIF: transfer TEPAT di date_from dan TEPAT di date_to
     * (keduanya tengah hari/00:00) harus masuk. Di luar range tidak masuk.
     */
    public function test_boundary_tanggal_inklusif_kedua_ujung(): void
    {
        // Tepat di batas bawah & atas (date-only, tersimpan 00:00:00)
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 11, tanggal: '2023-05-01');
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 22, tanggal: '2023-05-31');
        // Di luar range
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 99, tanggal: '2023-04-30');
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 88, tanggal: '2023-06-01');

        $items = $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary?date_from=2023-05-01&date_to=2023-05-31')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items); // pasangan sama → 1 baris
        $this->assertEquals(2, $items[0]['frekuensi']);
        $this->assertEquals(33, $items[0]['qty_total']); // 11 + 22 (yang di luar dikecualikan)
    }

    /**
     * top_sender & top_receiver dipilih dari frekuensi terbesar. Pusat kirim 3×,
     * Cabang kirim 1×; Cabang terima 3×, Pusat terima 1×.
     */
    public function test_top_sender_receiver_eksak(): void
    {
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 1);
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 1);
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 1);
        $this->makeTransfer($this->whCabangId, $this->whPusatId, 1);

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals('PUSAT', $data['top_sender']);
        $this->assertEquals('CABANG', $data['top_receiver']);
    }

    /**
     * Period default = bulan berjalan (startOfMonth s/d hari ini). Transfer di
     * bulan lalu TIDAK ikut bila tanpa filter date_from/date_to.
     */
    public function test_default_period_bulan_berjalan(): void
    {
        // Transfer hari ini (masuk default), dan bulan lalu (tidak masuk)
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 12); // today
        $this->makeTransfer($this->whPusatId, $this->whCabangId, 99, tanggal: now()->subMonthNoOverflow()->startOfMonth()->toDateString());

        $data = $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary')
            ->assertOk()
            ->json('data');

        $this->assertEquals(now()->startOfMonth()->toDateString(), $data['period']['from']);
        $this->assertEquals(now()->toDateString(), $data['period']['to']);
        $this->assertCount(1, $data['items']);
        $this->assertEquals(12, $data['items'][0]['qty_total']);
    }

    /**
     * date_to < date_from ditolak validasi (after_or_equal) → 422.
     */
    public function test_date_to_sebelum_date_from_ditolak(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/transfers/pattern-summary?date_from=2024-02-01&date_to=2024-01-01')
            ->assertStatus(422);
    }
}
