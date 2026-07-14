<?php

namespace Tests\Feature\SalesReport;

use App\Actions\Sales\CheckoutSalesAction;
use App\Actions\Sales\ProcessSalesReturnAction;
use App\Exports\SalesPerNotaExport;
use App\Models\DocSales;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\PosTerminalShift;
use App\Models\StockCard;
use App\Models\User;
use App\Services\ReportHelperService;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 2 — laporan penjualan: permission, filter, agregasi, export.
 * Pola mirror PurchaseReportSerialTest (BPA-7).
 */
class SalesReportCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected MasterWarehouse $warehouse;

    protected MasterPosTerminal $terminal;

    protected PosTerminalShift $shift;

    protected MasterCustomer $customer;

    protected MasterMetodePembayaran $cash;

    protected MasterProduk $product;

    protected CheckoutSalesAction $checkout;

    protected string $from = '2026-01-01';

    protected string $to = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['laporan.penjualan', 'laporan.export', 'stok.view_hpp'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo(['laporan.penjualan', 'laporan.export', 'stok.view_hpp']);
        $this->actingAs($this->viewer);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-RPT',
            'nama' => 'Customer Laporan',
            'telepon' => '08123456789',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $this->cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $this->terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-RPT',
            'nama_terminal' => 'Kasir Laporan',
            'warehouse_id' => $this->warehouse->id,
            'default_customer_id' => $this->customer->id,
            'default_metode_pembayaran_id' => $this->cash->id,
            'active_user_id' => $this->viewer->id,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $this->shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->viewer->id,
            'started_at' => now(),
        ]);

        $this->product = MasterProduk::factory()->create([
            'kode_produk' => 'RPT_PRD',
            'nama_produk' => 'Produk Laporan',
            'avg_cost' => 5000,
            'harga_4' => 10000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 200, 'avg_cost' => 5000]
        );
        StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE',
            'tanggal' => now(),
            'qty_in' => 200,
            'qty_out' => 0,
            'cost_per_unit' => 5000,
        ]);
        StockCard::$skipObserver = false;

        $this->checkout = new CheckoutSalesAction();
    }

    private function q(string $path, array $extra = []): string
    {
        return $path.'?'.http_build_query(array_merge([
            'date_from' => $this->from,
            'date_to' => $this->to,
        ], $extra));
    }

    /** @param  array<string, mixed>  $overrides */
    private function retailItem(array $overrides = []): array
    {
        return array_merge([
            'product_id' => $this->product->id,
            'unit' => 'PCS',
            'konversi' => 1,
            'qty' => 1,
            'qty_base' => 1,
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
            'jumlah' => 10000,
        ], $overrides);
    }

    /** @param  array<string, mixed>  $overrides */
    private function checkoutSale(array $overrides = []): DocSales
    {
        $items = $overrides['items'] ?? [$this->retailItem()];
        unset($overrides['items']);

        $nominal = $overrides['payment_nominal'] ?? 10000;
        unset($overrides['payment_nominal']);

        return $this->checkout->execute(array_merge([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'items' => $items,
            'payments' => [['metode_pembayaran_id' => $this->cash->id, 'nominal' => $nominal]],
        ], $overrides));
    }

    public function test_per_nota_lists_completed_sale_and_show_detail(): void
    {
        $sale = $this->checkoutSale();

        $index = $this->getJson($this->q('/api/v1/sales-report'))->assertOk();
        $nomors = collect($index->json('data.items'))->pluck('nomor_dokumen');
        $this->assertTrue($nomors->contains($sale->nomor_dokumen));

        $this->getJson("/api/v1/sales-report/{$sale->ulid}")
            ->assertOk()
            ->assertJsonPath('data.sales.nomor_dokumen', $sale->nomor_dokumen)
            ->assertJsonPath('data.sales.receipt_status', 'completed');
    }

    /**
     * Shared receipt-qty SQL (ReportHelperService) drives status filter + receipt_status
     * for API index and SalesPerNotaExport — regression after DRY extraction.
     */
    public function test_per_nota_receipt_status_filters_partial_and_full_return(): void
    {
        $salePartial = $this->checkoutSale([
            'items' => [$this->retailItem(['qty' => 4, 'qty_base' => 4, 'jumlah' => 40000])],
            'payment_nominal' => 40000,
        ]);
        $saleFull = $this->checkoutSale([
            'items' => [$this->retailItem(['qty' => 2, 'qty_base' => 2, 'jumlah' => 20000])],
            'payment_nominal' => 20000,
        ]);
        $saleClean = $this->checkoutSale();

        $returnAction = new ProcessSalesReturnAction();
        $returnAction->execute([
            'sales_id' => $salePartial->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'refund_method' => 'cash',
            'items' => [[
                'sales_detail_id' => $salePartial->details->first()->id,
                'product_id' => $this->product->id,
                'qty' => 1,
                'harga_per_base' => 10000,
            ]],
        ]);
        $returnAction->execute([
            'sales_id' => $saleFull->id,
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'refund_method' => 'cash',
            'items' => [[
                'sales_detail_id' => $saleFull->details->first()->id,
                'product_id' => $this->product->id,
                'qty' => 2,
                'harga_per_base' => 10000,
            ]],
        ]);

        $byStatus = fn (string $status) => collect(
            $this->getJson($this->q('/api/v1/sales-report', ['status' => $status]))->assertOk()->json('data.items')
        )->pluck('nomor_dokumen');

        $completed = $byStatus('completed');
        $this->assertTrue($completed->contains($saleClean->nomor_dokumen));
        $this->assertFalse($completed->contains($salePartial->nomor_dokumen));
        $this->assertFalse($completed->contains($saleFull->nomor_dokumen));

        $partial = $byStatus('retur_partial');
        $this->assertTrue($partial->contains($salePartial->nomor_dokumen));
        $this->assertFalse($partial->contains($saleClean->nomor_dokumen));
        $this->assertFalse($partial->contains($saleFull->nomor_dokumen));

        $full = $byStatus('retur_full');
        $this->assertTrue($full->contains($saleFull->nomor_dokumen));
        $this->assertFalse($full->contains($saleClean->nomor_dokumen));
        $this->assertFalse($full->contains($salePartial->nomor_dokumen));

        $all = collect($this->getJson($this->q('/api/v1/sales-report'))->assertOk()->json('data.items'))
            ->keyBy('nomor_dokumen');
        $this->assertSame('completed', $all[$saleClean->nomor_dokumen]['receipt_status'] ?? null);
        $this->assertSame('retur_partial', $all[$salePartial->nomor_dokumen]['receipt_status'] ?? null);
        $this->assertSame('retur_full', $all[$saleFull->nomor_dokumen]['receipt_status'] ?? null);

        // Export query must use the same helper fragment (status filter retained after DRY).
        $this->assertStringContainsString(
            'doc_sales_return_detail',
            ReportHelperService::sqlSalesReturnedBase('ds.id')
        );
        $exportPartial = (new SalesPerNotaExport(
            $this->from,
            $this->to,
            null,
            null,
            null,
            'retur_partial'
        ))->query()->get();
        $exportNoms = $exportPartial->pluck('nomor_dokumen');
        $this->assertTrue($exportNoms->contains($salePartial->nomor_dokumen));
        $this->assertFalse($exportNoms->contains($saleClean->nomor_dokumen));
        $this->assertFalse($exportNoms->contains($saleFull->nomor_dokumen));
    }

    public function test_per_nota_forbidden_without_laporan_penjualan(): void
    {
        $this->checkoutSale();

        $outsider = User::factory()->create();
        $this->actingAs($outsider)
            ->getJson($this->q('/api/v1/sales-report'))
            ->assertForbidden();
    }

    public function test_per_nota_export_requires_laporan_export(): void
    {
        $this->checkoutSale();

        Permission::firstOrCreate(['name' => 'laporan.penjualan', 'guard_name' => 'web']);
        $noExport = User::factory()->create();
        $noExport->givePermissionTo('laporan.penjualan');

        $this->actingAs($noExport)
            ->get($this->q('/api/v1/sales-report/export'))
            ->assertForbidden();

        $this->actingAs($this->viewer)
            ->get($this->q('/api/v1/sales-report/export'))
            ->assertOk();
    }

    public function test_per_nota_empty_when_date_range_excludes_sale(): void
    {
        $this->checkoutSale();

        $res = $this->getJson('/api/v1/sales-report?date_from=2020-01-01&date_to=2020-12-31')->assertOk();
        $this->assertSame(0, (int) $res->json('data.pagination.total'));
    }

    public function test_per_barang_aggregates_qty_and_hpp_gated_by_permission(): void
    {
        $this->checkoutSale(['items' => [$this->retailItem(['qty' => 3, 'qty_base' => 3, 'jumlah' => 30000])], 'payment_nominal' => 30000]);

        $res = $this->getJson($this->q('/api/v1/sales-product-report'))->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('kode_produk', 'RPT_PRD');
        $this->assertNotNull($row);
        $this->assertEquals(3, (float) $row['qty_terjual']);
        $this->assertEquals(30000, (float) $row['pendapatan']);
        $this->assertTrue($res->json('data.can_view_hpp'));
        $this->assertArrayHasKey('hpp_total', $row);

        Permission::firstOrCreate(['name' => 'laporan.penjualan', 'guard_name' => 'web']);
        $noHpp = User::factory()->create();
        $noHpp->givePermissionTo('laporan.penjualan');

        $hidden = $this->actingAs($noHpp)
            ->getJson($this->q('/api/v1/sales-product-report'))
            ->assertOk();

        $this->assertFalse($hidden->json('data.can_view_hpp'));
        $hiddenRow = collect($hidden->json('data.items'))->firstWhere('kode_produk', 'RPT_PRD');
        $this->assertNotNull($hiddenRow);
        $this->assertArrayNotHasKey('hpp_total', $hiddenRow);
        $this->assertArrayNotHasKey('total_hpp', $hidden->json('data.summary'));
    }

    public function test_financial_disc_line_and_detail(): void
    {
        $sale = $this->checkoutSale([
            'items' => [$this->retailItem([
                'diskon_5_tipe' => 'percent',
                'diskon_5_nilai' => 10,
                'diskon_total' => 1000,
                'jumlah' => 9000,
            ])],
            'payment_nominal' => 9000,
        ]);

        $res = $this->getJson($this->q('/api/v1/sales-financial-report/disc-line'))->assertOk();
        $this->assertTrue(
            collect($res->json('data.items'))->pluck('nomor_dokumen')->contains($sale->nomor_dokumen)
        );
        $this->assertEquals(1000, (float) $res->json('data.summary.total_disc_line'));

        $this->getJson("/api/v1/sales-financial-report/disc-line/{$sale->ulid}")
            ->assertOk()
            ->assertJsonPath('data.summary.total_disc', 1000);
    }

    public function test_financial_disc_nota_includes_nota_discount(): void
    {
        $sale = $this->checkoutSale([
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'percent', 'nilai' => 10],
            ],
            'payment_nominal' => 9000,
        ]);

        $res = $this->getJson($this->q('/api/v1/sales-financial-report/disc-nota'))->assertOk();
        $this->assertTrue(
            collect($res->json('data.items'))->pluck('nomor_dokumen')->contains($sale->nomor_dokumen)
        );
        $this->assertEquals(1000, (float) $res->json('data.summary.total_diskon'));
    }

    public function test_financial_biaya_includes_biaya_kirim(): void
    {
        $sale = $this->checkoutSale([
            'biaya_kirim' => ['tipe' => 'nominal', 'nilai' => 5000],
            'biaya_lain' => ['tipe' => 'none', 'nilai' => 0],
            'payment_nominal' => 15000,
        ]);

        $res = $this->getJson($this->q('/api/v1/sales-financial-report/biaya'))->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('nomor_dokumen', $sale->nomor_dokumen);
        $this->assertNotNull($row);
        $this->assertEquals(5000, (float) $row['biaya_kirim_hasil']);
        $this->assertEquals(5000, (float) $res->json('data.summary.total_biaya_kirim'));
    }

    public function test_financial_pembulatan_with_rounding_enabled(): void
    {
        SettingService::set('rounding.sales_method', 'round', 'string');
        SettingService::set('rounding.sales_precision', 100, 'integer');

        $sale = $this->checkoutSale([
            'items' => [$this->retailItem([
                'qty' => 10,
                'qty_base' => 10,
                'harga_satuan' => 9999,
                'jumlah' => 99990,
            ])],
            'payment_nominal' => 100000,
        ]);

        $this->assertNotEquals(0, (float) $sale->pembulatan);

        $res = $this->getJson($this->q('/api/v1/sales-financial-report/pembulatan'))->assertOk();
        $this->assertTrue(
            collect($res->json('data.items'))->pluck('nomor_dokumen')->contains($sale->nomor_dokumen)
        );
    }

    public function test_all_sales_report_exports_run_without_error(): void
    {
        $this->checkoutSale([
            'items' => [$this->retailItem([
                'diskon_5_tipe' => 'percent',
                'diskon_5_nilai' => 10,
                'diskon_total' => 1000,
                'jumlah' => 9000,
            ])],
            'discounts' => [
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'none', 'nilai' => 0],
                ['tipe' => 'percent', 'nilai' => 5],
            ],
            'biaya_kirim' => ['tipe' => 'nominal', 'nilai' => 2000],
            'biaya_lain' => ['tipe' => 'none', 'nilai' => 0],
            'payment_nominal' => 20000,
        ]);

        $this->get($this->q('/api/v1/sales-report/export'))->assertOk();
        $this->get($this->q('/api/v1/sales-product-report/export'))->assertOk();

        foreach (['pembulatan', 'disc-line', 'disc-nota', 'biaya'] as $type) {
            $this->get($this->q("/api/v1/sales-financial-report/{$type}/export"))->assertOk();
        }
    }

    public function test_dropdowns_include_terminal_after_sale(): void
    {
        $this->checkoutSale();

        $res = $this->getJson('/api/v1/sales-report/dropdowns')->assertOk();
        $this->assertTrue(
            collect($res->json('data.terminals'))->pluck('kode_terminal')->contains('TRM-RPT')
        );
    }
}
