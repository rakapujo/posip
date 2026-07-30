<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ApproveManualSalesAction;
use App\Actions\Sales\CreateManualSalesAction;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Harga jual / total Sales BO tidak digate; HPP tetap stok.view_hpp.
 * sales.view_harga dihapus dari catalog.
 */
class SalesSellPriceUngatedTest extends TestCase
{
    use RefreshDatabase;

    private User $actor;

    private MasterCustomer $customer;

    private MasterWarehouse $warehouse;

    private MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        foreach (['sales.view', 'sales.create', 'sales.update', 'stok.view_hpp'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->actor = User::factory()->create();
        $this->actingAs($this->actor);

        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'BO-UG',
            'nama' => 'Customer Ungate',
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
            'harga_4' => 12500,
        ]);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 4000],
        );
        StockCard::$skipObserver = false;
    }

    public function test_index_show_products_expose_sell_money_without_view_harga(): void
    {
        $sale = (new CreateManualSalesAction)->execute($this->payload());
        $sale = (new ApproveManualSalesAction)->execute($sale);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['sales.view', 'sales.create', 'sales.update']);
        $this->actingAs($viewer);

        $index = $this->getJson('/api/v1/sales')->assertOk();
        $row = collect($index->json('data.items'))->firstWhere('ulid', $sale->ulid);
        $this->assertNotNull($row);
        $this->assertArrayHasKey('grand_total', $row);
        $this->assertGreaterThan(0, (float) $row['grand_total']);

        $show = $this->getJson('/api/v1/sales/'.$sale->ulid)->assertOk();
        $this->assertGreaterThan(0, (float) $show->json('data.sales.grand_total'));
        $detail = $show->json('data.sales.details.0');
        $this->assertArrayHasKey('harga_satuan', $detail);
        $this->assertArrayNotHasKey('hpp_at_time', $detail);

        $products = $this->getJson('/api/v1/sales/products?search='.$this->product->kode_produk)->assertOk();
        $item = collect($products->json('data.items'))->firstWhere('id', $this->product->id);
        $this->assertNotNull($item);
        $harga = collect($item['units'] ?? [])->pluck('harga_jual')->map(fn ($v) => (float) $v)->filter(fn ($v) => $v > 0);
        $this->assertNotEmpty($harga->all());
    }

    public function test_show_includes_hpp_at_time_with_stok_view_hpp(): void
    {
        $sale = (new CreateManualSalesAction)->execute($this->payload());
        $sale = (new ApproveManualSalesAction)->execute($sale);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['sales.view', 'stok.view_hpp']);
        $this->actingAs($viewer);

        $detail = $this->getJson('/api/v1/sales/'.$sale->ulid)
            ->assertOk()
            ->json('data.sales.details.0');
        $this->assertArrayHasKey('hpp_at_time', $detail);
    }

    private function payload(): array
    {
        return [
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'tempo_hari' => 30,
            'details' => [[
                'product_id' => $this->product->id,
                'unit' => 'PCS',
                'konversi' => 1,
                'qty' => 2,
                'harga_satuan' => 12500,
            ]],
        ];
    }
}
