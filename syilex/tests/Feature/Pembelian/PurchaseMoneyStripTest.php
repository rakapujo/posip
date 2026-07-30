<?php

namespace Tests\Feature\Pembelian;

use App\Actions\PembayaranHutang\CreatePembayaranHutangAction;
use App\Actions\PurchaseOrder\ApprovePurchaseOrderAction;
use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Actions\PurchaseReturn\CreatePurchaseReturnAction;
use App\Actions\PurchaseReturn\LockPurchaseReturnAction;
use App\Models\DocPembayaranHutang;
use App\Models\DocPurchaseReturn;
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
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 3 — Regresi "strip uang": pastikan field nilai/nominal sensitif (retur
 * pembelian, pembayaran hutang, deposit supplier) disembunyikan via makeHidden
 * tanpa permission value-gate (po.view_harga / hutang.view_nominal), dan tetap
 * tampil saat permission diberikan. Lihat AI-AGENT.md §"Nilai sensitif".
 *
 * Pola diambil dari SupplierHutangController (aging-summary masking) dan
 * HutangAgingTest/SupplierDepositCrudTest yang sudah menguji show; test ini
 * menambah index masking (belum ada) + pembayaran hutang index/show (belum ada).
 */
class PurchaseMoneyStripTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected MasterWarehouse $warehouse;

    protected MasterSupplier $supplier;

    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        foreach ([
            'retur-beli.view', 'retur-beli.create', 'retur-beli.lock',
            'po.view_harga', 'po.view', 'po.create', 'po.approve',
            'pembayaran-hutang.view', 'pembayaran-hutang.create', 'pembayaran-hutang.complete',
            'hutang.view_nominal',
            'deposit-supplier.view', 'deposit-supplier.create',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->owner = User::factory()->create();
        $this->owner->givePermissionTo(Permission::all());
        $this->actingAs($this->owner);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->supplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-STRIP',
            'nama_supplier' => 'Supplier Strip',
            'nama_pic' => 'PIC',
            'telepon' => '08000',
            'tempo_default' => 14,
            'status' => 'active',
            'created_by' => $this->owner->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'avg_cost' => 8000,
            'status' => 'active',
        ]);

        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 50, 'avg_cost' => 8000]
        );
        StockCard::record([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => '2026-04-01',
            'qty_in' => 50, 'qty_out' => 0, 'cost_per_unit' => 8000,
        ]);
        StockCard::$skipObserver = false;
    }

    private function userWith(array $perms): User
    {
        $user = User::factory()->create();
        $user->givePermissionTo($perms);

        return $user;
    }

    // ==================== A1/A2: Purchase Return ====================

    private function makeLockedPurchaseReturn(): DocPurchaseReturn
    {
        $retur = (new CreatePurchaseReturnAction())->execute([
            'tanggal' => '2026-04-12',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'notes' => 'Barang rusak',
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => 5,
                'harga_per_unit' => 8000,
            ]],
        ]);

        return (new LockPurchaseReturnAction())->execute($retur->fresh());
    }
    #[Test]
    public function purchase_return_index_hides_nilai_without_view_harga(): void
    {
        $this->makeLockedPurchaseReturn();

        $viewer = $this->userWith(['retur-beli.view']);
        $item = $this->actingAs($viewer)
            ->getJson('/api/v1/purchase-returns')
            ->assertOk()
            ->json('data.items.0');

        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('nilai_kalkulasi', $item);
        $this->assertArrayNotHasKey('nilai_diakui', $item);
        $this->assertArrayNotHasKey('selisih', $item);
    }
    #[Test]
    public function purchase_return_index_shows_nilai_with_view_harga(): void
    {
        $this->makeLockedPurchaseReturn();

        $viewer = $this->userWith(['retur-beli.view', 'po.view_harga']);
        $item = $this->actingAs($viewer)
            ->getJson('/api/v1/purchase-returns')
            ->assertOk()
            ->json('data.items.0');

        $this->assertArrayHasKey('nilai_kalkulasi', $item);
        $this->assertEquals(40000, (float) $item['nilai_kalkulasi'], '5 x 8000');
    }
    #[Test]
    public function purchase_return_show_hides_header_and_detail_harga_without_view_harga(): void
    {
        $retur = $this->makeLockedPurchaseReturn();

        $viewer = $this->userWith(['retur-beli.view']);
        $data = $this->actingAs($viewer)
            ->getJson("/api/v1/purchase-returns/{$retur->ulid}")
            ->assertOk()
            ->json('data.purchase_return');

        $this->assertArrayNotHasKey('nilai_kalkulasi', $data);
        $this->assertArrayNotHasKey('nilai_diakui', $data);
        $this->assertArrayNotHasKey('selisih', $data);

        $detail = $data['details'][0];
        $this->assertArrayNotHasKey('harga_per_unit', $detail);
        $this->assertArrayNotHasKey('harga_per_base', $detail);
        $this->assertArrayNotHasKey('subtotal', $detail);
    }
    #[Test]
    public function purchase_return_show_includes_header_and_detail_harga_with_view_harga(): void
    {
        $retur = $this->makeLockedPurchaseReturn();

        $viewer = $this->userWith(['retur-beli.view', 'po.view_harga']);
        $data = $this->actingAs($viewer)
            ->getJson("/api/v1/purchase-returns/{$retur->ulid}")
            ->assertOk()
            ->json('data.purchase_return');

        $this->assertArrayHasKey('nilai_kalkulasi', $data);
        $this->assertEquals(40000, (float) $data['nilai_kalkulasi']);

        $detail = $data['details'][0];
        $this->assertArrayHasKey('harga_per_unit', $detail);
        $this->assertEquals(8000, (float) $detail['harga_per_unit']);
    }

    // ==================== A3: Pembayaran Hutang ====================

    /**
     * @return array{0: DocPembayaranHutang, 1: SupplierHutang}
     */
    private function makeHutangAndPayment(float $bayar = 40000): array
    {
        $po = (new CreatePurchaseOrderAction())->execute([
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
        (new ApprovePurchaseOrderAction())->execute($po);
        $hutang = SupplierHutang::where('po_id', $po->id)->first();

        $payment = (new CreatePembayaranHutangAction())->execute([
            'tanggal' => '2026-04-15',
            'supplier_id' => $this->supplier->id,
            'metode_pembayaran' => 'cash',
            'details' => [[
                'hutang_id' => $hutang->id,
                'nominal_dibayar' => $bayar,
                'sumber' => 'cash',
            ]],
        ]);

        return [$payment, $hutang];
    }
    #[Test]
    public function pembayaran_hutang_index_hides_total_without_view_nominal(): void
    {
        $this->makeHutangAndPayment();

        $viewer = $this->userWith(['pembayaran-hutang.view']);
        $item = $this->actingAs($viewer)
            ->getJson('/api/v1/pembayaran-hutangs')
            ->assertOk()
            ->json('data.items.0');

        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('total_pembayaran', $item);
        $this->assertArrayNotHasKey('total_bayar_cash', $item);
        $this->assertArrayNotHasKey('total_bayar_deposit', $item);
    }
    #[Test]
    public function pembayaran_hutang_index_shows_total_with_view_nominal(): void
    {
        $this->makeHutangAndPayment(40000);

        $viewer = $this->userWith(['pembayaran-hutang.view', 'hutang.view_nominal']);
        $item = $this->actingAs($viewer)
            ->getJson('/api/v1/pembayaran-hutangs')
            ->assertOk()
            ->json('data.items.0');

        $this->assertArrayHasKey('total_pembayaran', $item);
        $this->assertEquals(40000, (float) $item['total_pembayaran']);
    }
    #[Test]
    public function pembayaran_hutang_show_hides_total_and_detail_nominal_without_view_nominal(): void
    {
        [$payment] = $this->makeHutangAndPayment(40000);

        $viewer = $this->userWith(['pembayaran-hutang.view']);
        $data = $this->actingAs($viewer)
            ->getJson("/api/v1/pembayaran-hutangs/{$payment->ulid}")
            ->assertOk()
            ->json('data.pembayaran');

        $this->assertArrayNotHasKey('total_pembayaran', $data);
        $this->assertArrayNotHasKey('total_bayar_cash', $data);

        $detail = $data['details'][0];
        $this->assertArrayNotHasKey('nominal_dibayar', $detail);
        $this->assertArrayNotHasKey('nominal_awal', $detail['hutang']);
        $this->assertArrayNotHasKey('sisa_hutang', $detail['hutang']);
    }
    #[Test]
    public function pembayaran_hutang_show_includes_total_and_detail_nominal_with_view_nominal(): void
    {
        [$payment] = $this->makeHutangAndPayment(40000);

        $viewer = $this->userWith(['pembayaran-hutang.view', 'hutang.view_nominal']);
        $data = $this->actingAs($viewer)
            ->getJson("/api/v1/pembayaran-hutangs/{$payment->ulid}")
            ->assertOk()
            ->json('data.pembayaran');

        $this->assertArrayHasKey('total_pembayaran', $data);
        $this->assertEquals(40000, (float) $data['total_pembayaran']);

        $detail = $data['details'][0];
        $this->assertArrayHasKey('nominal_dibayar', $detail);
        $this->assertEquals(40000, (float) $detail['nominal_dibayar']);
    }

    // ==================== A4: Supplier Deposit ====================

    private function makeManualDeposit(float $nominal = 500000): SupplierDeposit
    {
        return SupplierDeposit::create([
            'supplier_id' => $this->supplier->id,
            'retur_id' => null,
            'tanggal' => now()->toDateString(),
            'nominal_awal' => $nominal,
            'nominal_terpakai' => 0,
            'sisa_deposit' => $nominal,
            'status' => 'available',
            'created_by' => $this->owner->id,
        ]);
    }
    #[Test]
    public function supplier_deposit_index_hides_nominal_without_view_nominal(): void
    {
        $this->makeManualDeposit(500000);

        $viewer = $this->userWith(['deposit-supplier.view']);
        $item = $this->actingAs($viewer)
            ->getJson('/api/v1/supplier-deposits')
            ->assertOk()
            ->json('data.items.0');

        $this->assertNotNull($item);
        $this->assertArrayNotHasKey('nominal_awal', $item);
        $this->assertArrayNotHasKey('nominal_terpakai', $item);
        $this->assertArrayNotHasKey('sisa_deposit', $item);
    }
    #[Test]
    public function purchase_return_products_always_expose_sell_price_avg_cost_needs_hpp(): void
    {
        Permission::firstOrCreate(['name' => 'stok.view_hpp', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'retur-beli.create', 'guard_name' => 'web']);

        $viewer = $this->userWith(['retur-beli.create']);
        $item = $this->actingAs($viewer)
            ->getJson('/api/v1/purchase-returns/products?search='.$this->product->kode_produk)
            ->assertOk()
            ->json('data.items.0');

        $this->assertNotNull($item);
        $this->assertNotNull($item['units'][0]['harga_jual'] ?? null);
        $this->assertNull($item['avg_cost'] ?? null);

        $withHpp = $this->userWith(['retur-beli.create', 'stok.view_hpp']);
        $itemHpp = $this->actingAs($withHpp)
            ->getJson('/api/v1/purchase-returns/products?search='.$this->product->kode_produk)
            ->assertOk()
            ->json('data.items.0');
        $this->assertNotNull($itemHpp['avg_cost'] ?? null);
    }
}
