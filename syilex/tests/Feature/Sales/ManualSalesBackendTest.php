<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ApproveManualSalesAction;
use App\Actions\Sales\CreateManualSalesAction;
use App\Actions\Sales\VoidManualSalesAction;
use App\Exports\SalesPerNotaExport;
use App\Models\DocSales;
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

class ManualSalesBackendTest extends TestCase
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
            'kode_customer' => 'BO-001',
            'nama' => 'Customer BO',
            'telepon' => '0800',
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
            ['qty' => 10, 'avg_cost' => 4000],
        );
        StockCard::$skipObserver = false;
    }

    public function test_draft_does_not_post_stock_and_approve_creates_piutang(): void
    {
        $sale = (new CreateManualSalesAction)->execute($this->data());
        $this->assertSame('manual', $sale->source);
        $this->assertSame('draft', $sale->status);
        $this->assertEquals(10, $this->stock());

        $sale = (new ApproveManualSalesAction)->execute($sale);
        $this->assertSame('completed', $sale->status);
        $this->assertEquals(8, $this->stock());
        $this->assertEquals(20000, $sale->piutang->nominal_awal);
        $this->assertSame('unpaid', $sale->piutang->status);
    }

    public function test_cash_approval_settles_piutang_and_cannot_be_voided(): void
    {
        $sale = (new CreateManualSalesAction)->execute($this->data([
            'cash_payment' => true,
            'cash_metode' => 'cash',
        ]));
        $sale = (new ApproveManualSalesAction)->execute($sale);

        $this->assertSame('paid', $sale->piutang->fresh()->status);
        $this->assertDatabaseHas('doc_pembayaran_piutang', [
            'customer_id' => $this->customer->id,
            'status' => 'completed',
        ]);
        $this->expectException(ValidationException::class);
        (new VoidManualSalesAction)->execute($sale, 'salah input');
    }

    public function test_unpaid_tempo_sale_can_be_voided_and_stock_is_restored(): void
    {
        $sale = (new ApproveManualSalesAction)->execute(
            (new CreateManualSalesAction)->execute($this->data()),
        );
        $sale = (new VoidManualSalesAction)->execute($sale, 'salah input');

        $this->assertSame('voided', $sale->status);
        $this->assertEquals(10, $this->stock());
        $this->assertSame('cancelled', $sale->piutang->fresh()->status);
    }

    public function test_manual_scope_excludes_pos_sales(): void
    {
        DocSales::create([
            'nomor_dokumen' => 'INV-2607-9999',
            'source' => 'pos',
            'tanggal' => now(),
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'status' => 'completed',
        ]);
        $manual = (new CreateManualSalesAction)->execute($this->data());

        $this->assertSame(1, DocSales::manual()->count());
        $this->assertSame(1, DocSales::pos()->count());

        Permission::findOrCreate('sales.view', 'web');
        $this->user->givePermissionTo('sales.view');
        Sanctum::actingAs($this->user);
        $this->getJson('/api/v1/sales')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.ulid', $manual->ulid);
    }

    public function test_approval_revalidates_saleable_warehouse(): void
    {
        $sale = (new CreateManualSalesAction)->execute($this->data());
        $this->warehouse->update(['is_saleable' => false]);

        $this->expectException(ValidationException::class);
        (new ApproveManualSalesAction)->execute($sale);
    }

    public function test_public_receipt_blocks_draft_and_strips_hpp(): void
    {
        $sale = (new CreateManualSalesAction)->execute($this->data());

        $this->getJson("/api/v1/public/receipt/{$sale->ulid}")->assertNotFound();

        $sale = (new ApproveManualSalesAction)->execute($sale);
        $response = $this->getJson("/api/v1/public/receipt/{$sale->ulid}")
            ->assertOk();

        $this->assertArrayNotHasKey('hpp_at_time', $response->json('data.sales.details.0'));
    }

    public function test_sales_export_query_keeps_manual_sales_without_terminal(): void
    {
        $sale = (new ApproveManualSalesAction)->execute(
            (new CreateManualSalesAction)->execute($this->data()),
        );

        $row = (new SalesPerNotaExport('2026-07-20', '2026-07-20', source: 'manual'))
            ->query()
            ->where('ds.id', $sale->id)
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->nama_terminal);
    }

    public function test_store_normalizes_cash_forces_tempo_zero(): void
    {
        $this->grantSalesPermissions();
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sales', $this->apiPayload([
            'cash_payment' => true,
            'tempo_hari' => 30,
            'cash_metode' => 'cash',
        ]))->assertCreated();

        $ulid = $response->json('data.sales.ulid');
        $sale = DocSales::manual()->where('ulid', $ulid)->first();
        $this->assertTrue((bool) $sale->cash_payment);
        $this->assertSame(0, (int) $sale->tempo_hari);
    }

    public function test_store_normalizes_tempo_zero_to_cash(): void
    {
        $this->grantSalesPermissions();
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sales', $this->apiPayload([
            'cash_payment' => false,
            'tempo_hari' => 0,
        ]))->assertCreated();

        $sale = DocSales::manual()->where('ulid', $response->json('data.sales.ulid'))->first();
        $this->assertTrue((bool) $sale->cash_payment);
        $this->assertSame(0, (int) $sale->tempo_hari);
    }

    public function test_calculate_rebuild_promos_returns_details(): void
    {
        $this->grantSalesPermissions();
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sales/calculate', $this->apiPayload([
            'rebuild_promos' => true,
            'tempo_hari' => 30,
            'cash_payment' => false,
        ]))->assertOk();

        $this->assertArrayHasKey('details', $response->json('data.calculation'));
        $this->assertArrayHasKey('totals', $response->json('data.calculation'));
        $this->assertCount(1, $response->json('data.calculation.details'));
    }

    public function test_store_rejects_cash_without_metode_when_already_cash(): void
    {
        $this->grantSalesPermissions();
        Sanctum::actingAs($this->user);

        // cash true tanpa cash_metode → validasi required_if
        $this->postJson('/api/v1/sales', $this->apiPayload([
            'cash_payment' => true,
            'tempo_hari' => 0,
            'cash_metode' => null,
        ]))->assertStatus(422);
    }

    public function test_store_rejects_duplicate_product_and_unit(): void
    {
        $this->grantSalesPermissions();
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/v1/sales', $this->apiPayload([
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'unit' => 'PCS',
                    'konversi' => 1,
                    'qty' => 1,
                    'harga_satuan' => 10000,
                ],
                [
                    'product_id' => $this->product->id,
                    'unit' => 'PCS',
                    'konversi' => 1,
                    'qty' => 1,
                    'harga_satuan' => 10000,
                ],
            ],
        ]))->assertStatus(422);

        $response->assertJsonValidationErrors('details');
    }

    public function test_update_rejects_duplicate_product_and_unit(): void
    {
        $this->grantSalesPermissions();
        Sanctum::actingAs($this->user);

        $sale = (new CreateManualSalesAction)->execute($this->data());

        $this->putJson("/api/v1/sales/{$sale->ulid}", $this->apiPayload([
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'unit' => 'PCS',
                    'konversi' => 1,
                    'qty' => 1,
                    'harga_satuan' => 10000,
                ],
                [
                    'product_id' => $this->product->id,
                    'unit' => 'PCS',
                    'konversi' => 1,
                    'qty' => 1,
                    'harga_satuan' => 10000,
                ],
            ],
        ]))->assertStatus(422)->assertJsonValidationErrors('details');
    }

    public function test_calculate_normalizes_cash_tempo_in_payload(): void
    {
        $this->grantSalesPermissions();
        Sanctum::actingAs($this->user);

        // Illegal combo in request: cash + tempo 30 → normalize before calc (still 200)
        $this->postJson('/api/v1/sales/calculate', $this->apiPayload([
            'cash_payment' => true,
            'tempo_hari' => 30,
            'cash_metode' => 'cash',
            'rebuild_promos' => false,
        ]))->assertOk();
    }

    private function grantSalesPermissions(): void
    {
        foreach (['sales.create', 'sales.update', 'sales.view'] as $name) {
            Permission::findOrCreate($name);
        }
        $this->user->givePermissionTo(['sales.create', 'sales.update', 'sales.view']);
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

    private function data(array $overrides = []): array
    {
        return array_merge([
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
    }

    private function stock(): float
    {
        return (float) InventoryStock::where([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
        ])->value('qty');
    }
}
