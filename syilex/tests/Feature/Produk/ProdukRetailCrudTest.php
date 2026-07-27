<?php

namespace Tests\Feature\Produk;

use App\Models\MasterProduk;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 3 — Produk Retail (non-serial) CRUD lifecycle via API. ProdukSerialTest
 * sudah menguji create/update non-serial secara umum (validasi konversi/harga,
 * is_serial immutability); test ini menambah yang belum ada: update satu field
 * harga (harga_4), soft delete via destroy(), dan endpoint list() yang secara
 * sengaja TIDAK men-strip harga (dipakai dropdown POS/form — lihat
 * ProdukController::list, tidak ada gate stok.view_hpp/po.view_harga di sana).
 */
class ProdukRetailCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['produk.create', 'produk.update', 'produk.view', 'produk.delete'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['produk.create', 'produk.update', 'produk.view', 'produk.delete']);
        $this->actingAs($this->admin);

        // Mode MANUAL (bukan default 'auto') supaya harga_2/3/4 dipertahankan
        // apa adanya (bukan direkalkulasi otomatis dari harga_1) — perlu untuk
        // menguji update satu field harga_4 secara independen.
        SettingService::set('product.price_input_mode', 'manual', 'string');
    }

    /**
     * Payload retail 4-unit TANPA auto-lock (konversi_1/2/3 semua != 1, hanya
     * konversi_4 = 1 sesuai aturan wajib) supaya harga_4 boleh berbeda dari
     * harga unit lain — konversi menurun ketat: 8 > 4 > 2 >= 1, PPU naik ketat.
     */
    private function retailPayload(array $overrides = []): array
    {
        return array_merge([
            'kode_produk' => 'RTL_CRUD',
            'nama_produk' => 'Kabel HDMI',
            'status' => 'active',
            'is_serial' => false,
            'minimum_stok' => 5,
            'unit_1' => 'KARTON', 'konversi_1' => 8, 'harga_1' => 800000,
            'unit_2' => 'BOX', 'konversi_2' => 4, 'harga_2' => 440000,
            'unit_3' => 'PAK', 'konversi_3' => 2, 'harga_3' => 240000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 130000,
        ], $overrides);
    }
    #[Test]
    public function create_non_serial_produk_via_api(): void
    {
        $res = $this->postJson('/api/v1/produks', $this->retailPayload())
            ->assertCreated();

        $res->assertJsonPath('data.produk.kode_produk', 'RTL_CRUD');
        $res->assertJsonPath('data.produk.is_serial', false);

        $p = MasterProduk::where('kode_produk', 'RTL_CRUD')->first();
        $this->assertNotNull($p);
        $this->assertEquals(800000, (float) $p->harga_1);
        $this->assertEquals(5, $p->minimum_stok);
    }
    #[Test]
    public function update_harga_4_only_persists_new_value(): void
    {
        $this->postJson('/api/v1/produks', $this->retailPayload())->assertCreated();
        $p = MasterProduk::where('kode_produk', 'RTL_CRUD')->first();

        $this->putJson('/api/v1/produks/' . $p->ulid, $this->retailPayload([
            'harga_4' => 150000, // masih < harga_3 (240000), PPU tetap naik
        ]))->assertOk()
            ->assertJsonPath('data.produk.harga_4', '150000.00');

        $p->refresh();
        $this->assertEquals(150000, (float) $p->harga_4);
        // Harga unit lain tidak ikut berubah.
        $this->assertEquals(800000, (float) $p->harga_1);
        $this->assertEquals(440000, (float) $p->harga_2);
        $this->assertEquals(240000, (float) $p->harga_3);
    }
    #[Test]
    public function update_harga_4_rejected_when_not_less_than_harga_3_in_manual_mode(): void
    {
        // Mode manual (di-set di setUp); harga_4 harus < harga_3 (240000).
        // Menaikkan harga_4 >= harga_3 harus ditolak 422 (harga tidak turun ke unit terkecil).
        $this->postJson('/api/v1/produks', $this->retailPayload())->assertCreated();
        $p = MasterProduk::where('kode_produk', 'RTL_CRUD')->first();

        $this->putJson('/api/v1/produks/' . $p->ulid, $this->retailPayload([
            'harga_4' => 250000, // >= harga_3 (240000) → ditolak
        ]))->assertStatus(422);

        $this->assertEquals(130000, (float) $p->fresh()->harga_4, 'Harga tidak ikut berubah saat update ditolak.');
    }
    #[Test]
    public function soft_delete_produk_without_stock_or_history(): void
    {
        $this->postJson('/api/v1/produks', $this->retailPayload())->assertCreated();
        $p = MasterProduk::where('kode_produk', 'RTL_CRUD')->first();

        $this->deleteJson('/api/v1/produks/' . $p->ulid)->assertOk();

        $this->assertSoftDeleted('master_produk', ['id' => $p->id]);
        // show() setelah soft delete tidak lagi menemukan produk (scope default exclude trashed).
        $this->getJson('/api/v1/produks/' . $p->ulid)->assertStatus(404);
    }
    #[Test]
    public function delete_blocked_when_produk_still_has_stock(): void
    {
        $this->postJson('/api/v1/produks', $this->retailPayload(['kode_produk' => 'RTL_STOK']))->assertCreated();
        $p = MasterProduk::where('kode_produk', 'RTL_STOK')->first();

        $warehouse = \App\Models\MasterWarehouse::factory()->create(['status' => 'active']);
        \App\Models\InventoryStock::updateOrCreate(
            ['product_id' => $p->id, 'warehouse_id' => $warehouse->id],
            ['qty' => 3, 'avg_cost' => 1000]
        );

        $this->deleteJson('/api/v1/produks/' . $p->ulid)->assertStatus(422);
        $this->assertDatabaseHas('master_produk', ['id' => $p->id, 'deleted_at' => null]);
    }
    #[Test]
    public function list_endpoint_returns_200_with_harga_present_for_any_authenticated_user(): void
    {
        $this->postJson('/api/v1/produks', $this->retailPayload())->assertCreated();

        // list() endpoint (dropdown POS/form) SENGAJA tidak men-strip harga dan
        // tidak digate permission khusus (lihat ProdukController::list) — cek
        // dengan user TANPA permission apapun untuk membuktikan ini bukan
        // kebetulan hasil permission admin.
        $noPerm = User::factory()->create();

        $res = $this->actingAs($noPerm)
            ->getJson('/api/v1/produks/list?search=RTL_CRUD')
            ->assertOk();

        $items = $res->json('data.produks');
        $this->assertNotEmpty($items);
        $this->assertArrayHasKey('harga_4', $items[0]);
        $this->assertEquals(130000, (float) $items[0]['harga_4']);
    }
    #[Test]
    public function create_requires_produk_create_permission(): void
    {
        $noPerm = User::factory()->create();

        $this->actingAs($noPerm)
            ->postJson('/api/v1/produks', $this->retailPayload(['kode_produk' => 'RTL_NOPERM']))
            ->assertStatus(403);

        $this->assertNull(MasterProduk::where('kode_produk', 'RTL_NOPERM')->first());
    }
    #[Test]
    public function delete_requires_produk_delete_permission(): void
    {
        $this->postJson('/api/v1/produks', $this->retailPayload(['kode_produk' => 'RTL_DELPERM']))->assertCreated();
        $p = MasterProduk::where('kode_produk', 'RTL_DELPERM')->first();

        $editorOnly = User::factory()->create();
        $editorOnly->givePermissionTo(['produk.view', 'produk.update']);

        $this->actingAs($editorOnly)
            ->deleteJson('/api/v1/produks/' . $p->ulid)
            ->assertStatus(403);

        $this->assertDatabaseHas('master_produk', ['id' => $p->id, 'deleted_at' => null]);
    }
}
