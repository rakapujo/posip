<?php

namespace Tests\Feature\Pos;

use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
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
    protected MasterPosTerminal $terminal;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => 'pos.access', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('pos.access');
        $this->actingAs($this->user);

        $this->wh = MasterWarehouse::factory()->create(['status' => 'active', 'is_saleable' => true]);

        $customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'WALK',
            'nama' => 'Walk-in',
            'telepon' => '0800000000',
            'jenis' => 'walk_in',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $this->terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-P',
            'nama_terminal' => 'Kasir Test',
            'warehouse_id' => $this->wh->id,
            'default_customer_id' => $customer->id,
            'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $this->user->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
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

    #[Test]
    public function products_endpoint_omits_avg_cost_without_view_hpp()
    {
        $this->makeProduk('RTL', false);

        $product = collect(
            $this->getJson("/api/v1/pos/products?warehouse_id={$this->wh->id}")
                ->assertOk()
                ->json('data.products')
        )->firstWhere('kode_produk', 'RTL');

        $this->assertNotNull($product);
        $this->assertArrayNotHasKey('avg_cost', $product);
    }

    #[Test]
    public function products_endpoint_includes_avg_cost_with_view_hpp()
    {
        Permission::firstOrCreate(['name' => 'stok.view_hpp', 'guard_name' => 'web']);
        $this->user->givePermissionTo('stok.view_hpp');
        $this->makeProduk('RTL', false);

        $product = collect(
            $this->getJson("/api/v1/pos/products?warehouse_id={$this->wh->id}")
                ->assertOk()
                ->json('data.products')
        )->firstWhere('kode_produk', 'RTL');

        $this->assertArrayHasKey('avg_cost', $product);
    }

    #[Test]
    public function barcode_endpoint_omits_avg_cost_without_view_hpp()
    {
        $this->makeProduk('RTL', false);

        $product = $this->getJson("/api/v1/pos/products/barcode/BC-RTL?warehouse_id={$this->wh->id}")
            ->assertOk()
            ->json('data.product');

        $this->assertArrayNotHasKey('avg_cost', $product);
    }
}
