<?php

namespace Tests\Feature\Pos;

use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regresi: endpoint produk POS WAJIB mengembalikan flag is_serial — tanpa ini,
 * grid POS tak tahu produk serial → guard "wajib scan SN" di frontend tak aktif
 * → produk serial keliru masuk keranjang sebagai baris qty biasa.
 */
class PosProductsSerialFlagTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'pos.access', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('pos.access');
        $this->actingAs($this->user);

        $this->wh = MasterWarehouse::factory()->create(['status' => 'active']);
    }

    private function makeProduk(string $kode, bool $serial): MasterProduk
    {
        return MasterProduk::create([
            'kode_produk' => $kode, 'nama_produk' => "Produk {$kode}", 'status' => 'active',
            'is_serial' => $serial, 'minimum_stok' => 0, 'avg_cost' => 0,
            'barcode' => $serial ? null : "BC-{$kode}",
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => $serial ? 0 : 5000,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => $serial ? 0 : 5000,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => $serial ? 0 : 5000,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => $serial ? 0 : 5000,
        ]);
    }
    #[Test]
    public function products_endpoint_includes_is_serial_flag()
    {
        $serial = $this->makeProduk('SERHP', true);
        $retail = $this->makeProduk('RTL', false);

        $items = collect(
            $this->getJson("/api/v1/pos/products?warehouse_id={$this->wh->id}")
                ->assertOk()
                ->json('data.products')
        )->keyBy('kode_produk');

        $this->assertArrayHasKey('is_serial', $items['SERHP']);
        $this->assertTrue((bool) $items['SERHP']['is_serial'], 'Produk serial harus is_serial=true');
        $this->assertFalse((bool) $items['RTL']['is_serial'], 'Produk retail harus is_serial=false');
    }
    #[Test]
    public function product_by_barcode_includes_is_serial_flag()
    {
        $this->makeProduk('RTL', false);

        $product = $this->getJson("/api/v1/pos/products/barcode/BC-RTL?warehouse_id={$this->wh->id}")
            ->assertOk()
            ->json('data.product');

        $this->assertArrayHasKey('is_serial', $product);
        $this->assertFalse((bool) $product['is_serial']);
    }
}
