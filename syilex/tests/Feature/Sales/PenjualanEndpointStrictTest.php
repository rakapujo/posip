<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ApproveManualSalesAction;
use App\Actions\Sales\CreateManualSalesAction;
use App\Actions\SalesReturn\CreateSalesReturnAction;
use App\Actions\SalesReturn\LockSalesReturnAction;
use App\Models\CustomerDeposit;
use App\Models\CustomerPiutang;
use App\Models\DocPembayaranPiutang;
use App\Models\DocPembayaranPiutangDeposit;
use App\Models\DocSales;
use App\Models\DocSalesReturn;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PenjualanEndpointStrictTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private MasterCustomer $customer;

    private MasterCustomer $otherCustomer;

    private MasterWarehouse $warehouse;

    private MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');
        SettingService::set('promo.enabled', true, 'boolean');
        SettingService::set('promo.allow_manual_discount', true, 'boolean');
        SettingService::set('promo.max_manual_discount_percent', 100, 'decimal');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'ST-001',
            'nama' => 'Customer Strict',
            'telepon' => '0800',
            'tempo_default' => 30,
            'jenis' => 'spesifik',
            'status' => 'active',
        ]);
        $this->otherCustomer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'ST-002',
            'nama' => 'Customer Lain',
            'telepon' => '0801',
            'tempo_default' => 30,
            'jenis' => 'spesifik',
            'status' => 'active',
        ]);
        $this->warehouse = MasterWarehouse::factory()->create([
            'status' => 'active',
            'is_saleable' => true,
        ]);
        $this->product = MasterProduk::factory()->create([
            'status' => 'active',
            'avg_cost' => 4000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'harga_4' => 10000,
        ]);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 100, 'avg_cost' => 4000],
        );
        StockCard::$skipObserver = false;
    }

    // --- Sales ---

    public function test_create_rejects_forged_konversi_and_stores_master_value(): void
    {
        $this->permissions(['sales.create']);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sales', $this->apiPayload([
            'details' => [[
                'product_id' => $this->product->id,
                'unit' => 'PCS',
                'konversi' => 12,
                'qty' => 3,
                'harga_satuan' => 10000,
                'diskon_1_tipe' => 'none',
                'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none',
                'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none',
                'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none',
                'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none',
                'diskon_5_nilai' => 0,
            ]],
        ]))->assertCreated();

        $detail = DocSales::manual()
            ->where('ulid', $response->json('data.sales.ulid'))
            ->firstOrFail()
            ->details
            ->first();

        $this->assertSame(1, (int) $detail->konversi);
        $this->assertEquals(3.0, (float) $detail->qty);
        $this->assertSame(3, (int) $detail->qty_base);
    }

    public function test_create_rejects_walk_in_customer(): void
    {
        $walkIn = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'WALK-BO',
            'nama' => 'Walk-in',
            'telepon' => '-',
            'jenis' => 'walk_in',
            'status' => 'active',
        ]);

        $this->permissions(['sales.create', 'deposit-customer.create']);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/sales', $this->apiPayload(['customer_id' => $walkIn->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['customer_id']);

        $this->postJson('/api/v1/customer-deposits', [
            'customer_id' => $walkIn->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 10000,
        ])->assertStatus(422)->assertJsonValidationErrors(['customer_id']);
    }

    public function test_show_strips_hpp_without_view_hpp_and_includes_with_permission(): void
    {
        $sale = $this->approveSale();
        $this->permissions(['sales.view']);
        Sanctum::actingAs($this->user);

        $without = $this->getJson("/api/v1/sales/{$sale->ulid}")
            ->assertOk()
            ->json('data.sales.details.0');
        $this->assertArrayNotHasKey('hpp_at_time', $without);

        Permission::findOrCreate('stok.view_hpp', 'web');
        $this->user->givePermissionTo('stok.view_hpp');
        Sanctum::actingAs($this->user);

        $with = $this->getJson("/api/v1/sales/{$sale->ulid}")
            ->assertOk()
            ->json('data.sales.details.0');
        $this->assertArrayHasKey('hpp_at_time', $with);
    }

    public function test_create_serial_product_when_elektronik_off_returns_422(): void
    {
        SettingService::set('modules.elektronik_enabled', false, 'boolean');
        $serial = MasterProduk::factory()->create([
            'status' => 'active',
            'is_serial' => true,
            'avg_cost' => 5000,
            'unit_4' => 'UNIT',
            'konversi_4' => 1,
            'harga_4' => 10000,
        ]);

        $this->permissions(['sales.create']);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/sales', $this->apiPayload([
            'details' => [[
                'product_id' => $serial->id,
                'unit' => 'UNIT',
                'konversi' => 1,
                'qty' => 1,
                'harga_satuan' => 10000,
                'diskon_1_tipe' => 'none',
                'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none',
                'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none',
                'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none',
                'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none',
                'diskon_5_nilai' => 0,
            ]],
        ]))->assertStatus(422)->assertJsonValidationErrors('details.0.product_id');
    }

    public function test_create_clamps_percent_disc_5_over_100_so_jumlah_not_negative(): void
    {
        $this->permissions(['sales.create']);
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sales', $this->apiPayload([
            'details' => [[
                'product_id' => $this->product->id,
                'unit' => 'PCS',
                'konversi' => 1,
                'qty' => 2,
                'harga_satuan' => 10000,
                'diskon_1_tipe' => 'none',
                'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none',
                'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none',
                'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none',
                'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'percent',
                'diskon_5_nilai' => 150,
            ]],
        ]))->assertCreated();

        $detail = DocSales::manual()
            ->where('ulid', $response->json('data.sales.ulid'))
            ->firstOrFail()
            ->details
            ->first();

        $this->assertEquals(100.0, (float) $detail->diskon_5_nilai);
        $this->assertGreaterThanOrEqual(0.0, (float) $detail->jumlah);
    }

    // --- Retur BO ---

    public function test_retur_approve_nilai_diakui_over_grand_total_returns_422(): void
    {
        $sale = $this->approveSale();
        $return = (new LockSalesReturnAction)->execute($this->draftReturn($sale, 1));

        $this->permissions(['retur-jual.approve']);
        Sanctum::actingAs($this->user);

        $this->postJson("/api/v1/sales-returns/{$return->ulid}/approve", [
            'nilai_diakui' => (float) $return->grand_total + 1,
        ])->assertStatus(422)->assertJsonValidationErrors('nilai_diakui');
    }

    public function test_retur_index_customer_filter_returns_only_matching_customer(): void
    {
        $saleA = $this->approveSale(['customer_id' => $this->customer->id]);
        $saleB = $this->approveSale(['customer_id' => $this->otherCustomer->id]);
        $returnA = (new CreateSalesReturnAction)->execute([
            'tanggal' => '2026-07-20',
            'sales_id' => $saleA->id,
            'details' => [[
                'sales_detail_id' => $saleA->details->first()->id,
                'product_id' => $this->product->id,
                'qty_base' => 1,
            ]],
        ]);
        DocSalesReturn::manual()->where('id', '!=', $returnA->id)->delete();
        (new CreateSalesReturnAction)->execute([
            'tanggal' => '2026-07-20',
            'sales_id' => $saleB->id,
            'details' => [[
                'sales_detail_id' => $saleB->details->first()->id,
                'product_id' => $this->product->id,
                'qty_base' => 1,
            ]],
        ]);

        $this->permissions(['retur-jual.view']);
        Sanctum::actingAs($this->user);

        $items = $this->getJson('/api/v1/sales-returns?customer_id='.$this->customer->id)
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $items);
        $this->assertSame($returnA->ulid, $items[0]['ulid']);
    }

    public function test_retur_show_strips_hpp_without_view_hpp(): void
    {
        $sale = $this->approveSale();
        $return = (new LockSalesReturnAction)->execute($this->draftReturn($sale, 1));

        $this->permissions(['retur-jual.view']);
        Sanctum::actingAs($this->user);

        $detail = $this->getJson("/api/v1/sales-returns/{$return->ulid}")
            ->assertOk()
            ->json('data.sales_return.details.0');

        $this->assertArrayNotHasKey('hpp_at_time', $detail);
    }

    // --- Piutang ---

    public function test_piutang_index_strips_nominal_retur_without_view_nominal(): void
    {
        $sale = $this->approveSale();
        $return = (new LockSalesReturnAction)->execute($this->draftReturn($sale, 1));
        (new \App\Actions\SalesReturn\ApproveSalesReturnAction)->execute($return, ['nilai_diakui' => 5000]);

        $this->permissions(['piutang.view']);
        Sanctum::actingAs($this->user);

        $row = $this->getJson('/api/v1/customer-piutangs')
            ->assertOk()
            ->json('data.items.0');

        $this->assertArrayNotHasKey('nominal_retur', $row);
        $this->assertArrayNotHasKey('sisa_piutang', $row);
    }

    public function test_piutang_index_aging_bucket_b1_30_filters(): void
    {
        $this->approveSale([
            'tanggal' => now()->subDays(45)->toDateString(),
            'tempo_hari' => 30,
        ]);
        $this->approveSale([
            'tanggal' => now()->toDateString(),
            'tempo_hari' => 30,
        ]);

        $this->permissions(['piutang.view', 'piutang.view_nominal']);
        Sanctum::actingAs($this->user);

        $filtered = $this->getJson('/api/v1/customer-piutangs?aging_bucket=b1_30')
            ->assertOk()
            ->json('data.items');

        $this->assertCount(1, $filtered);

        $this->getJson('/api/v1/customer-piutangs/aging-summary')
            ->assertOk()
            ->assertJsonPath('data.total_count', 2);
    }

    public function test_piutang_export_requires_piutang_view_not_only_laporan_export(): void
    {
        Permission::findOrCreate('laporan.export', 'web');
        $limited = User::factory()->create();
        $limited->givePermissionTo('laporan.export');
        Sanctum::actingAs($limited);

        $this->getJson('/api/v1/customer-piutangs/export')->assertForbidden();
    }

    // --- Pembayaran ---

    public function test_pembayaran_create_over_sisa_piutang_returns_422(): void
    {
        $piutang = $this->approveSale()->piutang;

        $this->permissions(['pembayaran-piutang.create']);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/pembayaran-piutangs', $this->paymentPayload($piutang, 20_001))
            ->assertStatus(422)
            ->assertJsonValidationErrors('details');
    }

    public function test_pembayaran_create_cash_and_deposit_same_piutang_draft_ok(): void
    {
        $piutang = $this->approveSale()->piutang;
        $deposit = CustomerDeposit::create([
            'customer_id' => $this->customer->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 5000,
            'nominal_terpakai' => 0,
            'sisa_deposit' => 5000,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $this->permissions(['pembayaran-piutang.create']);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/pembayaran-piutangs', [
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'metode_pembayaran' => 'cash',
            'details' => [
                ['piutang_id' => $piutang->id, 'nominal_dibayar' => 5000, 'sumber' => 'cash'],
                ['piutang_id' => $piutang->id, 'nominal_dibayar' => 5000, 'sumber' => 'deposit'],
            ],
            'deposit_usages' => [
                ['deposit_id' => $deposit->id, 'nominal_digunakan' => 5000],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.pembayaran.status', 'draft');
    }

    public function test_pembayaran_destroy_completed_returns_422_and_draft_ok(): void
    {
        $piutang = $this->approveSale()->piutang;
        $draft = DocPembayaranPiutang::create([
            'nomor_dokumen' => 'PAY-DRAFT-1',
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'total_bayar_cash' => 5000,
            'total_bayar_deposit' => 0,
            'total_pembayaran' => 5000,
            'metode_pembayaran' => 'cash',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        $draft->details()->create([
            'piutang_id' => $piutang->id,
            'nominal_dibayar' => 5000,
            'sumber' => 'cash',
        ]);

        $completed = DocPembayaranPiutang::create([
            'nomor_dokumen' => 'PAY-COMP-1',
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'total_bayar_cash' => 5000,
            'total_bayar_deposit' => 0,
            'total_pembayaran' => 5000,
            'metode_pembayaran' => 'cash',
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        $this->permissions(['pembayaran-piutang.delete']);
        Sanctum::actingAs($this->user);

        $this->deleteJson("/api/v1/pembayaran-piutangs/{$completed->ulid}")->assertStatus(422);
        $this->deleteJson("/api/v1/pembayaran-piutangs/{$draft->ulid}")->assertOk();
        $this->assertDatabaseMissing('doc_pembayaran_piutang', ['ulid' => $draft->ulid]);
    }

    public function test_outstanding_piutangs_strips_money_without_view_nominal(): void
    {
        $piutang = $this->approveSale()->piutang;

        $this->permissions(['pembayaran-piutang.create']);
        Sanctum::actingAs($this->user);

        $row = $this->getJson('/api/v1/pembayaran-piutangs/outstanding-piutangs?customer_id='.$this->customer->id)
            ->assertOk()
            ->json('data.items.0');

        $this->assertSame($piutang->ulid, $row['ulid']);
        $this->assertArrayNotHasKey('sisa_piutang', $row);
        $this->assertArrayNotHasKey('nominal_retur', $row);
    }

    // --- Deposit ---

    public function test_deposit_index_strips_nominal_without_view_nominal(): void
    {
        CustomerDeposit::create([
            'customer_id' => $this->customer->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 10000,
            'nominal_terpakai' => 0,
            'sisa_deposit' => 10000,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);

        $this->permissions(['deposit-customer.view']);
        Sanctum::actingAs($this->user);

        $row = $this->getJson('/api/v1/customer-deposits')
            ->assertOk()
            ->json('data.items.0');

        $this->assertArrayNotHasKey('nominal_awal', $row);
        $this->assertArrayNotHasKey('sisa_deposit', $row);
    }

    public function test_deposit_update_blocked_when_pivot_draft_or_terpakai_positive(): void
    {
        $this->permissions(['deposit-customer.create', 'deposit-customer.update']);
        Sanctum::actingAs($this->user);

        $created = $this->postJson('/api/v1/customer-deposits', [
            'customer_id' => $this->customer->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 10000,
        ])->assertCreated();
        $deposit = CustomerDeposit::where('ulid', $created->json('data.deposit.ulid'))->firstOrFail();

        $draftPayment = DocPembayaranPiutang::create([
            'nomor_dokumen' => 'PAY-PIVOT',
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'total_bayar_cash' => 0,
            'total_bayar_deposit' => 1000,
            'total_pembayaran' => 1000,
            'metode_pembayaran' => 'cash',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);
        DocPembayaranPiutangDeposit::create([
            'pembayaran_id' => $draftPayment->id,
            'deposit_id' => $deposit->id,
            'nominal_digunakan' => 1000,
        ]);

        $this->putJson("/api/v1/customer-deposits/{$deposit->ulid}", [
            'customer_id' => $this->customer->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 12000,
        ])->assertStatus(422);

        DocPembayaranPiutangDeposit::where('deposit_id', $deposit->id)->delete();
        $deposit->update(['nominal_terpakai' => 1000, 'sisa_deposit' => 9000, 'status' => 'used_partial']);

        $this->putJson("/api/v1/customer-deposits/{$deposit->ulid}", [
            'customer_id' => $this->customer->id,
            'tanggal' => '2026-07-20',
            'nominal_awal' => 12000,
        ])->assertStatus(422);
    }

    public function test_deposit_use_over_sisa_throws(): void
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

    private function permissions(array $names): void
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $this->user->givePermissionTo($names);
    }

    private function approveSale(array $overrides = []): DocSales
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

    private function draftReturn(DocSales $sale, float $qty): DocSalesReturn
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

    private function apiPayload(array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '2026-07-20 10:00:00',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tempo_hari' => 30,
            'cash_payment' => false,
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
            ],
            'biaya_kirim' => ['tipe' => 'none', 'nilai' => 0],
            'biaya_lain' => ['tipe' => 'none', 'nilai' => 0],
            'details' => [[
                'product_id' => $this->product->id,
                'unit' => 'PCS',
                'konversi' => 1,
                'qty' => 2,
                'harga_satuan' => 10000,
                'diskon_1_tipe' => 'none',
                'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none',
                'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none',
                'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none',
                'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none',
                'diskon_5_nilai' => 0,
            ]],
        ], $overrides);
    }

    private function paymentPayload(CustomerPiutang $piutang, float $amount): array
    {
        return [
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'metode_pembayaran' => 'cash',
            'details' => [['piutang_id' => $piutang->id, 'nominal_dibayar' => $amount, 'sumber' => 'cash']],
        ];
    }
}
