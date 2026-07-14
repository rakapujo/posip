<?php

namespace Tests\Feature\Serial;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 1 modul serial — fondasi & guard:
 *  - Repack & Koreksi HPP menolak produk serial (picker getProducts).
 *  - Adjustment menolak produk serial (debit & kredit) di Fase 1.
 *  - Hapus produk diblok bila masih punya unit serial.
 *  - Endpoint serial-units/available (unit tersedia per gudang).
 *  - Invariant data:verify mendeteksi desync serial_units vs inventory_stock.
 */
class SerialFase1Test extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $serial;
    protected MasterProduk $normal;
    protected MasterWarehouse $wh;
    protected MasterWarehouse $wh2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'repack.create', 'hpp.create', 'adjustment.view', 'adjustment.create',
            'produk.delete', 'serial-intake.view', 'transfer.create', 'retur-beli.create', 'opname.create',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::all());
        $this->actingAs($this->admin);

        $this->serial = $this->makeProduct('SER1', 'iPhone Serial', true);
        $this->normal = $this->makeProduct('NRM1', 'Kabel Biasa', false);

        $this->wh = MasterWarehouse::create([
            'kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang Utama', 'is_saleable' => true, 'status' => 'active',
        ]);
        $this->wh2 = MasterWarehouse::create([
            'kode_warehouse' => 'WH2', 'nama_warehouse' => 'Gudang Cabang', 'is_saleable' => true, 'status' => 'active',
        ]);
    }

    private function makeProduct(string $kode, string $nama, bool $serial): MasterProduk
    {
        return MasterProduk::create([
            'kode_produk' => $kode, 'nama_produk' => $nama, 'status' => 'active',
            'is_serial' => $serial, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
    }

    private function makeUnit(MasterWarehouse $wh, string $sn, string $status = 'tersedia'): SerialUnit
    {
        return SerialUnit::create([
            'product_id' => $this->serial->id, 'warehouse_id' => $wh->id,
            'serial_number' => $sn, 'harga_modal' => 1000, 'cost_per_unit' => 1000, 'status' => $status,
        ]);
    }
    #[Test]
    public function repack_products_excludes_serial()
    {
        $res = $this->getJson("/api/v1/repacks/products?warehouse_id={$this->wh->id}")->assertOk();
        $ulids = collect($res->json('data.items'))->pluck('ulid');
        $this->assertContains($this->normal->ulid, $ulids);
        $this->assertNotContains($this->serial->ulid, $ulids);
    }
    #[Test]
    public function hpp_correction_products_excludes_serial()
    {
        $res = $this->getJson('/api/v1/hpp-corrections/products')->assertOk();
        $ulids = collect($res->json('data.items'))->pluck('ulid');
        $this->assertContains($this->normal->ulid, $ulids);
        $this->assertNotContains($this->serial->ulid, $ulids);
    }
    #[Test]
    public function adjustment_store_rejects_serial_debit_and_kredit()
    {
        foreach (['debit', 'kredit'] as $jenis) {
            $this->postJson('/api/v1/adjustments', [
                'warehouse_id' => $this->wh->id,
                'tanggal' => now()->toDateString(),
                'details' => [
                    ['product_id' => $this->serial->id, 'jenis' => $jenis, 'qty' => 1],
                ],
            ])->assertStatus(422);
        }

        // Produk normal tetap bisa
        $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                ['product_id' => $this->normal->id, 'jenis' => 'debit', 'qty' => 1],
            ],
        ])->assertCreated();
    }
    #[Test]
    public function produk_with_serial_units_cannot_be_deleted()
    {
        $this->makeUnit($this->wh, 'SN-DEL-1');

        $this->deleteJson("/api/v1/produks/{$this->serial->ulid}")->assertStatus(422);

        // Produk normal tanpa stok/relasi tetap bisa dihapus
        $this->deleteJson("/api/v1/produks/{$this->normal->ulid}")->assertOk();
    }
    #[Test]
    public function serial_units_available_filters_by_warehouse_and_status()
    {
        $this->makeUnit($this->wh, 'SN-A');
        $this->makeUnit($this->wh, 'SN-B');
        $this->makeUnit($this->wh, 'SN-SOLD', 'terjual');
        $this->makeUnit($this->wh2, 'SN-C');

        $res = $this->getJson("/api/v1/serial-units/available?product_id={$this->serial->ulid}&warehouse_id={$this->wh->id}")
            ->assertOk();

        $sns = collect($res->json('data.items'))->pluck('serial_number');
        $this->assertEqualsCanonicalizing(['SN-A', 'SN-B'], $sns->all());
    }
    #[Test]
    public function data_verify_detects_serial_desync()
    {
        // State konsisten: inventory_stock qty=2 + stock_card padanan + 2 unit tersedia
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id],
            ['qty' => 2, 'avg_cost' => 1000]
        );
        StockCard::record([
            'product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 2, 'qty_out' => 0, 'cost_per_unit' => 1000,
        ]);
        StockCard::$skipObserver = false;

        $u1 = $this->makeUnit($this->wh, 'SN-1');
        $this->makeUnit($this->wh, 'SN-2');

        // Konsisten → exit 0
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));

        // Desync: hapus 1 unit (soft) tanpa sesuaikan inventory_stock → exit 1
        $u1->delete();
        $this->assertSame(1, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
}
