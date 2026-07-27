<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ApproveManualSalesAction;
use App\Actions\Sales\CreateManualSalesAction;
use App\Actions\SalesReturn\ApproveSalesReturnAction;
use App\Actions\SalesReturn\CreateSalesReturnAction;
use App\Actions\SalesReturn\LockSalesReturnAction;
use App\Models\CustomerDeposit;
use App\Models\DocSales;
use App\Models\DocSalesReturn;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BackofficeSalesReturnTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MasterCustomer $customer;

    private MasterWarehouse $warehouse;

    private MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'BO-RET',
            'nama' => 'Customer Retur',
            'telepon' => '0800',
            'tempo_default' => 30,
            'jenis' => 'spesifik',
            'status' => 'active',
        ]);
        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active', 'is_saleable' => true]);
        $this->product = MasterProduk::factory()->create([
            'status' => 'active',
            'avg_cost' => 4000,
            'unit_1' => 'PCS',
            'konversi_1' => 1,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'harga_4' => 10000,
        ]);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 4000],
        );
        StockCard::$skipObserver = false;
    }

    public function test_draft_has_no_stock_effect_and_lock_commits_once(): void
    {
        $sale = $this->approvedSale();
        $return = $this->draftReturn($sale, 1);

        $this->assertSame('draft', $return->status);
        $this->assertEquals(8, $this->stock());
        $this->assertEquals(0, $sale->details->first()->fresh()->total_returned_base);
        $this->assertTrue($sale->fresh()->canVoid());

        $return = (new LockSalesReturnAction)->execute($return);
        $this->assertSame('lock', $return->status);
        $this->assertEquals(9, $this->stock());
        $this->assertEquals(1, $sale->details->first()->fresh()->total_returned_base);
        $this->assertFalse($sale->fresh()->canVoid());
        $this->assertDatabaseHas('stock_card', [
            'transaction_type' => 'SALES_RETURN',
            'transaction_id' => $return->id,
            'qty_in' => 1,
        ]);

        $this->expectException(ValidationException::class);
        (new LockSalesReturnAction)->execute($return);
    }

    public function test_approve_nets_unpaid_and_partial_piutang_without_stock_change(): void
    {
        $sale = $this->approvedSale();
        $return = (new LockSalesReturnAction)->execute($this->draftReturn($sale, 2));
        $stock = $this->stock();

        $approved = (new ApproveSalesReturnAction)->execute($return, ['nilai_diakui' => 15000]);
        $piutang = $sale->piutang->fresh();

        $this->assertSame('approved', $approved->status);
        $this->assertEquals(15000, $piutang->nominal_retur);
        $this->assertEquals(5000, $piutang->sisa_piutang);
        $this->assertSame('partial', $piutang->status);
        $this->assertNull($approved->deposit);
        $this->assertEquals($stock, $this->stock());

        $this->expectException(ValidationException::class);
        (new ApproveSalesReturnAction)->execute($approved, ['nilai_diakui' => 15000]);
    }

    public function test_partial_payment_is_preserved_when_return_credit_is_applied(): void
    {
        $sale = $this->approvedSale();
        $sale->piutang->recordPayment(5000);
        $return = (new LockSalesReturnAction)->execute($this->draftReturn($sale, 1));

        (new ApproveSalesReturnAction)->execute($return, ['nilai_diakui' => 10000]);
        $piutang = $sale->piutang->fresh();

        $this->assertEquals(20000, $piutang->nominal_awal);
        $this->assertEquals(5000, $piutang->nominal_terbayar);
        $this->assertEquals(10000, $piutang->nominal_retur);
        $this->assertEquals(5000, $piutang->sisa_piutang);
    }

    public function test_paid_return_becomes_immutable_deposit_and_excess_nilai_diakui_rejected(): void
    {
        $paidSale = $this->approvedSale();
        $paidSale->piutang->recordPayment(20000);
        $paidReturn = (new LockSalesReturnAction)->execute($this->draftReturn($paidSale, 1));
        $paidReturn = (new ApproveSalesReturnAction)->execute($paidReturn, ['nilai_diakui' => 10000]);

        $this->assertEquals(10000, $paidReturn->deposit->nominal_awal);
        $this->assertFalse($paidReturn->deposit->canBeEdited());
        $this->assertFalse($paidReturn->deposit->canBeDeleted());

        $unpaidSale = $this->approvedSale();
        $excessReturn = (new LockSalesReturnAction)->execute($this->draftReturn($unpaidSale, 2));

        $this->expectException(ValidationException::class);
        (new ApproveSalesReturnAction)->execute($excessReturn, ['nilai_diakui' => 25000]);
    }

    public function test_zero_recognized_value_changes_neither_piutang_nor_deposit(): void
    {
        $sale = $this->approvedSale();
        $return = (new LockSalesReturnAction)->execute($this->draftReturn($sale, 1));
        (new ApproveSalesReturnAction)->execute($return, ['nilai_diakui' => 0]);

        $this->assertEquals(0, $sale->piutang->fresh()->nominal_retur);
        $this->assertEquals(20000, $sale->piutang->fresh()->sisa_piutang);
        $this->assertSame(0, CustomerDeposit::count());
    }

    public function test_invoice_biaya_excluded_from_pool_and_serial_lock_recomputes_avg_cost_metode_a(): void
    {
        $serial = MasterProduk::factory()->create([
            'status' => 'active',
            'is_serial' => true,
            'avg_cost' => 4000,
            'unit_1' => 'UNIT',
            'konversi_1' => 1,
            'unit_4' => 'UNIT',
            'konversi_4' => 1,
            'harga_4' => 10000,
        ]);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $serial->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 2, 'avg_cost' => 4000],
        );
        StockCard::$skipObserver = false;
        $soldUnit = SerialUnit::create([
            'product_id' => $serial->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_number' => 'BO-SN-SOLD',
            'harga_modal' => 3000,
            'cost_per_unit' => 3000,
            'status' => SerialUnit::STATUS_TERSEDIA,
        ]);
        SerialUnit::create([
            'product_id' => $serial->id,
            'warehouse_id' => $this->warehouse->id,
            'serial_number' => 'BO-SN-KEEP',
            'harga_modal' => 5000,
            'cost_per_unit' => 5000,
            'status' => SerialUnit::STATUS_TERSEDIA,
        ]);

        $sale = $this->approvedSale([
            'details' => [[
                'product_id' => $serial->id,
                'unit' => 'UNIT',
                'konversi' => 1,
                'qty' => 1,
                'harga_satuan' => 10000,
                'serial_unit_ids' => [$soldUnit->ulid],
            ]],
            'biaya_kirim' => ['tipe' => 'nominal', 'nilai' => 2000],
        ]);
        // Setelah jual unit 3000, Metode A sisa = 5000
        $this->assertEquals(5000, (float) $serial->fresh()->avg_cost);
        $this->assertEquals(12000, (float) $sale->grand_total, 'Nota tetap termasuk biaya kirim');

        $detail = $sale->details->first();
        $return = (new CreateSalesReturnAction)->execute([
            'tanggal' => '2026-07-20',
            'sales_id' => $sale->id,
            'details' => [[
                'sales_detail_id' => $detail->id,
                'product_id' => $serial->id,
                'qty_base' => 1,
                'serial_unit_ids' => [$soldUnit->ulid],
            ]],
        ]);

        // Pool retur = total_setelah_diskon (tanpa biaya) → 10000, bukan grand_total 12000
        $this->assertEquals(10000, (float) $return->grand_total);
        $this->assertEquals(10000, (float) $return->subtotal);
        $this->assertEquals(0, (float) $return->pembulatan);

        (new LockSalesReturnAction)->execute($return);
        $this->assertSame(SerialUnit::STATUS_TERSEDIA, $soldUnit->fresh()->status);
        // Metode A: (3000 + 5000) / 2 = 4000 — avg berubah dari 5000
        $this->assertEquals(4000, (float) $serial->fresh()->avg_cost);
        $this->assertDatabaseHas('stock_card', [
            'product_id' => $serial->id,
            'transaction_type' => 'SALES_RETURN',
            'transaction_id' => $return->id,
            'avg_cost_before' => 5000,
            'avg_cost_after' => 4000,
        ]);
        $this->assertDatabaseHas('serial_unit_movements', [
            'serial_unit_id' => $soldUnit->id,
            'doc_type' => 'SALES_RETURN',
            'movement_type' => 'IN',
        ]);
        $this->assertSame(1, SerialUnitMovement::where('doc_type', 'SALES_RETURN')->count());
    }

    public function test_backoffice_endpoints_are_source_scoped(): void
    {
        $manualSale = $this->approvedSale();
        $posSale = DocSales::create([
            'nomor_dokumen' => 'INV-2607-IDOR',
            'source' => 'pos',
            'tanggal' => now(),
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'status' => 'completed',
        ]);
        $posReturn = DocSalesReturn::create([
            'nomor_dokumen' => 'RPJ-2607-IDOR',
            'source' => 'pos',
            'tanggal' => now(),
            'sales_id' => $posSale->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'status' => 'approved',
        ]);
        foreach (['retur-jual.view', 'retur-jual.create'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->user->givePermissionTo(['retur-jual.view', 'retur-jual.create']);
        Sanctum::actingAs($this->user);

        $this->getJson("/api/v1/sales-returns/{$posReturn->ulid}")->assertNotFound();
        $this->getJson('/api/v1/sales-returns/returnable-sales')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.ulid', $manualSale->ulid);
    }

    public function test_free_mode_store_rejects_duplicate_product_and_unit(): void
    {
        foreach (['retur-jual.create'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->user->givePermissionTo(['retur-jual.create']);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sales-returns', [
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'qty_base' => 1,
                    'harga_satuan' => 10000,
                    'unit' => 'PCS',
                ],
                [
                    'product_id' => $this->product->id,
                    'qty_base' => 1,
                    'harga_satuan' => 10000,
                    'unit' => 'PCS',
                ],
            ],
        ])->assertStatus(422);

        $response->assertJsonValidationErrors('details');
        $this->assertSame(0, DocSalesReturn::count());
    }

    public function test_free_mode_store_allows_same_product_with_different_unit(): void
    {
        foreach (['retur-jual.create'] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $this->user->givePermissionTo(['retur-jual.create']);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/sales-returns', [
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'qty_base' => 1,
                    'harga_satuan' => 10000,
                    'unit' => 'PCS',
                ],
                [
                    'product_id' => $this->product->id,
                    'qty_base' => 12,
                    'harga_satuan' => 100000,
                    'unit' => 'BOX',
                ],
            ],
        ])->assertCreated();

        $this->assertSame(1, DocSalesReturn::count());
    }

    private function approvedSale(array $overrides = []): DocSales
    {
        $data = array_replace_recursive([
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tempo_hari' => 30,
            'details' => [[
                'product_id' => $this->product->id,
                'unit' => 'PCS',
                'konversi' => 1,
                'qty' => 2,
                'harga_satuan' => 10000,
            ]],
        ], $overrides);

        return (new ApproveManualSalesAction)->execute(
            (new CreateManualSalesAction)->execute($data),
        );
    }

    private function draftReturn(DocSales $sale, float $qty)
    {
        $detail = $sale->details->first();

        return (new CreateSalesReturnAction)->execute([
            'tanggal' => '2026-07-20',
            'sales_id' => $sale->id,
            'details' => [[
                'sales_detail_id' => $detail->id,
                'product_id' => $detail->product_id,
                'qty_base' => $qty,
            ]],
        ]);
    }

    private function stock(): float
    {
        return (float) InventoryStock::where([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
        ])->value('qty');
    }
}
