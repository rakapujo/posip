<?php

namespace Tests\Feature\Reports;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class LaporanAnalitikExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $exporter;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'laporan.export', 'laporan.keuangan', 'laporan.performa', 'laporan.promo',
            'laporan.inventory', 'stok.view_hpp',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->exporter = User::factory()->create();
        $this->exporter->givePermissionTo([
            'laporan.export', 'laporan.keuangan', 'laporan.performa', 'laporan.promo',
            'laporan.inventory', 'stok.view_hpp',
        ]);
    }

    private function q(string $path): string
    {
        return $path.'?date_from=2026-01-01&date_to=2026-12-31';
    }

    public function test_gross_profit_daily_export_requires_laporan_export(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.keuangan', 'stok.view_hpp']);

        $this->actingAs($viewer)
            ->get($this->q('/api/v1/reports/gross-profit/daily/export'))
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get($this->q('/api/v1/reports/gross-profit/daily/export'))
            ->assertOk();
    }

    public function test_margin_per_barang_export_requires_stok_view_hpp(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.export', 'laporan.keuangan']);

        $this->actingAs($viewer)
            ->get('/api/v1/reports/margin-per-barang/export')
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get('/api/v1/reports/margin-per-barang/export')
            ->assertOk();
    }

    public function test_kasir_performance_export_requires_laporan_performa(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.export']);

        $this->actingAs($viewer)
            ->get($this->q('/api/v1/reports/kasir-performance/export'))
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get($this->q('/api/v1/reports/kasir-performance/export'))
            ->assertOk();
    }

    public function test_cash_flow_daily_export_requires_laporan_keuangan(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.export']);

        $this->actingAs($viewer)
            ->get($this->q('/api/v1/reports/cash-flow/daily/export'))
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get($this->q('/api/v1/reports/cash-flow/daily/export'))
            ->assertOk();
    }

    public function test_dead_stock_export_requires_laporan_inventory(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.export']);

        $this->actingAs($viewer)
            ->get('/api/v1/reports/inventory/dead-stock/export')
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get('/api/v1/reports/inventory/dead-stock/export')
            ->assertOk();
    }

    public function test_promo_usage_export_requires_laporan_promo(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.export']);

        $this->actingAs($viewer)
            ->get($this->q('/api/v1/reports/promo-usage/export'))
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get($this->q('/api/v1/reports/promo-usage/export'))
            ->assertOk();
    }

    public function test_product_promo_export_requires_laporan_promo(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.export']);

        $this->actingAs($viewer)
            ->get('/api/v1/reports/product-promo/by-promo/export')
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get('/api/v1/reports/product-promo/by-promo/export')
            ->assertOk();
    }

    public function test_customer_promo_export_requires_laporan_promo(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.export']);

        $this->actingAs($viewer)
            ->get('/api/v1/reports/customer-promo/by-customer/export')
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get('/api/v1/reports/customer-promo/by-customer/export')
            ->assertOk();
    }

    public function test_all_analytic_exports_run_without_error(): void
    {
        $this->actingAs($this->exporter);

        $this->get($this->q('/api/v1/reports/gross-profit/daily/export'))->assertOk();
        $this->get($this->q('/api/v1/reports/gross-profit/by-kategori/export'))->assertOk();
        $this->get($this->q('/api/v1/reports/gross-profit/top-products/export'))->assertOk();
        $this->get('/api/v1/reports/margin-per-barang/export')->assertOk();
        $this->get($this->q('/api/v1/reports/kasir-performance/export'))->assertOk();
        $this->get($this->q('/api/v1/reports/cash-flow/daily/export'))->assertOk();
        $this->get('/api/v1/reports/inventory/dead-stock/export')->assertOk();
        $this->get($this->q('/api/v1/reports/promo-usage/export'))->assertOk();
        $this->get('/api/v1/reports/product-promo/by-promo/export')->assertOk();
        $this->get('/api/v1/reports/product-promo/by-product/export')->assertOk();
        $this->get('/api/v1/reports/customer-promo/summary/export')->assertOk();
        $this->get('/api/v1/reports/customer-promo/by-tipe/export')->assertOk();
        $this->get('/api/v1/reports/customer-promo/by-kategori/export')->assertOk();
        $this->get('/api/v1/reports/customer-promo/by-customer/export')->assertOk();
        $this->get($this->q('/api/v1/reports/payment-method/breakdown/export'))->assertOk();
        $this->get($this->q('/api/v1/reports/customer/top/export'))->assertOk();
        $this->get($this->q('/api/v1/reports/retur/pattern/export'))->assertOk();
    }

    public function test_top_customer_export_requires_laporan_performa(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.export']);

        $this->actingAs($viewer)
            ->get($this->q('/api/v1/reports/customer/top/export'))
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get($this->q('/api/v1/reports/customer/top/export'))
            ->assertOk();
    }

    public function test_gross_profit_by_kategori_export_requires_stok_view_hpp(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.export', 'laporan.keuangan']);

        $this->actingAs($viewer)
            ->get($this->q('/api/v1/reports/gross-profit/by-kategori/export'))
            ->assertForbidden();

        $this->actingAs($this->exporter)
            ->get($this->q('/api/v1/reports/gross-profit/by-kategori/export'))
            ->assertOk();
    }
}
