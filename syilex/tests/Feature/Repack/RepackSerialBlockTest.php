<?php

namespace Tests\Feature\Repack;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 3 — Repack x Serial guard: produk serial DILARANG masuk dokumen Repack
 * (per docs/modules/serial.md §"Repack — guard-only"). SerialFase1Test sudah
 * menguji picker `getProducts` menyembunyikan produk serial; test ini menambah
 * lapis yang belum ada: store()/update() menolak 422 dengan pesan jelas ketika
 * product_id serial disisipkan langsung (bypass picker), baik sebagai input
 * maupun output. Study: InventoryMasterRules::repackPayloadErrors (blockSerial
 * true) dipanggil dari RepackController::store/update.
 */
class RepackSerialBlockTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MasterWarehouse $warehouse;

    protected MasterProduk $serialProduct;

    protected MasterProduk $normalInput;

    protected MasterProduk $normalOutput;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['repack.create', 'repack.update', 'repack.view', 'repack.approve'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['repack.create', 'repack.update', 'repack.view', 'repack.approve']);
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->serialProduct = MasterProduk::create([
            'kode_produk' => 'SERREP', 'nama_produk' => 'iPhone Serial', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 1000, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);

        $this->normalInput = MasterProduk::factory()->create([
            'nama_produk' => 'Karton Source', 'avg_cost' => 12000, 'status' => 'active',
        ]);
        $this->normalOutput = MasterProduk::factory()->create([
            'nama_produk' => 'PCS Result', 'avg_cost' => 0, 'status' => 'active',
        ]);

        StockCard::$skipObserver = true;
        foreach ([
            [$this->serialProduct->id, 5],
            [$this->normalInput->id, 10],
            [$this->normalOutput->id, 0],
        ] as [$productId, $qty]) {
            InventoryStock::updateOrCreate(
                ['product_id' => $productId, 'warehouse_id' => $this->warehouse->id],
                ['qty' => $qty, 'avg_cost' => $qty > 0 ? 1000 : 0]
            );
            if ($qty > 0) {
                StockCard::record([
                    'product_id' => $productId, 'warehouse_id' => $this->warehouse->id,
                    'transaction_type' => 'PURCHASE', 'tanggal' => '2026-04-01',
                    'qty_in' => $qty, 'qty_out' => 0, 'cost_per_unit' => 1000,
                ]);
            }
        }
        StockCard::$skipObserver = false;
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->warehouse->id,
            'tipe' => 'pecah',
            'tanggal' => '2026-04-12',
            'biaya_repack' => 1000,
            'inputs' => [
                ['product_id' => $this->normalInput->id, 'qty' => 2],
            ],
            'outputs' => [
                ['product_id' => $this->normalOutput->id, 'qty' => 10],
            ],
        ], $overrides);
    }
    #[Test]
    public function store_rejects_serial_product_as_input_with_clear_message(): void
    {
        $res = $this->postJson('/api/v1/repacks', $this->baseData([
            'inputs' => [['product_id' => $this->serialProduct->id, 'qty' => 1]],
        ]))->assertStatus(422);

        $this->assertSame(
            'Produk serial tidak diizinkan pada dokumen ini.',
            $res->json('errors')['inputs.0.product_id'][0] ?? null
        );
        $this->assertSame(0, \App\Models\DocRepack::count(), 'Tidak ada dokumen repack tercipta.');
    }
    #[Test]
    public function store_rejects_serial_product_as_output_with_clear_message(): void
    {
        $res = $this->postJson('/api/v1/repacks', $this->baseData([
            'outputs' => [['product_id' => $this->serialProduct->id, 'qty' => 1]],
        ]))->assertStatus(422);

        $this->assertSame(
            'Produk serial tidak diizinkan pada dokumen ini.',
            $res->json('errors')['outputs.0.product_id'][0] ?? null
        );
        $this->assertSame(0, \App\Models\DocRepack::count());
    }
    #[Test]
    public function store_allows_non_serial_products(): void
    {
        $this->postJson('/api/v1/repacks', $this->baseData())->assertCreated();
    }
    #[Test]
    public function update_rejects_switching_input_to_serial_product(): void
    {
        $repack = $this->postJson('/api/v1/repacks', $this->baseData())
            ->assertCreated()
            ->json('data.repack');

        $res = $this->putJson("/api/v1/repacks/{$repack['ulid']}", $this->baseData([
            'inputs' => [['product_id' => $this->serialProduct->id, 'qty' => 1]],
        ]))->assertStatus(422);

        $this->assertSame(
            'Produk serial tidak diizinkan pada dokumen ini.',
            $res->json('errors')['inputs.0.product_id'][0] ?? null
        );

        // Draft tidak ikut berubah (masih produk lama).
        $fresh = $this->getJson("/api/v1/repacks/{$repack['ulid']}")->assertOk()->json('data.repack');
        $this->assertEquals($this->normalInput->id, $fresh['inputs'][0]['product_id']);
    }
    #[Test]
    public function update_rejects_switching_output_to_serial_product(): void
    {
        $repack = $this->postJson('/api/v1/repacks', $this->baseData())
            ->assertCreated()
            ->json('data.repack');

        $res = $this->putJson("/api/v1/repacks/{$repack['ulid']}", $this->baseData([
            'outputs' => [['product_id' => $this->serialProduct->id, 'qty' => 1]],
        ]))->assertStatus(422);

        $this->assertSame(
            'Produk serial tidak diizinkan pada dokumen ini.',
            $res->json('errors')['outputs.0.product_id'][0] ?? null
        );
    }
    #[Test]
    public function repack_products_picker_excludes_serial_products_for_this_warehouse(): void
    {
        // Lapis pertama (sudah ada di SerialFase1Test, dipertahankan sebagai sanity
        // check agar guard tidak diam-diam hilang bila picker query diubah).
        $res = $this->getJson("/api/v1/repacks/products?warehouse_id={$this->warehouse->id}")->assertOk();
        $ulids = collect($res->json('data.items'))->pluck('ulid');

        $this->assertContains($this->normalInput->ulid, $ulids);
        $this->assertNotContains($this->serialProduct->ulid, $ulids);
    }
}
