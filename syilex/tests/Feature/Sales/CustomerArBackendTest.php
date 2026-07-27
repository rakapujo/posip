<?php

namespace Tests\Feature\Sales;

use App\Actions\PembayaranPiutang\CompletePembayaranPiutangAction;
use App\Actions\PembayaranPiutang\CreatePembayaranPiutangAction;
use App\Actions\Sales\ApproveManualSalesAction;
use App\Actions\Sales\CreateManualSalesAction;
use App\Models\CustomerDeposit;
use App\Models\CustomerPiutang;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use App\Services\CustomerRules;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CustomerArBackendTest extends TestCase
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
            'kode_customer' => 'AR-001', 'nama' => 'Customer AR', 'telepon' => '0800',
            'jenis' => 'spesifik', 'status' => 'active',
        ]);
        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active', 'is_saleable' => true]);
        $this->product = MasterProduk::factory()->create([
            'status' => 'active', 'avg_cost' => 4000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 10000,
        ]);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
        ], [
            'qty' => 100,
            'avg_cost' => 4000,
        ]);
        StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE',
            'tanggal' => '2026-07-01',
            'qty_in' => 100,
            'qty_out' => 0,
            'cost_per_unit' => 4000,
        ]);
        StockCard::$skipObserver = false;
    }

    public function test_payment_partial_full_overpay_and_completed_immutability(): void
    {
        $piutang = $this->approveSale();
        $create = new CreatePembayaranPiutangAction;
        $complete = new CompletePembayaranPiutangAction;

        $first = $create->execute($this->paymentData($piutang, 8000));
        $complete->execute($first);
        $this->assertSame('partial', $piutang->fresh()->status);
        $this->assertEquals(12000, $piutang->fresh()->sisa_piutang);

        $this->expectException(ValidationException::class);
        $create->execute($this->paymentData($piutang, 12001));

        $last = $create->execute($this->paymentData($piutang, 12000));
        $complete->execute($last);
        $this->assertSame('paid', $piutang->fresh()->status);
        $this->assertEquals(0, $piutang->fresh()->sisa_piutang);

        $this->expectException(ValidationException::class);
        $complete->execute($last->fresh());
    }

    public function test_deposit_allocation_updates_both_ledgers_and_invariants(): void
    {
        $piutang = $this->approveSale();
        $deposit = CustomerDeposit::create([
            'customer_id' => $this->customer->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 15000,
            'nominal_terpakai' => 0,
            'sisa_deposit' => 15000,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);
        try {
            (new CreatePembayaranPiutangAction)->execute([
                'tanggal' => '2026-07-20',
                'customer_id' => $this->customer->id,
                'metode_pembayaran' => 'cash',
                'details' => [['piutang_id' => $piutang->id, 'nominal_dibayar' => 5000, 'sumber' => 'deposit']],
                'deposit_usages' => [],
            ]);
            $this->fail('Deposit allocation mismatch should fail.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deposit_usages', $e->errors());
        }
        $payment = (new CreatePembayaranPiutangAction)->execute([
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'metode_pembayaran' => 'cash',
            'details' => [['piutang_id' => $piutang->id, 'nominal_dibayar' => 15000, 'sumber' => 'deposit']],
            'deposit_usages' => [['deposit_id' => $deposit->id, 'nominal_digunakan' => 15000]],
        ]);
        (new CompletePembayaranPiutangAction)->execute($payment);

        $this->assertEquals(0, $deposit->fresh()->sisa_deposit);
        $this->assertEquals(5000, $piutang->fresh()->sisa_piutang);
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    public function test_manual_deposit_is_mutable_only_before_usage(): void
    {
        foreach (['deposit-customer.create', 'deposit-customer.update', 'deposit-customer.delete'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user->givePermissionTo(['deposit-customer.create', 'deposit-customer.update', 'deposit-customer.delete']);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/customer-deposits', [
            'customer_id' => $this->customer->id, 'tanggal' => '2026-07-20', 'nominal_awal' => 10000,
        ])->assertCreated();
        $deposit = CustomerDeposit::where('ulid', $response->json('data.deposit.ulid'))->firstOrFail();

        $deposit->update(['nominal_terpakai' => 1000, 'sisa_deposit' => 9000, 'status' => 'used_partial']);
        $payload = [
            'customer_id' => $this->customer->id, 'tanggal' => '2026-07-20', 'nominal_awal' => 12000,
        ];
        $this->putJson("/api/v1/customer-deposits/{$deposit->ulid}", $payload)->assertUnprocessable();
        $this->deleteJson("/api/v1/customer-deposits/{$deposit->ulid}")->assertUnprocessable();

        $fromReturn = new CustomerDeposit(['retur_id' => 123, 'nominal_terpakai' => 0]);
        $this->assertFalse($fromReturn->canBeEdited());
        $this->assertFalse($fromReturn->canBeDeleted());
    }

    public function test_piutang_list_and_aging_are_eager_loaded_without_query_growth(): void
    {
        foreach (['piutang.view', 'piutang.view_nominal'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user->givePermissionTo(['piutang.view', 'piutang.view_nominal']);
        Sanctum::actingAs($this->user);
        $first = $this->approveSale();

        $this->getJson('/api/v1/customer-piutangs')->assertOk();
        $this->getJson("/api/v1/customer-piutangs/{$first->ulid}")
            ->assertOk()->assertJsonPath('data.piutang.sales.nomor_dokumen', $first->sales->nomor_dokumen);
        DB::flushQueryLog();
        DB::enableQueryLog();
        $this->getJson('/api/v1/customer-piutangs')->assertOk()
            ->assertJsonPath('data.items.0.customer.kode_customer', 'AR-001');
        $baseline = count(DB::getQueryLog());

        $this->approveSale();
        $this->approveSale();
        DB::flushQueryLog();
        $this->getJson('/api/v1/customer-piutangs')->assertOk()->assertJsonCount(3, 'data.items');
        $this->assertLessThanOrEqual($baseline + 1, count(DB::getQueryLog()));
        $this->getJson('/api/v1/customer-piutangs/aging-summary')
            ->assertOk()->assertJsonPath('data.total_count', 3);
    }

    public function test_customer_downstream_guards_cover_piutang_and_deposit(): void
    {
        $this->approveSale();
        $this->assertNotNull(CustomerRules::deactivationBlockMessage($this->customer));
        $this->assertNotNull(CustomerRules::deletionBlockMessage($this->customer));

        CustomerPiutang::query()->delete();
        CustomerDeposit::create([
            'customer_id' => $this->customer->id, 'tanggal' => now(),
            'nominal_awal' => 1000, 'nominal_terpakai' => 0, 'sisa_deposit' => 1000, 'status' => 'available',
        ]);
        $this->assertStringContainsString('deposit', CustomerRules::deactivationBlockMessage($this->customer));
    }

    // ==================== Fase 1 — permission gating coverage ====================

    public function test_piutang_index_forbidden_without_view_permission(): void
    {
        $denied = User::factory()->create();

        $this->actingAs($denied)
            ->getJson('/api/v1/customer-piutangs')
            ->assertForbidden();
    }

    public function test_piutang_show_strips_nominal_without_view_nominal_permission(): void
    {
        Permission::findOrCreate('piutang.view', 'web');
        $piutang = $this->approveSale();

        $limited = User::factory()->create();
        $limited->givePermissionTo('piutang.view'); // no piutang.view_nominal
        Sanctum::actingAs($limited);

        $data = $this->getJson("/api/v1/customer-piutangs/{$piutang->ulid}")
            ->assertOk()
            ->json('data.piutang');

        $this->assertArrayNotHasKey('nominal_awal', $data);
        $this->assertArrayNotHasKey('nominal_terbayar', $data);
        $this->assertArrayNotHasKey('sisa_piutang', $data);
        $this->assertArrayNotHasKey('nominal_retur', $data);
    }

    public function test_piutang_show_includes_nominal_with_view_nominal_permission(): void
    {
        foreach (['piutang.view', 'piutang.view_nominal'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $piutang = $this->approveSale();

        $full = User::factory()->create();
        $full->givePermissionTo(['piutang.view', 'piutang.view_nominal']);
        Sanctum::actingAs($full);

        $data = $this->getJson("/api/v1/customer-piutangs/{$piutang->ulid}")
            ->assertOk()
            ->json('data.piutang');

        $this->assertArrayHasKey('sisa_piutang', $data);
    }

    public function test_pembayaran_piutang_index_forbidden_without_view_permission(): void
    {
        $denied = User::factory()->create();

        $this->actingAs($denied)
            ->getJson('/api/v1/pembayaran-piutangs')
            ->assertForbidden();
    }

    public function test_pembayaran_piutang_store_forbidden_without_create_permission(): void
    {
        $piutang = $this->approveSale();

        $denied = User::factory()->create();
        Sanctum::actingAs($denied);

        $this->postJson('/api/v1/pembayaran-piutangs', $this->paymentData($piutang, 5000))
            ->assertForbidden();
    }

    public function test_deposit_customer_index_forbidden_without_view_permission(): void
    {
        $denied = User::factory()->create();

        $this->actingAs($denied)
            ->getJson('/api/v1/customer-deposits')
            ->assertForbidden();
    }

    public function test_deposit_customer_store_forbidden_without_create_permission(): void
    {
        $denied = User::factory()->create();
        Sanctum::actingAs($denied);

        $this->postJson('/api/v1/customer-deposits', [
            'customer_id' => $this->customer->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 10000,
        ])->assertForbidden();
    }

    public function test_customer_deposit_use_melebihi_sisa_throws(): void
    {
        $deposit = CustomerDeposit::create([
            'customer_id' => $this->customer->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 500_000,
            'nominal_terpakai' => 200_000,
            'sisa_deposit' => 300_000,
            'status' => 'used_partial',
            'created_by' => $this->user->id,
        ]);

        $this->expectException(ValidationException::class);
        $deposit->use(1_000_000);
    }

    public function test_deposit_customer_export_forbidden_without_view_nominal(): void
    {
        foreach (['deposit-customer.view', 'laporan.export'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $limited = User::factory()->create();
        $limited->givePermissionTo(['deposit-customer.view', 'laporan.export']);
        Sanctum::actingAs($limited);

        $this->getJson('/api/v1/customer-deposits/export')->assertForbidden();
    }

    public function test_deposit_customer_export_ok_with_view_nominal(): void
    {
        foreach (['deposit-customer.view', 'laporan.export', 'piutang.view_nominal'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        CustomerDeposit::create([
            'customer_id' => $this->customer->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 10_000,
            'nominal_terpakai' => 0,
            'sisa_deposit' => 10_000,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $exporter = User::factory()->create();
        $exporter->givePermissionTo(['deposit-customer.view', 'laporan.export', 'piutang.view_nominal']);
        Sanctum::actingAs($exporter);

        $this->get('/api/v1/customer-deposits/export')->assertOk();
    }

    public function test_piutang_export_forbidden_without_view_permission(): void
    {
        Permission::findOrCreate('laporan.export', 'web');
        $denied = User::factory()->create();
        $denied->givePermissionTo('laporan.export');
        Sanctum::actingAs($denied);

        $this->getJson('/api/v1/customer-piutangs/export')->assertForbidden();
    }

    private function approveSale(): CustomerPiutang
    {
        $sale = (new CreateManualSalesAction)->execute([
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tempo_hari' => 30,
            'details' => [[
                'product_id' => $this->product->id, 'unit' => 'PCS',
                'konversi' => 1, 'qty' => 2, 'harga_satuan' => 10000,
            ]],
        ]);

        return (new ApproveManualSalesAction)->execute($sale)->piutang;
    }

    private function paymentData(CustomerPiutang $piutang, float $amount): array
    {
        return [
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'metode_pembayaran' => 'cash',
            'details' => [['piutang_id' => $piutang->id, 'nominal_dibayar' => $amount, 'sumber' => 'cash']],
        ];
    }
}
