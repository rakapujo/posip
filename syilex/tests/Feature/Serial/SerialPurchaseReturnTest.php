<?php

namespace Tests\Feature\Serial;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 4 — Retur Beli untuk produk serial:
 * harga retur = rata-rata harga_modal unit (subtotal = Σ modal), valuasi stok =
 * rata-rata cost_per_unit (landed), unit jadi 'retur', movement OUT.
 */
class SerialPurchaseReturnTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $serial;
    protected MasterWarehouse $wh;
    protected MasterSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['retur-beli.view', 'retur-beli.create', 'retur-beli.lock', 'serial-intake.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::all());
        $this->actingAs($this->admin);

        $this->serial = MasterProduk::create([
            'kode_produk' => 'SER1', 'nama_produk' => 'iPhone Serial', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
        $this->wh = MasterWarehouse::create(['kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang 1', 'is_saleable' => true, 'status' => 'active']);
        $this->supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP1', 'nama_supplier' => 'PT Supplier', 'nama_pic' => 'Budi',
            'telepon' => '0812', 'status' => 'active',
        ]);
    }

    /** @return SerialUnit[] unit dengan modal & cost berbeda untuk uji rata-rata */
    private function seedUnits(): array
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id],
            ['qty' => 3, 'avg_cost' => 2000]
        );
        StockCard::record([
            'product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 3, 'qty_out' => 0, 'cost_per_unit' => 2200,
        ]);
        StockCard::$skipObserver = false;

        $specs = [
            ['SN-1', 1000, 1100],
            ['SN-2', 2000, 2200],
            ['SN-3', 3000, 3300],
        ];
        $units = [];
        foreach ($specs as [$sn, $modal, $cost]) {
            $units[] = SerialUnit::create([
                'product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id,
                'serial_number' => $sn, 'harga_modal' => $modal, 'cost_per_unit' => $cost, 'status' => 'tersedia',
            ]);
        }
        return $units;
    }
    #[Test]
    public function retur_serial_uses_avg_modal_and_landed_cost_and_marks_retur()
    {
        $units = $this->seedUnits();
        $retur = [$units[0]->ulid, $units[1]->ulid]; // modal 1000 & 2000 → avg 1500

        $res = $this->postJson('/api/v1/purchase-returns', [
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'details' => [
                [
                    'product_id' => $this->serial->id,
                    'unit_used' => 'UNIT', 'unit_konversi' => 1,
                    'qty_in_unit' => 2, 'harga_per_unit' => 0,
                    'serial_unit_ids' => $retur,
                ],
            ],
        ])->assertCreated();

        $ulid = $res->json('data.purchase_return.ulid');

        // Harga retur = rata-rata modal (1500); subtotal = Σ modal (3000)
        $detail = \App\Models\DocPurchaseReturnDetail::whereHas('purchaseReturn', fn ($q) => $q->where('ulid', $ulid))->first();
        $this->assertSame(1500.0, (float) $detail->harga_per_unit);
        $this->assertSame(3000.0, (float) $detail->subtotal);
        $this->assertCount(2, $detail->serial_unit_ids);

        // Lock → stok keluar + unit jadi retur
        $this->postJson("/api/v1/purchase-returns/{$ulid}/lock")->assertOk();

        $this->assertSame('retur', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertSame('retur', SerialUnit::where('ulid', $units[1]->ulid)->value('status'));
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[2]->ulid)->value('status'));

        $this->assertSame(1, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->wh->id)->value('qty'));

        // Valuasi stok keluar = rata-rata cost_per_unit landed (1100+2200)/2 = 1650
        $card = StockCard::where('transaction_type', 'PURCHASE_RETURN')->where('product_id', $this->serial->id)->first();
        $this->assertSame(1650.0, (float) $card->cost_per_unit);

        $this->assertSame(2, SerialUnitMovement::where('doc_type', 'PURCHASE_RETURN')->where('movement_type', 'OUT')->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function retur_serial_requires_units()
    {
        $this->seedUnits();

        $this->postJson('/api/v1/purchase-returns', [
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'details' => [
                [
                    'product_id' => $this->serial->id,
                    'unit_used' => 'UNIT', 'unit_konversi' => 1,
                    'qty_in_unit' => 1, 'harga_per_unit' => 0,
                    'serial_unit_ids' => [],
                ],
            ],
        ])->assertStatus(422);
    }
    #[Test]
    public function retur_serial_does_not_recalc_avg_cost()
    {
        $units = $this->seedUnits();
        $this->serial->update(['avg_cost' => 2000]); // HPP agregat awal

        $res = $this->postJson('/api/v1/purchase-returns', [
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'details' => [[
                'product_id' => $this->serial->id, 'unit_used' => 'UNIT', 'unit_konversi' => 1,
                'qty_in_unit' => 1, 'harga_per_unit' => 0, 'serial_unit_ids' => [$units[0]->ulid],
            ]],
        ])->assertCreated();
        $this->postJson("/api/v1/purchase-returns/{$res->json('data.purchase_return.ulid')}/lock")->assertOk();

        // §2B: PURCHASE_RETURN TIDAK merekalkulasi avg_cost agregat
        $this->assertSame(2000.0, (float) $this->serial->fresh()->avg_cost);
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function retur_serial_unavailable_unit_never_returned()
    {
        $units = $this->seedUnits();
        SerialUnit::where('ulid', $units[0]->ulid)->update(['status' => 'rusak']);

        $res = $this->postJson('/api/v1/purchase-returns', [
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'details' => [[
                'product_id' => $this->serial->id, 'unit_used' => 'UNIT', 'unit_konversi' => 1,
                'qty_in_unit' => 1, 'harga_per_unit' => 0, 'serial_unit_ids' => [$units[0]->ulid],
            ]],
        ]);
        // Ditolak di create ATAU di lock — yang penting unit rusak TAK PERNAH jadi retur
        if ($res->status() === 201) {
            $this->postJson("/api/v1/purchase-returns/{$res->json('data.purchase_return.ulid')}/lock")->assertStatus(422);
        }
        $this->assertSame('rusak', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertSame(0, StockCard::where('transaction_type', 'PURCHASE_RETURN')->where('product_id', $this->serial->id)->count());
    }
    #[Test]
    public function retur_show_attaches_serial_units_with_kode_internal()
    {
        $units = $this->seedUnits();
        $res = $this->postJson('/api/v1/purchase-returns', [
            'tanggal' => now()->toDateString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->wh->id,
            'details' => [[
                'product_id' => $this->serial->id, 'unit_used' => 'UNIT', 'unit_konversi' => 1,
                'qty_in_unit' => 2, 'harga_per_unit' => 0,
                'serial_unit_ids' => [$units[0]->ulid, $units[1]->ulid],
            ]],
        ])->assertCreated();
        $ulid = $res->json('data.purchase_return.ulid');

        // Detail show me-resolve serial_unit_ids → unit (kode_internal/SN) untuk tampilan + PDF
        $show = $this->getJson("/api/v1/purchase-returns/{$ulid}")->assertOk();
        $serialUnits = collect($show->json('data.purchase_return.details.0.serial_units'));

        $this->assertCount(2, $serialUnits);
        $this->assertTrue($serialUnits->every(fn ($u) => str_starts_with((string) ($u['kode_internal'] ?? ''), 'KI-')));
        $this->assertTrue($serialUnits->every(fn ($u) => !empty($u['serial_number'])));
    }
}
