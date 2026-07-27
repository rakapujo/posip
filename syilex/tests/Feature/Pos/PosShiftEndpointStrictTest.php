<?php

namespace Tests\Feature\Pos;

use App\Actions\Sales\CheckoutSalesAction;
use App\Models\DocSales;
use App\Models\DocSalesPayment;
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
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PosShiftEndpointStrictTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $intruder;

    protected MasterPosTerminal $terminal;

    protected PosTerminalShift $shift;

    protected MasterMetodePembayaran $cash;

    protected MasterWarehouse $warehouse;

    protected MasterCustomer $customer;

    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        foreach (['pos.access', 'pos.void', 'terminal.view', 'terminal.force-release', 'settings.view'] as $perm) {
            Permission::findOrCreate($perm, 'web');
        }

        $this->owner = User::factory()->create();
        $this->intruder = User::factory()->create();
        $this->intruder->givePermissionTo('pos.access');

        $this->owner->givePermissionTo(['pos.access', 'pos.void']);
        $this->actingAs($this->owner);

        $this->warehouse = MasterWarehouse::factory()->create([
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $this->cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'POS-STRICT',
            'nama' => 'Walk In',
            'telepon' => '08123456789',
            'jenis' => 'walk_in',
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $this->terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-STRICT',
            'nama_terminal' => 'Kasir Strict',
            'warehouse_id' => $this->warehouse->id,
            'default_metode_pembayaran_id' => $this->cash->id,
            'active_user_id' => $this->owner->id,
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);
        $this->terminal->allowedPaymentMethods()->attach([$this->cash->id]);

        $this->shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->owner->id,
            'started_at' => now(),
        ]);

        $this->product = MasterProduk::factory()->create([
            'status' => 'active',
            'avg_cost' => 5000,
            'harga_4' => 10000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
        ]);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 100, 'avg_cost' => 5000],
        );
        StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE',
            'tanggal' => now(),
            'qty_in' => 100,
            'qty_out' => 0,
            'cost_per_unit' => 5000,
        ]);
        StockCard::$skipObserver = false;
    }

    public function test_cash_index_as_other_user_with_pos_access_on_victim_shift_returns_403(): void
    {
        Sanctum::actingAs($this->intruder);

        $this->getJson('/api/v1/pos/cash?shift_id='.$this->shift->id)
            ->assertForbidden();
    }

    public function test_cash_store_as_other_user_on_victim_shift_returns_403(): void
    {
        Sanctum::actingAs($this->intruder);

        $this->postJson('/api/v1/pos/cash', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'tipe' => 'kas_masuk',
            'nominal' => 50_000,
        ])->assertForbidden();
    }

    public function test_cash_store_as_owner_returns_201(): void
    {
        Sanctum::actingAs($this->owner);

        $response = $this->postJson('/api/v1/pos/cash', [
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'tipe' => 'kas_masuk',
            'nominal' => 25_000,
        ])->assertCreated();

        $this->assertEquals(25000.0, (float) $response->json('data.transaction.nominal'));
    }

    public function test_void_completed_pos_sale_after_shift_ended_returns_422(): void
    {
        $sale = $this->completedPosSale();
        $this->shift->update(['ended_at' => now()]);
        $sale->unsetRelation('shift');

        Sanctum::actingAs($this->owner);

        $this->postJson("/api/v1/pos/sales/{$sale->ulid}/void", [
            'reason' => 'Salah input',
        ])->assertStatus(422);
    }

    public function test_shift_report_closed_shift_ok_with_terminal_view_only(): void
    {
        $this->shift->update(['ended_at' => now()]);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('terminal.view');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/pos/shift-report/'.$this->shift->ulid)
            ->assertOk()
            ->assertJsonStructure(['data' => ['shift']]);
    }

    public function test_shift_report_open_shift_other_viewer_forbidden_owner_ok(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('terminal.view');
        Sanctum::actingAs($viewer);

        $this->getJson('/api/v1/pos/shift-report/'.$this->shift->ulid)
            ->assertForbidden();

        Sanctum::actingAs($this->owner);
        $this->getJson('/api/v1/pos/shift-report/'.$this->shift->ulid)
            ->assertOk();
    }

    public function test_shifts_index_kasir_sees_own_only_admin_sees_all(): void
    {
        $otherOwner = User::factory()->create();
        $otherOwner->givePermissionTo('pos.access');

        PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminal->id,
            'user_id' => $otherOwner->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);

        $kasir = User::factory()->create();
        $kasir->givePermissionTo('terminal.view');
        Sanctum::actingAs($kasir);

        $this->getJson('/api/v1/shifts')
            ->assertOk()
            ->assertJsonCount(0, 'data.shifts');

        Sanctum::actingAs($this->owner);
        $this->owner->givePermissionTo('terminal.view');

        $this->getJson('/api/v1/shifts')
            ->assertOk()
            ->assertJsonCount(1, 'data.shifts');

        $admin = User::factory()->create();
        $admin->givePermissionTo(['terminal.view', 'terminal.force-release']);
        Sanctum::actingAs($admin);

        $this->getJson('/api/v1/shifts')
            ->assertOk()
            ->assertJsonCount(2, 'data.shifts');
    }

    private function completedPosSale(): DocSales
    {
        return (new CheckoutSalesAction)->execute([
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'items' => [[
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
            ]],
            'payments' => [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 10000]],
        ]);
    }
}
