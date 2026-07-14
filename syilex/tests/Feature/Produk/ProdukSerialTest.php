<?php

namespace Tests\Feature\Produk;

use App\Models\MasterProduk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Modul Serial (A+) — toggle is_serial di Master Produk.
 * Serial: form minimal, auto-scaffold (UNIT/1/0). Retail: tak berubah. is_serial immutable saat edit.
 */
class ProdukSerialTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['produk.create', 'produk.update', 'produk.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['produk.create', 'produk.update', 'produk.view']);
    }

    private function serialPayload(array $o = []): array
    {
        return array_merge([
            'kode_produk' => 'SRL_A', 'nama_produk' => 'MacBook Air M2',
            'status' => 'active', 'is_serial' => true,
        ], $o);
    }
    #[Test]
    public function serial_minimal_auto_scaffolds()
    {
        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload())
            ->assertStatus(201);

        $p = MasterProduk::where('kode_produk', 'SRL_A')->first();
        $this->assertTrue((bool) $p->is_serial);
        $this->assertSame('UNIT', $p->unit_1);
        $this->assertSame('UNIT', $p->unit_4);
        $this->assertEquals(1, $p->konversi_1);
        $this->assertEquals(0, (float) $p->harga_1);
        $this->assertEquals(0, $p->minimum_stok);
        $this->assertNull($p->barcode);
    }
    #[Test]
    public function two_serial_without_barcode_coexist()
    {
        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload(['kode_produk' => 'SRL_B']))->assertStatus(201);
        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload(['kode_produk' => 'SRL_C', 'nama_produk' => 'MacBook Pro M3']))->assertStatus(201);

        $this->assertEquals(2, MasterProduk::whereNull('barcode')->where('is_serial', true)->count());
    }
    #[Test]
    public function retail_product_unchanged()
    {
        $this->actingAs($this->admin)->postJson('/api/v1/produks', [
            'kode_produk' => 'RTL_A', 'nama_produk' => 'Charger', 'status' => 'active', 'is_serial' => false,
            'minimum_stok' => 5,
            'unit_1' => 'PCS', 'konversi_1' => 1, 'harga_1' => 50000,
            'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 50000,
            'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 50000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 50000,
        ])->assertStatus(201);

        $p = MasterProduk::where('kode_produk', 'RTL_A')->first();
        $this->assertFalse((bool) $p->is_serial);
        $this->assertEquals(50000, (float) $p->harga_1);
        $this->assertEquals(5, $p->minimum_stok);
    }
    #[Test]
    public function serial_required_fields_still_enforced()
    {
        // kode + nama tetap wajib walau serial
        $this->actingAs($this->admin)->postJson('/api/v1/produks', ['status' => 'active', 'is_serial' => true])
            ->assertStatus(422);
    }
    #[Test]
    public function is_serial_immutable_on_update()
    {
        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload(['kode_produk' => 'SRL_D']))->assertStatus(201);
        $p = MasterProduk::where('kode_produk', 'SRL_D')->first();

        // coba ubah is_serial=false saat update → harus diabaikan (tetap serial)
        $this->actingAs($this->admin)->putJson('/api/v1/produks/' . $p->ulid, [
            'nama_produk' => 'MacBook Air M2 Updated', 'status' => 'active', 'is_serial' => false,
        ])->assertStatus(200);

        $this->assertTrue((bool) $p->fresh()->is_serial, 'is_serial harus immutable saat edit');
    }
    #[Test]
    public function serial_scaffolding_overrides_supplied_unit_price_barcode()
    {
        // Walau frontend kirim barcode/satuan/harga/min-stok, serial WAJIB di-scaffold (diabaikan)
        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload([
            'kode_produk' => 'SRL_OVR', 'barcode' => '9990001',
            'minimum_stok' => 99,
            'unit_1' => 'KARTON', 'konversi_1' => 50, 'harga_1' => 7000000,
            'unit_2' => 'BOX', 'konversi_2' => 10, 'harga_2' => 1500000,
            'unit_3' => 'PAK', 'konversi_3' => 5, 'harga_3' => 800000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 200000,
        ]))->assertStatus(201);

        $p = MasterProduk::where('kode_produk', 'SRL_OVR')->first();
        $this->assertSame('UNIT', $p->unit_1);
        $this->assertSame('UNIT', $p->unit_2);
        $this->assertSame('UNIT', $p->unit_3);
        $this->assertSame('UNIT', $p->unit_4);
        foreach ([1, 2, 3, 4] as $i) {
            $this->assertEquals(1, $p->{"konversi_{$i}"});
            $this->assertEquals(0, (float) $p->{"harga_{$i}"});
        }
        $this->assertEquals(0, $p->minimum_stok);
        $this->assertNull($p->barcode, 'Barcode serial harus dipaksa null walau dikirim');
    }
    #[Test]
    public function serial_update_keeps_scaffold_and_ignores_unit_price_changes()
    {
        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload(['kode_produk' => 'SRL_UPD']))->assertStatus(201);
        $p = MasterProduk::where('kode_produk', 'SRL_UPD')->first();

        // Edit serial: coba inject harga/satuan/barcode → tetap scaffold
        $this->actingAs($this->admin)->putJson('/api/v1/produks/' . $p->ulid, [
            'nama_produk' => 'MacBook Air M2 Rev', 'status' => 'active',
            'barcode' => '12345', 'minimum_stok' => 7,
            'unit_1' => 'BOX', 'konversi_1' => 5, 'harga_1' => 5000000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 100000,
        ])->assertStatus(200);

        $p->refresh();
        $this->assertSame('UNIT', $p->unit_1);
        $this->assertSame('UNIT', $p->unit_4);
        $this->assertEquals(0, (float) $p->harga_1);
        $this->assertEquals(0, $p->minimum_stok);
        $this->assertNull($p->barcode);
        // Nama disimpan trim (case dipertahankan; uppercase hanya jika setting text.uppercase_mode='all')
        $this->assertSame('MacBook Air M2 Rev', $p->nama_produk);
    }
    #[Test]
    public function kode_produk_is_immutable_on_update()
    {
        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload(['kode_produk' => 'SRL_KODE']))->assertStatus(201);
        $p = MasterProduk::where('kode_produk', 'SRL_KODE')->first();

        // Kirim kode_produk baru saat update → diabaikan (kode immutable)
        $this->actingAs($this->admin)->putJson('/api/v1/produks/' . $p->ulid, [
            'kode_produk' => 'SRL_GANTI', 'nama_produk' => 'Tetap', 'status' => 'active',
        ])->assertStatus(200);

        $this->assertSame('SRL_KODE', $p->fresh()->kode_produk);
        $this->assertNull(MasterProduk::where('kode_produk', 'SRL_GANTI')->first());
    }
    #[Test]
    public function retail_product_stays_retail_and_enforces_full_validation_on_update()
    {
        // Buat retail valid
        $this->actingAs($this->admin)->postJson('/api/v1/produks', [
            'kode_produk' => 'RTL_UPD', 'nama_produk' => 'Charger', 'status' => 'active', 'is_serial' => false,
            'minimum_stok' => 5,
            'unit_1' => 'BOX', 'konversi_1' => 10, 'harga_1' => 90000,
            'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 10000,
            'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 10000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 10000,
        ])->assertStatus(201);
        $p = MasterProduk::where('kode_produk', 'RTL_UPD')->first();

        // Update retail TANPA unit/harga (kirim is_serial=true) → tetap retail, validasi unit wajib → 422
        $this->actingAs($this->admin)->putJson('/api/v1/produks/' . $p->ulid, [
            'nama_produk' => 'Charger Baru', 'status' => 'active', 'is_serial' => true,
        ])->assertStatus(422);

        $this->assertFalse((bool) $p->fresh()->is_serial, 'Retail tak boleh jadi serial via update');
    }
    #[Test]
    public function create_requires_permission()
    {
        $this->actingAs(User::factory()->create()) // tanpa produk.create
            ->postJson('/api/v1/produks', $this->serialPayload(['kode_produk' => 'SRL_NOPERM']))
            ->assertStatus(403);

        $this->assertNull(MasterProduk::where('kode_produk', 'SRL_NOPERM')->first());
    }
    #[Test]
    public function duplicate_kode_produk_is_rejected()
    {
        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload(['kode_produk' => 'SRL_DUP']))->assertStatus(201);

        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload(['kode_produk' => 'SRL_DUP', 'nama_produk' => 'Lain']))
            ->assertStatus(422)->assertJsonValidationErrors('kode_produk');

        $this->assertSame(1, MasterProduk::where('kode_produk', 'SRL_DUP')->count());
    }
    #[Test]
    public function invalid_kode_produk_with_space_is_rejected()
    {
        $this->actingAs($this->admin)->postJson('/api/v1/produks', $this->serialPayload(['kode_produk' => 'SRL A']))
            ->assertStatus(422)->assertJsonValidationErrors('kode_produk');
    }
    #[Test]
    public function retail_with_non_decreasing_konversi_is_rejected()
    {
        // konversi_1 (5) < konversi_2 (10) → langgar aturan urut menurun
        $this->actingAs($this->admin)->postJson('/api/v1/produks', [
            'kode_produk' => 'RTL_BADK', 'nama_produk' => 'Salah Konversi', 'status' => 'active', 'is_serial' => false,
            'minimum_stok' => 1,
            'unit_1' => 'BOX', 'konversi_1' => 5, 'harga_1' => 50000,
            'unit_2' => 'PAK', 'konversi_2' => 10, 'harga_2' => 30000,
            'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 10000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 10000,
        ])->assertStatus(422);

        $this->assertNull(MasterProduk::where('kode_produk', 'RTL_BADK')->first());
    }
    #[Test]
    public function retail_with_zero_price_is_rejected()
    {
        // harga retail wajib > 0 (gt:0)
        $this->actingAs($this->admin)->postJson('/api/v1/produks', [
            'kode_produk' => 'RTL_ZERO', 'nama_produk' => 'Harga Nol', 'status' => 'active', 'is_serial' => false,
            'minimum_stok' => 1,
            'unit_1' => 'PCS', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 0,
        ])->assertStatus(422)->assertJsonValidationErrors('harga_1');

        $this->assertNull(MasterProduk::where('kode_produk', 'RTL_ZERO')->first());
    }
}
