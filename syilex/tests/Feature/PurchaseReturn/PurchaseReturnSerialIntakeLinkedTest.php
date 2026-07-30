<?php

namespace Tests\Feature\PurchaseReturn;

use App\Models\DocPurchaseOrder;
use App\Models\DocSerialIntake;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\StockCard;
use App\Models\SupplierDeposit;
use App\Models\SupplierHutang;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Retur beli linked PBS: XOR vs PO, unit scoped ke intake, approve net hutang PBS (bukan FIFO).
 */
class PurchaseReturnSerialIntakeLinkedTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MasterWarehouse $warehouse;

    protected MasterSupplier $supplier;

    protected MasterProduk $serial;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        foreach (['retur-beli.view', 'retur-beli.create', 'retur-beli.update', 'retur-beli.lock', 'retur-beli.approve', 'stok.view_hpp', 'po.view_harga'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(Permission::all());
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::create([
            'kode_warehouse' => 'WH-PBS',
            'nama_warehouse' => 'Gudang PBS',
            'is_saleable' => true,
            'status' => 'active',
        ]);

        $this->supplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-PBS',
            'nama_supplier' => 'Supplier PBS',
            'nama_pic' => 'PIC',
            'telepon' => '0812',
            'status' => 'active',
        ]);

        $this->serial = MasterProduk::create([
            'kode_produk' => 'SER-PBS',
            'nama_produk' => 'Serial PBS',
            'status' => 'active',
            'is_serial' => true,
            'minimum_stok' => 0,
            'avg_cost' => 2000,
            'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
    }

    /**
     * @return array{0: DocSerialIntake, 1: SerialUnit[], 2: SupplierHutang}
     */
    private function seedApprovedPbs(float $unitModal = 1000, int $unitCount = 2): array
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => $unitCount, 'avg_cost' => $unitModal]
        );
        StockCard::$skipObserver = false;

        $intake = DocSerialIntake::create([
            'nomor_dokumen' => 'PBS-TEST-0001',
            'tanggal' => now()->subDay(),
            'product_id' => $this->serial->id,
            'warehouse_id' => $this->warehouse->id,
            'supplier_id' => $this->supplier->id,
            'total_unit' => $unitCount,
            'total_modal' => $unitModal * $unitCount,
            'subtotal' => $unitModal * $unitCount,
            'grand_total' => $unitModal * $unitCount,
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $this->user->id,
        ]);

        $units = [];
        for ($i = 1; $i <= $unitCount; $i++) {
            $units[] = SerialUnit::create([
                'product_id' => $this->serial->id,
                'warehouse_id' => $this->warehouse->id,
                'intake_id' => $intake->id,
                'serial_number' => "SN-PBS-{$i}",
                'harga_modal' => $unitModal,
                'cost_per_unit' => $unitModal,
                'status' => 'tersedia',
            ]);
        }

        $hutang = SupplierHutang::create([
            'supplier_id' => $this->supplier->id,
            'po_id' => null,
            'serial_intake_id' => $intake->id,
            'tanggal' => now()->subDay(),
            'tanggal_jatuh_tempo' => now()->addDays(30)->toDateString(),
            'nominal_awal' => $unitModal * $unitCount,
            'nominal_terbayar' => 0,
            'nominal_retur' => 0,
            'sisa_hutang' => $unitModal * $unitCount,
            'status' => 'unpaid',
            'created_at' => now(),
        ]);

        return [$intake, $units, $hutang];
    }

    private function seedOlderPoHutang(float $amount = 5000): SupplierHutang
    {
        $po = DocPurchaseOrder::create([
            'nomor_dokumen' => 'POR-OLD-0001',
            'tanggal_po' => now()->subDays(10),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'subtotal' => $amount,
            'grand_total' => $amount,
            'status' => 'approved',
            'approved_at' => now()->subDays(10),
            'approved_by' => $this->user->id,
        ]);

        return SupplierHutang::create([
            'supplier_id' => $this->supplier->id,
            'po_id' => $po->id,
            'serial_intake_id' => null,
            'tanggal' => now()->subDays(10),
            'tanggal_jatuh_tempo' => now()->addDays(20)->toDateString(),
            'nominal_awal' => $amount,
            'nominal_terbayar' => 0,
            'nominal_retur' => 0,
            'sisa_hutang' => $amount,
            'status' => 'unpaid',
            'created_at' => now()->subDays(10),
        ]);
    }

    #[Test]
    public function rejects_po_and_pbs_together(): void
    {
        [$intake, $units] = $this->seedApprovedPbs();
        $poHutang = $this->seedOlderPoHutang();

        $this->postJson('/api/v1/purchase-returns', [
            'tanggal' => now()->toDateTimeString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'po_id' => $poHutang->po_id,
            'serial_intake_id' => $intake->id,
            'details' => [[
                'product_id' => $this->serial->id,
                'unit_used' => 'UNIT',
                'unit_konversi' => 1,
                'qty_in_unit' => 1,
                'harga_per_unit' => 1000,
                'serial_unit_ids' => [$units[0]->ulid],
            ]],
        ])->assertStatus(422);
    }

    #[Test]
    public function list_and_returnable_units_endpoint(): void
    {
        [$intake, $units] = $this->seedApprovedPbs(1000, 2);

        $list = $this->getJson('/api/v1/purchase-returns/serial-intakes/list?supplier_id='.$this->supplier->id.'&warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->json('data.items');
        $this->assertCount(1, $list);
        $this->assertSame($intake->nomor_dokumen, $list[0]['nomor_dokumen']);
        $this->assertSame(2, (int) ($list[0]['returnable_unit_count'] ?? 0));

        $ret = $this->getJson('/api/v1/purchase-returns/serial-intake/'.$intake->ulid.'/returnable-units')
            ->assertOk()
            ->json('data');
        $this->assertSame(2, $ret['returnable_count']);
        $this->assertCount(2, $ret['units']);
        $this->assertSame(1000.0, (float) $ret['harga_per_unit']);
        $this->assertArrayHasKey('harga_modal', $ret['units'][0]);
        $this->assertSame(1000.0, (float) $ret['units'][0]['harga_modal']);

        // Strip harga when missing po.view_harga (HPP unit masih gated stok.view_hpp)
        $this->user->revokePermissionTo('po.view_harga');
        $stripped = $this->getJson('/api/v1/purchase-returns/serial-intake/'.$intake->ulid.'/returnable-units')
            ->assertOk()
            ->json('data');
        $this->assertNull($stripped['harga_per_unit']);
        $this->assertSame(2, $stripped['returnable_count']);
    }

    #[Test]
    public function approve_nets_pbs_hutang_not_older_po_fifo(): void
    {
        [$intake, $units, $pbsHutang] = $this->seedApprovedPbs(1000, 2);
        $poHutang = $this->seedOlderPoHutang(5000);

        $create = $this->postJson('/api/v1/purchase-returns', [
            'tanggal' => now()->toDateTimeString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_intake_id' => $intake->id,
            'details' => [[
                'product_id' => $this->serial->id,
                'unit_used' => 'UNIT',
                'unit_konversi' => 1,
                'qty_in_unit' => 1,
                'harga_per_unit' => 0,
                'serial_unit_ids' => [$units[0]->ulid],
            ]],
        ])->assertCreated();

        $ulid = $create->json('data.purchase_return.ulid');
        $this->postJson("/api/v1/purchase-returns/{$ulid}/lock")->assertOk();
        $this->postJson("/api/v1/purchase-returns/{$ulid}/approve", [
            'nilai_diakui' => 1000,
        ])->assertOk();

        $pbsHutang->refresh();
        $poHutang->refresh();

        $this->assertSame(1000.0, (float) $pbsHutang->nominal_retur);
        $this->assertSame(1000.0, (float) $pbsHutang->sisa_hutang);
        $this->assertSame(0.0, (float) $poHutang->nominal_retur);
        $this->assertSame(5000.0, (float) $poHutang->sisa_hutang);
        $this->assertSame(0, SupplierDeposit::where('supplier_id', $this->supplier->id)->count());
    }

    #[Test]
    public function approve_excess_becomes_deposit(): void
    {
        [$intake, $units, $pbsHutang] = $this->seedApprovedPbs(1000, 1);

        $create = $this->postJson('/api/v1/purchase-returns', [
            'tanggal' => now()->toDateTimeString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_intake_id' => $intake->id,
            'details' => [[
                'product_id' => $this->serial->id,
                'unit_used' => 'UNIT',
                'unit_konversi' => 1,
                'qty_in_unit' => 1,
                'harga_per_unit' => 0,
                'serial_unit_ids' => [$units[0]->ulid],
            ]],
        ])->assertCreated();

        $ulid = $create->json('data.purchase_return.ulid');
        $this->postJson("/api/v1/purchase-returns/{$ulid}/lock")->assertOk();
        $this->postJson("/api/v1/purchase-returns/{$ulid}/approve", [
            'nilai_diakui' => 1500,
        ])->assertOk();

        $pbsHutang->refresh();
        $this->assertSame(1000.0, (float) $pbsHutang->nominal_retur);
        $this->assertSame(0.0, (float) $pbsHutang->sisa_hutang);

        $deposit = SupplierDeposit::where('supplier_id', $this->supplier->id)->first();
        $this->assertNotNull($deposit);
        $this->assertSame(500.0, (float) $deposit->nominal_awal);
    }

    #[Test]
    public function list_excludes_pbs_without_tersedia_units(): void
    {
        [$intake, $units] = $this->seedApprovedPbs(1000, 1);
        $units[0]->update(['status' => 'retur']);

        $list = $this->getJson('/api/v1/purchase-returns/serial-intakes/list?supplier_id='.$this->supplier->id.'&warehouse_id='.$this->warehouse->id)
            ->assertOk()
            ->json('data.items');

        $this->assertCount(0, $list);

        $msg = $this->getJson('/api/v1/purchase-returns/serial-intake/'.$intake->ulid.'/returnable-units')
            ->assertOk()
            ->json('data.message');
        $this->assertStringContainsString('retur', $msg);
    }

    #[Test]
    public function rejects_unit_from_other_intake(): void
    {
        [$intake] = $this->seedApprovedPbs(1000, 1);

        $foreign = SerialUnit::create([
            'product_id' => $this->serial->id,
            'warehouse_id' => $this->warehouse->id,
            'intake_id' => null,
            'serial_number' => 'SN-FOREIGN',
            'harga_modal' => 1000,
            'cost_per_unit' => 1000,
            'status' => 'tersedia',
        ]);

        $this->postJson('/api/v1/purchase-returns', [
            'tanggal' => now()->toDateTimeString(),
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_intake_id' => $intake->id,
            'details' => [[
                'product_id' => $this->serial->id,
                'unit_used' => 'UNIT',
                'unit_konversi' => 1,
                'qty_in_unit' => 1,
                'harga_per_unit' => 0,
                'serial_unit_ids' => [$foreign->ulid],
            ]],
        ])->assertStatus(422);
    }
}
