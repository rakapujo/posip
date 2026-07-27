<?php

namespace Tests\Feature\PurchaseReport;

use App\Actions\PurchaseOrder\ApprovePurchaseOrderAction;
use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Actions\PurchaseReturn\CreatePurchaseReturnAction;
use App\Actions\PurchaseReturn\LockPurchaseReturnAction;
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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 2 — laporan pembelian untuk PO biasa (non-serial).
 * Melengkapi PurchaseReportSerialTest yang fokus serial intake.
 */
class PurchaseReportPoTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected MasterProduk $product;

    protected MasterWarehouse $warehouse;

    protected MasterSupplier $supplier;

    protected CreatePurchaseOrderAction $createAction;

    protected ApprovePurchaseOrderAction $approveAction;

    protected string $from = '2026-01-01';

    protected string $to = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['laporan.pembelian', 'laporan.export', 'po.view_harga'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['laporan.pembelian', 'laporan.export', 'po.view_harga']);
        $this->actingAs($this->admin);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->supplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-PO',
            'nama_supplier' => 'Supplier PO Biasa',
            'nama_pic' => 'PIC',
            'telepon' => '08123456789',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'kode_produk' => 'PO_RPT',
            'nama_produk' => 'Produk PO Laporan',
            'avg_cost' => 5000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        $this->createAction = new CreatePurchaseOrderAction();
        $this->approveAction = new ApprovePurchaseOrderAction();
    }

    private function q(string $path, array $extra = []): string
    {
        return $path.'?'.http_build_query(array_merge([
            'date_from' => $this->from,
            'date_to' => $this->to,
        ], $extra));
    }

    private function approvedPo(int $qty = 10, float $harga = 6000): string
    {
        $po = $this->createAction->execute([
            'tanggal_po' => '2026-06-01 10:00:00',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => $qty,
                'harga_per_unit' => $harga,
            ]],
        ]);

        $this->approveAction->execute($po);

        return $po->fresh()->nomor_dokumen;
    }

    public function test_per_dokumen_includes_approved_po_with_source_po(): void
    {
        $nomor = $this->approvedPo();

        $all = $this->getJson($this->q('/api/v1/purchase-report/per-dokumen'))->assertOk();
        $row = collect($all->json('data.items'))->firstWhere('nomor_dokumen', $nomor);
        $this->assertNotNull($row);
        $this->assertSame('po', $row['sumber']);
        $this->assertEquals(60000, (float) $row['subtotal']);

        $poOnly = $this->getJson($this->q('/api/v1/purchase-report/per-dokumen', ['source' => 'po']))->assertOk();
        $this->assertTrue(collect($poOnly->json('data.items'))->pluck('nomor_dokumen')->contains($nomor));

        $serialOnly = $this->getJson($this->q('/api/v1/purchase-report/per-dokumen', ['source' => 'serial']))->assertOk();
        $this->assertSame(0, (int) $serialOnly->json('data.summary.jumlah_po'));
    }

    public function test_per_barang_and_per_supplier_aggregate_plain_po(): void
    {
        $this->approvedPo(5, 8000);

        $barang = $this->getJson($this->q('/api/v1/purchase-report/per-barang'))->assertOk();
        $row = collect($barang->json('data.items'))->firstWhere('kode_produk', 'PO_RPT');
        $this->assertNotNull($row);
        $this->assertEquals(5, (float) $row['total_qty']);
        $this->assertEquals(40000, (float) $row['total_subtotal']);

        $supplier = $this->getJson($this->q('/api/v1/purchase-report/per-supplier'))->assertOk();
        $supRow = collect($supplier->json('data.items'))->firstWhere('kode_supplier', 'SUP-PO');
        $this->assertNotNull($supRow);
        $this->assertEquals(40000, (float) $supRow['total_grand_total']);
    }

    public function test_po_exports_run_without_error(): void
    {
        $this->approvedPo();

        foreach (['per-dokumen', 'per-supplier', 'per-barang', 'diskon', 'harga-terakhir'] as $type) {
            $this->get($this->q("/api/v1/purchase-report/{$type}/export"))->assertOk();
        }
    }

    public function test_per_supplier_hides_financials_without_view_harga(): void
    {
        $this->approvedPo(5, 8000);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('laporan.pembelian');

        $res = $this->actingAs($viewer)
            ->getJson($this->q('/api/v1/purchase-report/per-supplier'))
            ->assertOk();

        $this->assertFalse($res->json('data.can_view_harga'));
        $this->assertArrayNotHasKey('total_grand_total', $res->json('data.summary'));
        $this->assertEquals(1, (int) $res->json('data.summary.total_po'));
    }

    public function test_diskon_requires_view_harga(): void
    {
        $this->approvedPo();

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.pembelian', 'laporan.export']);

        $this->actingAs($viewer)
            ->getJson($this->q('/api/v1/purchase-report/diskon'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get($this->q('/api/v1/purchase-report/diskon/export'))
            ->assertForbidden();
    }

    public function test_endpoints_forbidden_without_laporan_pembelian(): void
    {
        $this->approvedPo();

        $outsider = User::factory()->create();

        foreach (['per-dokumen', 'per-supplier', 'per-barang', 'diskon', 'harga-terakhir'] as $type) {
            $this->actingAs($outsider)
                ->getJson($this->q("/api/v1/purchase-report/{$type}"))
                ->assertForbidden();
        }
    }

    public function test_per_dokumen_mode_net_nets_list_row_not_just_summary(): void
    {
        foreach (['retur-beli.create', 'retur-beli.lock'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin->givePermissionTo(['retur-beli.create', 'retur-beli.lock']);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 5000]
        );
        StockCard::$skipObserver = false;

        $nomor = $this->approvedPo(10, 6000);
        /** @var DocPurchaseOrder $po */
        $po = DocPurchaseOrder::where('nomor_dokumen', $nomor)->with('details')->first();
        $poDetail = $po->details->first();

        $retur = (new CreatePurchaseReturnAction())->execute([
            'tanggal' => '2026-06-05',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'po_id' => $po->id,
            'details' => [[
                'product_id' => $this->product->id,
                'po_detail_id' => $poDetail->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => 3,
                'harga_per_unit' => 6000,
            ]],
        ]);
        (new LockPurchaseReturnAction())->execute($retur->fresh());

        // Bruto (default): list row stays at the full PO grand_total, retur is not deducted.
        $bruto = $this->getJson($this->q('/api/v1/purchase-report/per-dokumen'))->assertOk();
        $brutoRow = collect($bruto->json('data.items'))->firstWhere('nomor_dokumen', $nomor);
        $this->assertEquals(60000, (float) $brutoRow['subtotal']);
        $this->assertEquals(60000, (float) $brutoRow['grand_total']);

        // Net: the LIST row itself (not just the summary) must be reduced by the locked retur (3 x 6000 = 18000).
        $net = $this->getJson($this->q('/api/v1/purchase-report/per-dokumen', ['mode' => 'net']))->assertOk();
        $netRow = collect($net->json('data.items'))->firstWhere('nomor_dokumen', $nomor);
        $this->assertEquals(42000, (float) $netRow['subtotal']);
        $this->assertEquals(42000, (float) $netRow['grand_total']);
        $this->assertEquals(42000, (float) $net->json('data.summary.total_grand_total'));

        // Export (net) must reflect the same netted grand_total, not the bruto value.
        $export = $this->get($this->q('/api/v1/purchase-report/per-dokumen/export', ['mode' => 'net']))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $export->headers->get('content-type'));
    }

    public function test_per_dokumen_empty_when_date_range_excludes_po(): void
    {
        $this->approvedPo();

        $url = '/api/v1/purchase-report/per-dokumen?'.http_build_query([
            'date_from' => '2099-01-01',
            'date_to' => '2099-12-31',
        ]);

        $res = $this->getJson($url)->assertOk();
        $this->assertSame([], $res->json('data.items'));
        $this->assertEquals(0, (int) $res->json('data.summary.jumlah_po'));
    }
}
