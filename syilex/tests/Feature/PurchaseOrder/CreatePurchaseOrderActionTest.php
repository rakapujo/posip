<?php

namespace Tests\Feature\PurchaseOrder;

use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Models\DocPurchaseOrder;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class CreatePurchaseOrderActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterSupplier $supplier;
    protected MasterProduk $product;
    protected CreatePurchaseOrderAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable tax & rounding for predictable math
        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->supplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-001',
            'nama_supplier' => 'Test Supplier',
            'nama_pic' => 'John Doe',
            'telepon' => '08123456789',
            'tempo_default' => 14,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Test Product',
            'avg_cost' => 5000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        // Initialize stock (product observer already created with qty=0)
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 50, 'avg_cost' => 5000]
        );
        StockCard::$skipObserver = false;

        $this->action = new CreatePurchaseOrderAction();
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'tanggal_po' => '2026-04-12',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 10,
                    'harga_per_unit' => 5000,
                ],
            ],
        ], $overrides);
    }
    #[Test]
    public function create_po_happy_path_with_draft_status()
    {
        $po = $this->action->execute($this->baseData());

        $this->assertInstanceOf(DocPurchaseOrder::class, $po);
        $this->assertEquals('draft', $po->status);
        $this->assertEquals($this->supplier->id, $po->supplier_id);
        $this->assertEquals($this->warehouse->id, $po->warehouse_id);
        $this->assertNotEmpty($po->nomor_dokumen);
        $this->assertStringStartsWith('POR-', $po->nomor_dokumen);
    }
    #[Test]
    public function create_po_calculates_subtotal_from_details()
    {
        // 10 qty × 5000 = 50000
        $po = $this->action->execute($this->baseData());

        $this->assertEquals(50000, $po->subtotal);
        $this->assertEquals(50000, $po->grand_total);
        $this->assertEquals(1, $po->details->count());

        $detail = $po->details->first();
        $this->assertEquals(10, $detail->qty_in_unit);
        $this->assertEquals(10, $detail->qty_in_base);
        $this->assertEquals(5000, $detail->harga_per_unit);
        $this->assertEquals(50000, $detail->harga_bruto);
        $this->assertEquals(50000, $detail->subtotal);
    }
    #[Test]
    public function create_po_does_not_change_inventory_stock()
    {
        $initialStock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first()->qty;

        $this->action->execute($this->baseData());

        $afterStock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first()->qty;

        $this->assertEquals($initialStock, $afterStock, 'Stock should not change on create (only on approve)');
    }
    #[Test]
    public function create_po_sets_tempo_and_jatuh_tempo_from_supplier_default()
    {
        // supplier tempo_default = 14 days, tanggal_po = 2026-04-12 → jatuh tempo = 2026-04-26
        $po = $this->action->execute($this->baseData());

        $this->assertEquals(14, $po->tempo_hari);
        $this->assertEquals('2026-04-26', $po->tanggal_jatuh_tempo->format('Y-m-d'));
    }
    #[Test]
    public function create_po_can_override_tempo_hari()
    {
        $po = $this->action->execute($this->baseData(['tempo_hari' => 30]));

        $this->assertEquals(30, $po->tempo_hari);
        $this->assertEquals('2026-05-12', $po->tanggal_jatuh_tempo->format('Y-m-d'));
    }
    #[Test]
    public function create_po_with_item_discount_percent()
    {
        // 10 × 5000 = 50000, diskon 10% = 5000, subtotal = 45000
        $po = $this->action->execute($this->baseData([
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 10,
                    'harga_per_unit' => 5000,
                    'diskon_1_tipe' => 'percent',
                    'diskon_1_nilai' => 10,
                ],
            ],
        ]));

        $detail = $po->details->first();
        $this->assertEquals(5000, $detail->diskon_1_hasil);
        $this->assertEquals(5000, $detail->total_diskon_item);
        $this->assertEquals(45000, $detail->subtotal);
        $this->assertEquals(45000, $po->subtotal);
        $this->assertEquals(45000, $po->grand_total);
    }
    #[Test]
    public function create_po_with_biaya_kirim_nominal()
    {
        $po = $this->action->execute($this->baseData([
            'biaya_kirim_tipe' => 'nominal',
            'biaya_kirim_nilai' => 10000,
        ]));

        // subtotal 50000 + biaya kirim 10000 = 60000
        $this->assertEquals(10000, $po->biaya_kirim_hasil);
        $this->assertEquals(10000, $po->total_biaya_tambahan);
        $this->assertEquals(60000, $po->grand_total);
    }

    // ===================================================================
    // EDGE CASE TAMBAHAN — assertion eksak, perilaku diverifikasi via kode
    // ===================================================================
    #[Test]
    public function create_po_tidak_membuat_stock_card_sama_sekali()
    {
        // Create PO TIDAK menyentuh stok → stock_card harus 0 entri (mutasi hanya saat approve).
        $po = $this->action->execute($this->baseData());

        $this->assertEquals(
            0,
            StockCard::where('transaction_id', $po->id)->count(),
            'Create PO tidak boleh menulis stock_card apa pun.'
        );
    }
    #[Test]
    public function create_po_tempo_nol_menghasilkan_jatuh_tempo_null()
    {
        // tempo_hari = 0 → tanggal_jatuh_tempo WAJIB null (lihat CreatePurchaseOrderAction baris 39-41).
        $po = $this->action->execute($this->baseData(['tempo_hari' => 0]));

        $this->assertEquals(0, $po->tempo_hari);
        $this->assertNull($po->tanggal_jatuh_tempo, 'tempo 0 → tidak ada tanggal jatuh tempo.');
    }
    #[Test]
    public function create_po_konversi_unit_menghitung_qty_base_dan_harga_per_base_eksak()
    {
        // 1 unit BOX (konversi 12) @ 60000 → qty_base 12, harga_per_base 5000, harga_bruto 60000
        $this->product->update(['unit_1' => 'BOX', 'konversi_1' => 12]);

        $po = $this->action->execute($this->baseData([
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'BOX',
                'unit_konversi' => 12,
                'qty_in_unit' => 1,
                'harga_per_unit' => 60000,
            ]],
        ]));

        $detail = $po->details->first();
        $this->assertEquals(1, $detail->qty_in_unit);
        $this->assertEquals(12, $detail->qty_in_base, 'qty_base = qty_unit × konversi = 1 × 12');
        $this->assertEquals(60000, $detail->harga_bruto, 'harga_bruto = qty_unit × harga_per_unit');
        $this->assertEquals(5000, $detail->harga_per_base, 'harga_per_base = harga_per_unit / konversi = 60000/12');
        $this->assertEquals(5000, $detail->cost_per_unit, 'cost_per_unit (per base) = subtotal/qty_base = 60000/12');
        $this->assertEquals(60000, $po->grand_total);
    }
    #[Test]
    public function create_po_diskon_item_recursive_bertingkat_eksak()
    {
        // Mode default = recursive. 10×5000 = 50000.
        // diskon_1 10% → 5000 (sisa 45000); diskon_2 nominal 5000 → (sisa 40000).
        $po = $this->action->execute($this->baseData([
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => 10,
                'harga_per_unit' => 5000,
                'diskon_1_tipe' => 'percent',
                'diskon_1_nilai' => 10,
                'diskon_2_tipe' => 'nominal',
                'diskon_2_nilai' => 5000,
            ]],
        ]));

        $detail = $po->details->first();
        $this->assertEquals(5000, $detail->diskon_1_hasil, 'diskon_1 = 10% × 50000');
        $this->assertEquals(5000, $detail->diskon_2_hasil, 'diskon_2 = nominal 5000 dari sisa 45000');
        $this->assertEquals(10000, $detail->total_diskon_item, 'total diskon = 5000 + 5000');
        $this->assertEquals(40000, $detail->subtotal, '50000 - 10000');
        $this->assertEquals(40000, $po->subtotal);
        $this->assertEquals(40000, $po->grand_total);
    }
    #[Test]
    public function create_po_diskon_nominal_item_tidak_melebihi_sisa()
    {
        // diskon nominal 99999 dari bruto 50000 → di-cap ke 50000, subtotal 0 (tidak negatif).
        $po = $this->action->execute($this->baseData([
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => 10,
                'harga_per_unit' => 5000,
                'diskon_1_tipe' => 'nominal',
                'diskon_1_nilai' => 99999,
            ]],
        ]));

        $detail = $po->details->first();
        $this->assertEquals(50000, $detail->diskon_1_hasil, 'diskon nominal di-cap ke sisa amount (50000).');
        $this->assertEquals(0, $detail->subtotal, 'subtotal tidak boleh negatif.');
        $this->assertEquals(0, $po->grand_total);
    }
    #[Test]
    public function create_po_header_diskon_percent_eksak()
    {
        // subtotal 50000, header diskon_1 20% → total_setelah_diskon 40000.
        $po = $this->action->execute($this->baseData([
            'diskon_1_tipe' => 'percent',
            'diskon_1_nilai' => 20,
        ]));

        $this->assertEquals(50000, $po->subtotal);
        $this->assertEquals(10000, $po->diskon_1_hasil, '20% × 50000');
        $this->assertEquals(10000, $po->total_diskon_header);
        $this->assertEquals(40000, $po->total_setelah_diskon);
        $this->assertEquals(40000, $po->dpp, 'dpp = total setelah diskon + biaya tambahan (0)');
        $this->assertEquals(40000, $po->grand_total);
    }
    #[Test]
    public function create_po_biaya_kirim_persen_dihitung_dari_total_setelah_diskon()
    {
        // subtotal 50000, biaya kirim 10% → 5000, grand_total 55000.
        $po = $this->action->execute($this->baseData([
            'biaya_kirim_tipe' => 'percent',
            'biaya_kirim_nilai' => 10,
        ]));

        $this->assertEquals(5000, $po->biaya_kirim_hasil, '10% × 50000');
        $this->assertEquals(5000, $po->total_biaya_tambahan);
        $this->assertEquals(55000, $po->dpp);
        $this->assertEquals(55000, $po->grand_total);
    }
    #[Test]
    public function create_po_biaya_kirim_dan_biaya_lain_dijumlah_ke_grand_total()
    {
        // subtotal 50000 + kirim 10000 + lain 3000 = 63000.
        $po = $this->action->execute($this->baseData([
            'biaya_kirim_tipe' => 'nominal',
            'biaya_kirim_nilai' => 10000,
            'biaya_lain_nama' => 'PACKING',
            'biaya_lain_tipe' => 'nominal',
            'biaya_lain_nilai' => 3000,
        ]));

        $this->assertEquals(10000, $po->biaya_kirim_hasil);
        $this->assertEquals(3000, $po->biaya_lain_hasil);
        $this->assertEquals(13000, $po->total_biaya_tambahan);
        $this->assertEquals(63000, $po->dpp);
        $this->assertEquals(63000, $po->grand_total);
    }
    #[Test]
    public function create_po_biaya_kirim_dialokasikan_ke_cost_per_unit_proporsional()
    {
        // 2 produk, subtotal 30000 & 20000 (total 50000); biaya kirim 10000.
        // Alokasi by_value: produk A (30/50) = 6000, produk B (20/50) = 4000.
        // cost_per_unit A = (30000+6000)/qtyA, B = (20000+4000)/qtyB.
        $productB = MasterProduk::factory()->create([
            'nama_produk' => 'Produk B',
            'avg_cost' => 0,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        $po = $this->action->execute($this->baseData([
            'biaya_kirim_tipe' => 'nominal',
            'biaya_kirim_nilai' => 10000,
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'unit_used' => 'PCS', 'unit_konversi' => 1,
                    'qty_in_unit' => 6, 'harga_per_unit' => 5000, // subtotal 30000
                ],
                [
                    'product_id' => $productB->id,
                    'unit_used' => 'PCS', 'unit_konversi' => 1,
                    'qty_in_unit' => 4, 'harga_per_unit' => 5000, // subtotal 20000
                ],
            ],
        ]));

        $this->assertEquals(50000, $po->subtotal);
        $this->assertEquals(60000, $po->grand_total, '50000 + biaya kirim 10000');

        $detailA = $po->details->firstWhere('product_id', $this->product->id);
        $detailB = $po->details->firstWhere('product_id', $productB->id);

        // A: (30000 + 6000) / 6 = 6000 ; B: (20000 + 4000) / 4 = 6000
        $this->assertEquals(6000, $detailA->cost_per_unit, 'cost A = (30000 + alok 6000)/6');
        $this->assertEquals(6000, $detailB->cost_per_unit, 'cost B = (20000 + alok 4000)/4');
    }
    #[Test]
    public function create_po_dengan_pajak_persen_menambah_grand_total_eksak()
    {
        // Aktifkan PPN 11%. subtotal 50000 → pajak 5500, grand_total 55500.
        SettingService::set('tax.tax_purchase_percent', 11, 'integer');

        $po = $this->action->execute($this->baseData());

        $this->assertEquals(50000, $po->dpp);
        $this->assertEquals(11, $po->pajak_persen);
        $this->assertEquals(5500, $po->pajak_nominal, '11% × 50000');
        $this->assertEquals(55500, $po->grand_total, 'dpp 50000 + pajak 5500');
    }
    #[Test]
    public function create_po_multi_detail_menjumlah_subtotal_eksak()
    {
        $productB = MasterProduk::factory()->create([
            'nama_produk' => 'Produk B', 'avg_cost' => 0,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'status' => 'active',
        ]);

        $po = $this->action->execute($this->baseData([
            'details' => [
                ['product_id' => $this->product->id, 'unit_used' => 'PCS', 'unit_konversi' => 1, 'qty_in_unit' => 3, 'harga_per_unit' => 5000], // 15000
                ['product_id' => $productB->id, 'unit_used' => 'PCS', 'unit_konversi' => 1, 'qty_in_unit' => 7, 'harga_per_unit' => 2000],        // 14000
            ],
        ]));

        $this->assertEquals(2, $po->details->count());
        $this->assertEquals(29000, $po->subtotal, '15000 + 14000');
        $this->assertEquals(29000, $po->grand_total);
    }
}
