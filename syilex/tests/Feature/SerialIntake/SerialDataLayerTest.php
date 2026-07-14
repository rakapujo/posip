<?php

namespace Tests\Feature\SerialIntake;

use App\Models\DocSerialIntake;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 2A — data layer modul serial: tabel doc_serial_intake + serial_units, relasi & default.
 */
class SerialDataLayerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // master_produk.created_by NOT NULL → butuh user terotentikasi (HasCreatedUpdatedBy).
        $this->actingAs(User::factory()->create());
    }

    private function serialProduk(): MasterProduk
    {
        return MasterProduk::create([
            'kode_produk' => 'LAP_M2', 'nama_produk' => 'MacBook Air M2', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
    }

    private function warehouse(): MasterWarehouse
    {
        return MasterWarehouse::create([
            'kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang Utama',
            'is_saleable' => true, 'status' => 'active',
        ]);
    }
    #[Test]
    public function intake_with_units_resolves_relations_and_defaults()
    {
        $produk = $this->serialProduk();
        $wh = $this->warehouse();

        $intake = DocSerialIntake::create([
            'nomor_dokumen' => 'PBS-0001', 'tanggal' => now(),
            'product_id' => $produk->id, 'warehouse_id' => $wh->id,
            'total_unit' => 2, 'total_modal' => 30000000, 'status' => 'completed',
        ]);

        $u1 = SerialUnit::create([
            'product_id' => $produk->id, 'warehouse_id' => $wh->id, 'intake_id' => $intake->id,
            'serial_number' => 'SN-AAA', 'harga_modal' => 15000000,
        ]);
        SerialUnit::create([
            'product_id' => $produk->id, 'warehouse_id' => $wh->id, 'intake_id' => $intake->id,
            'serial_number' => 'SN-BBB', 'harga_modal' => 15000000, 'harga_jual' => 18000000,
        ]);

        // ULID auto-generated
        $this->assertNotNull($intake->ulid);
        $this->assertNotNull($u1->ulid);

        // Default status (DB-level default → reload instance)
        $u1->refresh();
        $this->assertSame('tersedia', $u1->status);
        $this->assertTrue($u1->isTersedia());

        // Relasi
        $this->assertEquals(2, $intake->units()->count());
        $this->assertEquals($produk->id, $u1->product->id);
        $this->assertEquals($wh->id, $intake->warehouse->id);
        $this->assertEquals('PBS-0001', $u1->intake->nomor_dokumen);

        // Scope
        $this->assertEquals(2, SerialUnit::tersedia()->byProduct($produk->id)->count());
        $this->assertEquals(1, SerialUnit::where('serial_number', 'SN-BBB')->whereNotNull('harga_jual')->count());
    }
    #[Test]
    public function soft_delete_excludes_unit_from_queries()
    {
        $produk = $this->serialProduk();
        $wh = $this->warehouse();

        $u = SerialUnit::create([
            'product_id' => $produk->id, 'warehouse_id' => $wh->id,
            'serial_number' => 'SN-DEL', 'harga_modal' => 1000,
        ]);

        $u->delete();

        $this->assertEquals(0, SerialUnit::byProduct($produk->id)->count());
        $this->assertEquals(1, SerialUnit::withTrashed()->byProduct($produk->id)->count());
    }

    /**
     * SN TIDAK unik (per-produk maupun global): SN identik boleh dipakai dua produk berbeda.
     * Identitas unik unit = kode_internal. (Lihat juga _within_same_product di bawah.)
     *
     */
    #[Test]
    public function same_serial_number_is_allowed_across_different_products()
    {
        $wh = $this->warehouse();
        $produkA = $this->serialProduk();
        $produkB = MasterProduk::create([
            'kode_produk' => 'LAP_M3', 'nama_produk' => 'MacBook Air M3', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);

        SerialUnit::create([
            'product_id' => $produkA->id, 'warehouse_id' => $wh->id,
            'serial_number' => 'SN-SHARED', 'harga_modal' => 1000,
        ]);
        SerialUnit::create([
            'product_id' => $produkB->id, 'warehouse_id' => $wh->id,
            'serial_number' => 'SN-SHARED', 'harga_modal' => 2000,
        ]);

        // Per-produk: tepat 1 unit masing-masing; global SN 'SN-SHARED' = 2 baris
        $this->assertEquals(1, SerialUnit::byProduct($produkA->id)->where('serial_number', 'SN-SHARED')->count());
        $this->assertEquals(1, SerialUnit::byProduct($produkB->id)->where('serial_number', 'SN-SHARED')->count());
        $this->assertEquals(2, SerialUnit::where('serial_number', 'SN-SHARED')->count());
    }

    /**
     * SN boleh kembar BAHKAN dalam 1 produk (SN ponsel sering kembar/typo dari supplier).
     * kode_internal tetap unik per unit (auto KI-{id}).
     *
     */
    #[Test]
    public function same_serial_number_is_allowed_within_same_product()
    {
        $produk = $this->serialProduk();
        $wh = $this->warehouse();

        $a = SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh->id, 'serial_number' => 'SN-KEMBAR', 'harga_modal' => 1000]);
        $b = SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh->id, 'serial_number' => 'SN-KEMBAR', 'harga_modal' => 2000]);

        $this->assertEquals(2, SerialUnit::byProduct($produk->id)->where('serial_number', 'SN-KEMBAR')->count());
        // Identitas tetap terpisah: kode_internal beda & non-null
        $this->assertNotSame($a->fresh()->kode_internal, $b->fresh()->kode_internal);
    }

    /**
     * kode_internal auto-generate = KI-{id} saat dibiarkan kosong (hook model created).
     *
     */
    #[Test]
    public function kode_internal_auto_generated_when_blank()
    {
        $produk = $this->serialProduk();
        $wh = $this->warehouse();

        $u = SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh->id, 'serial_number' => 'SN-AUTO', 'harga_modal' => 1000]);

        $this->assertSame('KI-' . str_pad((string) $u->id, 7, '0', STR_PAD_LEFT), $u->kode_internal);
        // Override manual dipertahankan apa adanya
        $u2 = SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh->id, 'serial_number' => 'SN-OVR', 'kode_internal' => 'CUSTOM-1', 'harga_modal' => 1000]);
        $this->assertSame('CUSTOM-1', $u2->fresh()->kode_internal);
    }

    /**
     * kode_internal UNIQUE global di level DB — insert duplikat dilempar QueryException.
     *
     */
    #[Test]
    public function kode_internal_is_globally_unique_at_db_level()
    {
        $produk = $this->serialProduk();
        $wh = $this->warehouse();

        SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh->id, 'serial_number' => 'SN-1', 'kode_internal' => 'DUP-KODE', 'harga_modal' => 1000]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh->id, 'serial_number' => 'SN-2', 'kode_internal' => 'DUP-KODE', 'harga_modal' => 2000]);
    }

    /**
     * cost_per_unit di-cast decimal:4 → presisi 4 desimal tersimpan & terbaca eksak
     * (landed cost hasil alokasi proporsional bisa pecahan). harga_modal decimal:2.
     *
     */
    #[Test]
    public function cost_per_unit_keeps_four_decimal_precision()
    {
        $produk = $this->serialProduk();
        $wh = $this->warehouse();

        $u = SerialUnit::create([
            'product_id' => $produk->id, 'warehouse_id' => $wh->id,
            'serial_number' => 'SN-PREC', 'harga_modal' => 1000000.50, 'cost_per_unit' => 1000333.3333,
        ]);
        $u->refresh();

        $this->assertSame('1000333.3333', (string) $u->cost_per_unit);
        $this->assertSame('1000000.50', (string) $u->harga_modal);
    }

    /**
     * Status default DB 'tersedia'; unit yg eksplisit 'pending' → isTersedia() false.
     * Scope tersedia/byWarehouse memilah eksak antar status & gudang.
     *
     */
    #[Test]
    public function status_scopes_partition_units_exactly_per_status_and_warehouse()
    {
        $produk = $this->serialProduk();
        $wh = $this->warehouse();
        $wh2 = MasterWarehouse::create([
            'kode_warehouse' => 'WH2', 'nama_warehouse' => 'Gudang Cabang',
            'is_saleable' => true, 'status' => 'active',
        ]);

        // 2 tersedia di WH1, 1 pending di WH1, 1 terjual di WH2
        SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh->id, 'serial_number' => 'A1', 'harga_modal' => 1000, 'status' => 'tersedia']);
        SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh->id, 'serial_number' => 'A2', 'harga_modal' => 1000, 'status' => 'tersedia']);
        $pending = SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh->id, 'serial_number' => 'A3', 'harga_modal' => 1000, 'status' => 'pending']);
        SerialUnit::create(['product_id' => $produk->id, 'warehouse_id' => $wh2->id, 'serial_number' => 'A4', 'harga_modal' => 1000, 'status' => 'terjual']);

        $this->assertFalse($pending->isTersedia());

        $this->assertEquals(2, SerialUnit::byProduct($produk->id)->tersedia()->count());
        $this->assertEquals(1, SerialUnit::byProduct($produk->id)->terjual()->count());
        $this->assertEquals(3, SerialUnit::byProduct($produk->id)->byWarehouse($wh->id)->count());
        $this->assertEquals(1, SerialUnit::byProduct($produk->id)->byWarehouse($wh2->id)->count());
        // tersedia hanya di WH1 (yg pending tidak masuk)
        $this->assertEquals(2, SerialUnit::byProduct($produk->id)->byWarehouse($wh->id)->tersedia()->count());
        $this->assertEquals(0, SerialUnit::byProduct($produk->id)->byWarehouse($wh2->id)->tersedia()->count());
    }

    /**
     * id model SerialUnit & intake disembunyikan dari serialisasi (HasUlid + $hidden),
     * tapi ulid + serial_number tampil — API publik aman pakai ulid, bukan id.
     *
     */
    #[Test]
    public function serialization_hides_internal_id_but_exposes_ulid()
    {
        $produk = $this->serialProduk();
        $wh = $this->warehouse();

        $u = SerialUnit::create([
            'product_id' => $produk->id, 'warehouse_id' => $wh->id,
            'serial_number' => 'SN-HIDE', 'harga_modal' => 1000,
        ]);

        $arr = $u->fresh()->toArray();
        $this->assertArrayNotHasKey('id', $arr);
        $this->assertArrayNotHasKey('product_id', $arr);
        $this->assertArrayNotHasKey('warehouse_id', $arr);
        $this->assertArrayHasKey('ulid', $arr);
        $this->assertSame('SN-HIDE', $arr['serial_number']);
        // kode_internal = identitas publik → ikut terserialisasi (tidak di-$hidden)
        $this->assertArrayHasKey('kode_internal', $arr);
    }
}
