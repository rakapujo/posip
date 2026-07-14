<?php

namespace Tests\Feature\PembayaranHutang;

use App\Actions\PembayaranHutang\CompletePembayaranHutangAction;
use App\Actions\PembayaranHutang\CreatePembayaranHutangAction;
use App\Actions\PembayaranHutang\UpdatePembayaranHutangAction;
use App\Actions\PurchaseOrder\ApprovePurchaseOrderAction;
use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\SupplierDeposit;
use App\Models\SupplierHutang;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 3 P1 — UpdatePembayaranHutangAction: replace detail, totals, deposit, guard draft.
 */
class UpdatePembayaranHutangActionTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected MasterWarehouse $warehouse;

    protected MasterSupplier $supplier;

    protected MasterProduk $product;

    protected SupplierHutang $hutang;

    protected CreatePembayaranHutangAction $createAction;

    protected UpdatePembayaranHutangAction $updateAction;

    protected CompletePembayaranHutangAction $completeAction;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->supplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-UPD',
            'nama_supplier' => 'Supplier Update PH',
            'nama_pic' => 'PIC',
            'telepon' => '081234',
            'tempo_default' => 14,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'avg_cost' => 5000,
            'status' => 'active',
        ]);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 5000]
        );
        StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE',
            'tanggal' => '2026-04-01',
            'qty_in' => 10,
            'qty_out' => 0,
            'cost_per_unit' => 5000,
        ]);
        StockCard::$skipObserver = false;

        $createPo = new CreatePurchaseOrderAction();
        $approvePo = new ApprovePurchaseOrderAction();
        $po = $createPo->execute([
            'tanggal_po' => '2026-04-12',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => 10,
                'harga_per_unit' => 10000,
            ]],
        ]);
        $approvePo->execute($po);

        $this->hutang = SupplierHutang::where('po_id', $po->id)->first();

        $this->createAction = new CreatePembayaranHutangAction();
        $this->updateAction = new UpdatePembayaranHutangAction();
        $this->completeAction = new CompletePembayaranHutangAction();
    }

    private function basePaymentData(float $nominal, array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '2026-04-15',
            'supplier_id' => $this->supplier->id,
            'metode_pembayaran' => 'cash',
            'details' => [[
                'hutang_id' => $this->hutang->id,
                'nominal_dibayar' => $nominal,
                'sumber' => 'cash',
            ]],
        ], $overrides);
    }

    private function createDeposit(float $nominalAwal): SupplierDeposit
    {
        $returId = DB::table('doc_purchase_return')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-UPD-'.fake()->unique()->numerify('####'),
            'tanggal' => '2026-04-10',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'po_id' => $this->hutang->po_id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return SupplierDeposit::create([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $this->supplier->id,
            'retur_id' => $returId,
            'tanggal' => '2026-04-12',
            'nominal_awal' => $nominalAwal,
            'nominal_terpakai' => 0,
            'sisa_deposit' => $nominalAwal,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);
    }

    public function test_update_draft_replaces_details_and_recalculates_totals(): void
    {
        $payment = $this->createAction->execute($this->basePaymentData(50000));

        $updated = $this->updateAction->execute($payment, $this->basePaymentData(35000, [
            'tanggal' => '2026-04-16',
            'notes' => 'Revisi nominal',
        ]));

        $this->assertEquals('2026-04-16', $updated->tanggal->toDateString());
        $this->assertEquals('Revisi nominal', $updated->notes);
        $this->assertEquals(35000, (float) $updated->total_bayar_cash);
        $this->assertEquals(0, (float) $updated->total_bayar_deposit);
        $this->assertEquals(35000, (float) $updated->total_pembayaran);
        $this->assertEquals(1, $updated->details->count());
        $this->assertEquals(35000, (float) $updated->details->first()->nominal_dibayar);
    }

    public function test_update_throws_when_not_draft(): void
    {
        $payment = $this->createAction->execute($this->basePaymentData(30000));
        $this->completeAction->execute($payment);

        $this->expectException(ValidationException::class);
        $this->updateAction->execute($payment->fresh(), $this->basePaymentData(20000));
    }

    public function test_update_throws_when_hutang_belongs_to_other_supplier(): void
    {
        $otherSupplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-OTH',
            'nama_supplier' => 'Other Supplier',
            'nama_pic' => 'X',
            'telepon' => '08111',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $otherHutangId = DB::table('supplier_hutang')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $otherSupplier->id,
            'tanggal' => now(),
            'nominal_awal' => 20000,
            'nominal_terbayar' => 0,
            'sisa_hutang' => 20000,
            'status' => 'unpaid',
            'created_at' => now(),
        ]);

        $payment = $this->createAction->execute($this->basePaymentData(10000));

        try {
            $this->updateAction->execute($payment, [
                'tanggal' => '2026-04-15',
                'supplier_id' => $this->supplier->id,
                'metode_pembayaran' => 'cash',
                'details' => [[
                    'hutang_id' => $otherHutangId,
                    'nominal_dibayar' => 5000,
                    'sumber' => 'cash',
                ]],
            ]);
            $this->fail('Update dengan hutang supplier lain seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details', $e->errors());
        }
    }

    public function test_update_with_deposit_usages_recalculates_totals(): void
    {
        $deposit = $this->createDeposit(60000);
        $payment = $this->createAction->execute($this->basePaymentData(20000));

        $updated = $this->updateAction->execute($payment, [
            'tanggal' => '2026-04-17',
            'supplier_id' => $this->supplier->id,
            'metode_pembayaran' => 'cash',
            'details' => [[
                'hutang_id' => $this->hutang->id,
                'nominal_dibayar' => 45000,
                'sumber' => 'deposit',
            ]],
            'deposit_usages' => [[
                'deposit_id' => $deposit->id,
                'nominal_digunakan' => 45000,
            ]],
        ]);

        $this->assertEquals(0, (float) $updated->total_bayar_cash);
        $this->assertEquals(45000, (float) $updated->total_bayar_deposit);
        $this->assertEquals(45000, (float) $updated->total_pembayaran);
        $this->assertEquals(1, $updated->depositUsages->count());
    }

    public function test_update_throws_when_deposit_total_mismatch(): void
    {
        $deposit = $this->createDeposit(50000);
        $payment = $this->createAction->execute($this->basePaymentData(10000));

        try {
            $this->updateAction->execute($payment, [
                'tanggal' => '2026-04-17',
                'supplier_id' => $this->supplier->id,
                'metode_pembayaran' => 'cash',
                'details' => [[
                    'hutang_id' => $this->hutang->id,
                    'nominal_dibayar' => 40000,
                    'sumber' => 'deposit',
                ]],
                'deposit_usages' => [[
                    'deposit_id' => $deposit->id,
                    'nominal_digunakan' => 25000,
                ]],
            ]);
            $this->fail('Mismatch deposit vs detail seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deposit_usages', $e->errors());
        }
    }

    public function test_update_throws_when_deposit_belongs_to_other_supplier(): void
    {
        $otherSupplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-DEP',
            'nama_supplier' => 'Deposit Owner',
            'nama_pic' => 'Y',
            'telepon' => '08122',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $foreignReturId = DB::table('doc_purchase_return')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-FGN',
            'tanggal' => '2026-04-10',
            'supplier_id' => $otherSupplier->id,
            'warehouse_id' => $this->warehouse->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $foreignDeposit = SupplierDeposit::create([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $otherSupplier->id,
            'retur_id' => $foreignReturId,
            'tanggal' => '2026-04-12',
            'nominal_awal' => 30000,
            'nominal_terpakai' => 0,
            'sisa_deposit' => 30000,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $payment = $this->createAction->execute($this->basePaymentData(10000));

        try {
            $this->updateAction->execute($payment, [
                'tanggal' => '2026-04-17',
                'supplier_id' => $this->supplier->id,
                'metode_pembayaran' => 'cash',
                'details' => [[
                    'hutang_id' => $this->hutang->id,
                    'nominal_dibayar' => 20000,
                    'sumber' => 'deposit',
                ]],
                'deposit_usages' => [[
                    'deposit_id' => $foreignDeposit->id,
                    'nominal_digunakan' => 20000,
                ]],
            ]);
            $this->fail('Deposit supplier lain seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deposit_usages', $e->errors());
        }
    }

    public function test_completed_payment_cannot_be_updated_via_http(): void
    {
        Permission::firstOrCreate(['name' => 'pembayaran-hutang.update', 'guard_name' => 'web']);
        $this->user->givePermissionTo('pembayaran-hutang.update');

        $payment = $this->createAction->execute($this->basePaymentData(25000));
        $this->completeAction->execute($payment);

        $this->actingAs($this->user)
            ->putJson("/api/v1/pembayaran-hutangs/{$payment->ulid}", $this->basePaymentData(15000))
            ->assertStatus(422);
    }
}
