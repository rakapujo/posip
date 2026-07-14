<?php

namespace Tests\Feature\Reports;

use App\Actions\Sales\CheckoutSalesAction;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\PosTerminalShift;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 4 — smoke test lintas modul analitik setelah satu transaksi POS.
 */
class LaporanAnalitikSmokeCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected string $from = '2026-01-01';

    protected string $to = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'laporan.keuangan', 'laporan.performa', 'laporan.promo',
            'laporan.inventory', 'stok.view_hpp',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo([
            'laporan.keuangan', 'laporan.performa', 'laporan.promo',
            'laporan.inventory', 'stok.view_hpp',
        ]);
        $this->actingAs($this->viewer);

        $warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-SMK',
            'nama' => 'Customer Smoke',
            'telepon' => '08123456789',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);
        $cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);
        $terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-SMK',
            'nama_terminal' => 'Kasir Smoke',
            'warehouse_id' => $warehouse->id,
            'default_customer_id' => $customer->id,
            'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $this->viewer->id,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);
        $shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminal->id,
            'user_id' => $this->viewer->id,
            'started_at' => '2026-06-15 08:00:00',
        ]);
        $product = MasterProduk::factory()->create([
            'kode_produk' => 'SMK_PRD',
            'avg_cost' => 4000,
            'harga_4' => 10000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $product->id, 'warehouse_id' => $warehouse->id],
            ['qty' => 100, 'avg_cost' => 4000]
        );
        StockCard::record([
            'product_id' => $product->id,
            'warehouse_id' => $warehouse->id,
            'transaction_type' => 'PURCHASE',
            'tanggal' => '2026-06-01',
            'qty_in' => 100,
            'qty_out' => 0,
            'cost_per_unit' => 4000,
        ]);
        StockCard::$skipObserver = false;

        (new CheckoutSalesAction())->execute([
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customer->id,
            'tanggal' => '2026-06-15 10:00:00',
            'items' => [[
                'product_id' => $product->id,
                'unit' => 'PCS',
                'konversi' => 1,
                'qty' => 2,
                'qty_base' => 2,
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
                'diskon_total' => 0,
                'jumlah' => 20000,
            ]],
            'payments' => [['metode_pembayaran_id' => $cash->id, 'nominal' => 20000]],
        ]);
    }

    private function q(string $path, array $extra = []): string
    {
        return $path.'?'.http_build_query(array_merge([
            'date_from' => $this->from,
            'date_to' => $this->to,
        ], $extra));
    }

    public function test_keuangan_reports_return_data_after_sale(): void
    {
        $summary = $this->getJson($this->q('/api/v1/reports/gross-profit/summary'))->assertOk()->json('data');
        $this->assertGreaterThan(0, $summary['revenue_net']);
        $this->assertGreaterThan(0, $summary['gross_profit']);

        $this->getJson($this->q('/api/v1/reports/gross-profit/daily'))->assertOk();
        $this->getJson($this->q('/api/v1/reports/cash-flow/summary'))->assertOk();
        $this->getJson('/api/v1/reports/margin-per-barang/summary')->assertOk();
    }

    public function test_performa_reports_return_structure_after_sale(): void
    {
        $kasir = $this->getJson($this->q('/api/v1/reports/kasir-performance'))->assertOk()->json('data');
        $this->assertNotEmpty($kasir['items']);

        $payment = $this->getJson($this->q('/api/v1/reports/payment-method/breakdown'))->assertOk()->json('data');
        $this->assertNotEmpty($payment['items']);

        $top = $this->getJson($this->q('/api/v1/reports/customer/top'))->assertOk()->json('data');
        $this->assertNotEmpty($top['items']);
    }

    public function test_promo_and_inventory_reports_respond_ok(): void
    {
        $this->getJson($this->q('/api/v1/reports/promo-usage/summary'))->assertOk();
        $this->getJson($this->q('/api/v1/reports/product-promo/by-product'))->assertOk();
        $this->getJson('/api/v1/reports/customer-promo/summary')->assertOk();
        $this->getJson($this->q('/api/v1/reports/retur/pattern'))->assertOk();
        $this->getJson('/api/v1/reports/inventory/dead-stock')->assertOk();
    }

    public function test_date_filter_excludes_sale_outside_range(): void
    {
        $url = '/api/v1/reports/gross-profit/summary?'.http_build_query([
            'date_from' => '2099-01-01',
            'date_to' => '2099-12-31',
        ]);

        $data = $this->getJson($url)->assertOk()->json('data');
        $this->assertEquals(0, $data['revenue_net']);
        $this->assertEquals(0, $data['gross_profit']);
    }
}
