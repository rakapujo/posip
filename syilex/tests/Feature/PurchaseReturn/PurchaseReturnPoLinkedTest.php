<?php

namespace Tests\Feature\PurchaseReturn;

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
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 3 P1 — retur pembelian terkait PO: batas qty, returnable-details, kumulatif.
 */
class PurchaseReturnPoLinkedTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MasterWarehouse $warehouse;

    protected MasterSupplier $supplier;

    protected MasterProduk $product;

    protected CreatePurchaseOrderAction $createPoAction;

    protected ApprovePurchaseOrderAction $approvePoAction;

    protected CreatePurchaseReturnAction $createReturAction;

    protected LockPurchaseReturnAction $lockAction;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        foreach (['retur-beli.create', 'retur-beli.lock'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['retur-beli.create', 'retur-beli.lock']);
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->supplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-PO-LNK',
            'nama_supplier' => 'Supplier PO Linked',
            'nama_pic' => 'PIC',
            'telepon' => '08123456789',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Produk PO Retur',
            'avg_cost' => 5000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 5000]
        );
        StockCard::$skipObserver = false;

        $this->createPoAction = new CreatePurchaseOrderAction();
        $this->approvePoAction = new ApprovePurchaseOrderAction();
        $this->createReturAction = new CreatePurchaseReturnAction();
        $this->lockAction = new LockPurchaseReturnAction();
    }

    private function approvedPo(int $qtyOrdered = 10): DocPurchaseOrder
    {
        $po = $this->createPoAction->execute([
            'tanggal_po' => '2026-06-01',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => $qtyOrdered,
                'harga_per_unit' => 6000,
            ]],
        ]);

        $this->approvePoAction->execute($po);

        return $po->fresh(['details']);
    }

    private function poLinkedReturData(DocPurchaseOrder $po, int $qty, array $overrides = []): array
    {
        $poDetail = $po->details->first();

        return array_merge([
            'tanggal' => '2026-06-05',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'po_id' => $po->id,
            'details' => [[
                'product_id' => $this->product->id,
                'po_detail_id' => $poDetail->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => $qty,
                'harga_per_unit' => 6000,
            ]],
        ], $overrides);
    }

    public function test_returnable_details_shows_full_qty_available(): void
    {
        $po = $this->approvedPo(10);

        $response = $this->getJson("/api/v1/purchase-returns/po/{$po->ulid}/returnable-details")
            ->assertOk();

        $detail = $response->json('data.details.0');
        $this->assertNotNull($detail);
        $this->assertEquals(10, (float) $detail['qty_available']);
        $this->assertEquals(0, (float) $detail['qty_returned']);
    }

    public function test_lock_succeeds_within_po_qty_limit(): void
    {
        $po = $this->approvedPo(10);

        $retur = $this->createReturAction->execute($this->poLinkedReturData($po, 6));
        $locked = $this->lockAction->execute($retur->fresh());

        $this->assertSame('lock', $locked->status);
        $this->assertEquals(6, (int) $locked->details->first()->qty_in_base);
    }

    public function test_lock_rejects_qty_exceeding_po_remaining(): void
    {
        $po = $this->approvedPo(10);
        $retur = $this->createReturAction->execute($this->poLinkedReturData($po, 11));

        try {
            $this->lockAction->execute($retur->fresh());
            $this->fail('Lock seharusnya ditolak karena qty melebihi PO.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('po_limit', $e->errors());
        }
    }

    public function test_cumulative_returns_respect_po_limit(): void
    {
        $po = $this->approvedPo(10);

        $retur1 = $this->createReturAction->execute($this->poLinkedReturData($po, 4));
        $this->lockAction->execute($retur1->fresh());

        $returnable = $this->getJson("/api/v1/purchase-returns/po/{$po->ulid}/returnable-details")
            ->assertOk()
            ->json('data.details.0');
        $this->assertEquals(6, (float) $returnable['qty_available']);

        $retur2 = $this->createReturAction->execute($this->poLinkedReturData($po, 6));
        $this->lockAction->execute($retur2->fresh());

        $this->getJson("/api/v1/purchase-returns/po/{$po->ulid}/returnable-details")
            ->assertOk()
            ->assertJsonPath('data.details', []);

        $retur3 = $this->createReturAction->execute($this->poLinkedReturData($po, 1));

        try {
            $this->lockAction->execute($retur3->fresh());
            $this->fail('Retur ketiga seharusnya ditolak — PO sudah habis diretur.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('po_limit', $e->errors());
        }
    }

    public function test_draft_return_does_not_reduce_po_available_qty(): void
    {
        $po = $this->approvedPo(10);

        $this->createReturAction->execute($this->poLinkedReturData($po, 8));

        $returnable = $this->getJson("/api/v1/purchase-returns/po/{$po->ulid}/returnable-details")
            ->assertOk()
            ->json('data.details.0');

        $this->assertEquals(10, (float) $returnable['qty_available']);
    }

    public function test_returnable_details_rejects_non_approved_po(): void
    {
        $po = $this->createPoAction->execute([
            'tanggal_po' => '2026-06-01',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => 5,
                'harga_per_unit' => 6000,
            ]],
        ]);

        $this->getJson("/api/v1/purchase-returns/po/{$po->ulid}/returnable-details")
            ->assertStatus(422);
    }
}
