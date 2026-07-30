<?php

namespace Tests\Feature\Sales;

use App\Actions\Sales\ApproveManualSalesAction;
use App\Actions\Sales\CreateManualSalesAction;
use App\Actions\SalesReturn\CreateSalesReturnAction;
use App\Actions\SalesReturn\LockSalesReturnAction;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\Setting;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class SalesReturnFreeModeSettingTest extends TestCase
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
            'kode_customer' => 'FREE-RET',
            'nama' => 'Customer Free',
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

    private function setSalesFree(bool $on): void
    {
        Setting::updateOrCreate(
            ['group' => 'returns', 'key' => 'sales_allow_free'],
            ['value' => $on ? 'true' : 'false', 'type' => 'boolean']
        );
        SettingService::clearCache();
    }

    private function seedSold(): void
    {
        (new ApproveManualSalesAction)->execute(
            (new CreateManualSalesAction)->execute([
                'tanggal' => '2026-07-20',
                'customer_id' => $this->customer->id,
                'warehouse_id' => $this->warehouse->id,
                'tempo_hari' => 30,
                'details' => [[
                    'product_id' => $this->product->id,
                    'unit' => 'PCS',
                    'konversi' => 1,
                    'qty' => 3,
                    'harga_satuan' => 10000,
                ]],
            ]),
        );
    }

    public function test_free_create_ok_when_allow_free_true(): void
    {
        $this->setSalesFree(true);
        $this->seedSold();
        $return = (new CreateSalesReturnAction)->execute([
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [[
                'product_id' => $this->product->id,
                'qty_base' => 1,
                'harga_satuan' => 10000,
                'unit' => 'PCS',
            ]],
        ]);
        $this->assertNull($return->sales_id);
        $this->assertSame('draft', $return->status);
    }

    public function test_free_create_rejected_when_allow_free_false(): void
    {
        $this->setSalesFree(false);
        $this->seedSold();
        $this->expectException(ValidationException::class);
        (new CreateSalesReturnAction)->execute([
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [[
                'product_id' => $this->product->id,
                'qty_base' => 1,
                'harga_satuan' => 10000,
                'unit' => 'PCS',
            ]],
        ]);
    }

    public function test_preexisting_free_draft_can_lock_when_setting_later_disabled(): void
    {
        $this->setSalesFree(true);
        $this->seedSold();
        $return = (new CreateSalesReturnAction)->execute([
            'tanggal' => '2026-07-20',
            'customer_id' => $this->customer->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [[
                'product_id' => $this->product->id,
                'qty_base' => 1,
                'harga_satuan' => 10000,
                'unit' => 'PCS',
            ]],
        ]);
        $this->setSalesFree(false);
        $locked = (new LockSalesReturnAction)->execute($return);
        $this->assertSame('lock', $locked->status);
    }

    public function test_linked_create_still_ok_when_allow_free_false(): void
    {
        $this->setSalesFree(false);
        $sale = (new ApproveManualSalesAction)->execute(
            (new CreateManualSalesAction)->execute([
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
            ]),
        );
        $detail = $sale->details->first();
        $return = (new CreateSalesReturnAction)->execute([
            'tanggal' => '2026-07-20',
            'sales_id' => $sale->id,
            'details' => [[
                'sales_detail_id' => $detail->id,
                'product_id' => $this->product->id,
                'qty_base' => 1,
            ]],
        ]);
        $this->assertSame($sale->id, $return->sales_id);
    }
}
