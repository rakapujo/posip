<?php

namespace Tests\Feature\Serial;

use App\Actions\Sales\CheckoutSalesAction;
use App\Actions\Sales\ProcessSalesReturnAction;
use App\Actions\Sales\VoidSalesAction;
use App\Models\DocSales;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\PosTerminalShift;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Penjualan serial (Fase B — Void & Retur):
 * - Void: SEMUA unit nota → tersedia, putus tautan sale, movement IN, avg di-recompute.
 * - Retur: pilih SN yang kembali → tersedia; sisanya tetap terjual; stok +count.
 * - Tolak: kembalikan unit yang bukan terjual / bukan dari nota.
 * - Regresi: retail void tetap.
 */
class SerialSalesReturnVoidTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterPosTerminal $terminal;
    protected PosTerminalShift $shift;
    protected MasterCustomer $customer;
    protected MasterMetodePembayaran $cash;
    protected MasterProduk $serial;
    protected MasterProduk $retail;
    protected CheckoutSalesAction $checkout;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(), 'kode_customer' => 'CUST-001', 'nama' => 'Walk-in',
            'telepon' => '0812', 'jenis' => 'spesifik', 'status' => 'active', 'created_by' => $this->user->id,
        ]);

        $this->cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(), 'kode_pembayaran' => 'CASH', 'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai', 'biaya_tambahan_tipe' => 'none', 'biaya_tambahan_nilai' => 0,
            'status' => 'active', 'created_by' => $this->user->id,
        ]);

        $this->terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(), 'kode_terminal' => 'TRM-001', 'nama_terminal' => 'Kasir 1',
            'warehouse_id' => $this->warehouse->id, 'default_customer_id' => $this->customer->id,
            'default_metode_pembayaran_id' => $this->cash->id, 'active_user_id' => $this->user->id,
            'status' => 'active', 'created_by' => $this->user->id,
        ]);

        $this->shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(), 'terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id, 'started_at' => now(),
        ]);

        $this->serial = MasterProduk::create([
            'kode_produk' => 'SERHP', 'nama_produk' => 'iPhone Serial', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);

        $this->retail = MasterProduk::factory()->create([
            'nama_produk' => 'Charger', 'avg_cost' => 5000, 'harga_4' => 10000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'status' => 'active', 'is_serial' => false,
        ]);
        $this->seedStock($this->retail, 100, 5000);

        $this->checkout = new CheckoutSalesAction();
    }

    private function seedStock(MasterProduk $p, int $qty, float $avg): void
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $p->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => $qty, 'avg_cost' => $avg]
        );
        StockCard::record([
            'product_id' => $p->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(), 'qty_in' => $qty, 'qty_out' => 0, 'cost_per_unit' => $avg,
        ]);
        StockCard::$skipObserver = false;
    }

    /** @return SerialUnit[] */
    private function seedSerialUnits(array $costs): array
    {
        $count = count($costs);
        $avg = array_sum($costs) / $count;
        $this->seedStock($this->serial, $count, $avg);
        $this->serial->update(['avg_cost' => $avg]);

        $units = [];
        foreach ($costs as $i => $c) {
            $units[] = SerialUnit::create([
                'product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse->id,
                'serial_number' => 'SN-' . ($i + 1), 'harga_modal' => $c, 'cost_per_unit' => $c, 'status' => 'tersedia',
            ]);
        }
        return $units;
    }

    private function sellSerial(array $units, float $harga = 6000000): DocSales
    {
        $ulids = array_map(fn ($u) => $u->ulid, $units);
        $qty = count($ulids);
        return $this->checkout->execute([
            'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'customer_id' => $this->customer->id,
            'items' => [[
                'product_id' => $this->serial->id, 'unit' => 'UNIT', 'konversi' => 1,
                'qty' => $qty, 'qty_base' => $qty, 'harga_satuan' => $harga,
                'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                'diskon_total' => 0, 'jumlah' => $qty * $harga, 'serial_unit_ids' => $ulids,
            ]],
            'payments' => [['metode_pembayaran_id' => $this->cash->id, 'nominal' => $qty * $harga]],
        ]);
    }
    #[Test]
    public function void_serial_sale_reverts_all_units()
    {
        $units = $this->seedSerialUnits([4000000, 4000000, 5200000]);
        $sales = $this->sellSerial([$units[0], $units[1]]);

        (new VoidSalesAction())->execute($sales->fresh(), 'salah input');

        // Semua unit terjual balik tersedia + tautan sale putus
        foreach ([$units[0], $units[1]] as $u) {
            $f = SerialUnit::where('ulid', $u->ulid)->first();
            $this->assertSame('tersedia', $f->status);
            $this->assertNull($f->sale_id);
            $this->assertNull($f->sold_at);
        }
        // Stok kembali 3, avg = rata semua unit lagi (4,4jt)
        $this->assertSame(3, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertEqualsWithDelta(4400000, (float) $this->serial->fresh()->avg_cost, 0.01);
        // Movement VOID IN = 2
        $this->assertSame(2, SerialUnitMovement::where('doc_type', 'VOID')->where('movement_type', 'IN')->count());
        $this->assertSame('voided', $sales->fresh()->status);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function return_serial_reverts_selected_unit_only()
    {
        $units = $this->seedSerialUnits([4000000, 4000000, 5200000]);
        $sales = $this->sellSerial([$units[0], $units[1]]);
        $detail = $sales->details->first();

        $return = (new ProcessSalesReturnAction())->execute([
            'sales_id' => $sales->id, 'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'refund_method' => 'cash',
            'items' => [[
                'sales_detail_id' => $detail->id, 'product_id' => $this->serial->id,
                'qty' => 1, 'harga_per_base' => 0, 'serial_unit_ids' => [$units[0]->ulid],
            ]],
        ]);

        // units[0] kembali tersedia; units[1] tetap terjual; units[2] tetap tersedia
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertNull(SerialUnit::where('ulid', $units[0]->ulid)->value('sale_id'));
        $this->assertSame('terjual', SerialUnit::where('ulid', $units[1]->ulid)->value('status'));
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[2]->ulid)->value('status'));

        // Stok 1 → 2 ; avg = (units[0] 4jt + units[2] 5,2jt)/2 = 4,6jt
        $this->assertSame(2, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertEqualsWithDelta(4600000, (float) $this->serial->fresh()->avg_cost, 0.01);

        // detail retur simpan SN + hpp = cost unit yang dikembalikan (4jt)
        $rd = $return->details->first();
        $this->assertEqualsCanonicalizing([$units[0]->ulid], $rd->serial_unit_ids);
        $this->assertEqualsWithDelta(4000000, (float) $rd->hpp_at_time, 0.01);

        $this->assertSame(1, SerialUnitMovement::where('doc_type', 'SALES_RETURN')->where('movement_type', 'IN')->count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function return_rejects_unit_not_sold_in_this_sale()
    {
        $units = $this->seedSerialUnits([4000000, 4000000, 5200000]);
        $sales = $this->sellSerial([$units[0], $units[1]]);
        $detail = $sales->details->first();

        $this->expectException(ValidationException::class);
        // units[2] tidak pernah terjual → tak boleh diretur
        (new ProcessSalesReturnAction())->execute([
            'sales_id' => $sales->id, 'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'refund_method' => 'cash',
            'items' => [[
                'sales_detail_id' => $detail->id, 'product_id' => $this->serial->id,
                'qty' => 1, 'harga_per_base' => 0, 'serial_unit_ids' => [$units[2]->ulid],
            ]],
        ]);
    }

    /** Helper: proses retur serial untuk daftar SN. */
    private function returnUnits(DocSales $sales, $detail, array $ulids)
    {
        return (new ProcessSalesReturnAction())->execute([
            'sales_id' => $sales->id, 'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'refund_method' => 'cash',
            'items' => [[
                'sales_detail_id' => $detail->id, 'product_id' => $this->serial->id,
                'qty' => count($ulids), 'harga_per_base' => 0, 'serial_unit_ids' => $ulids,
            ]],
        ]);
    }
    #[Test]
    public function void_rejects_already_voided_sale()
    {
        $units = $this->seedSerialUnits([4000000, 5000000]);
        $sales = $this->sellSerial([$units[0]]);
        (new VoidSalesAction())->execute($sales->fresh(), 'pertama');

        $this->expectException(ValidationException::class);
        (new VoidSalesAction())->execute($sales->fresh(), 'lagi');
    }
    #[Test]
    public function return_rejected_from_voided_sale()
    {
        $units = $this->seedSerialUnits([4000000, 5000000]);
        $sales = $this->sellSerial([$units[0]]);
        $detail = $sales->details->first();
        (new VoidSalesAction())->execute($sales->fresh(), 'void');

        $this->expectException(ValidationException::class);
        $this->returnUnits($sales, $detail, [$units[0]->ulid]);
    }
    #[Test]
    public function return_same_unit_twice_is_rejected()
    {
        $units = $this->seedSerialUnits([4000000, 5000000]);
        $sales = $this->sellSerial([$units[0], $units[1]]);
        $detail = $sales->details->first();
        $this->returnUnits($sales, $detail, [$units[0]->ulid]); // retur pertama OK

        $this->expectException(ValidationException::class);
        $this->returnUnits($sales, $detail, [$units[0]->ulid]); // unit sudah tersedia → tolak
    }
    #[Test]
    public function partial_then_full_return_serial_consistent()
    {
        $units = $this->seedSerialUnits([4000000, 5000000]);
        $sales = $this->sellSerial([$units[0], $units[1]]);
        $detail = $sales->details->first();

        $this->returnUnits($sales, $detail, [$units[0]->ulid]);
        $this->returnUnits($sales, $detail, [$units[1]->ulid]);

        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[1]->ulid)->value('status'));
        $this->assertSame(2, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->warehouse->id)->value('qty'));
        // avg = (4jt + 5jt)/2
        $this->assertEqualsWithDelta(4500000, (float) $this->serial->fresh()->avg_cost, 0.01);
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function void_mixed_serial_and_retail_nota_restores_both()
    {
        $units = $this->seedSerialUnits([4000000]);

        $serialLine = [
            'product_id' => $this->serial->id, 'unit' => 'UNIT', 'konversi' => 1,
            'qty' => 1, 'qty_base' => 1, 'harga_satuan' => 6000000,
            'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
            'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
            'diskon_total' => 0, 'jumlah' => 6000000, 'serial_unit_ids' => [$units[0]->ulid],
        ];
        $retailLine = [
            'product_id' => $this->retail->id, 'unit' => 'PCS', 'konversi' => 1,
            'qty' => 3, 'qty_base' => 3, 'harga_satuan' => 10000,
            'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
            'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
            'diskon_total' => 0, 'jumlah' => 30000,
        ];

        $sales = $this->checkout->execute([
            'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'customer_id' => $this->customer->id,
            'items' => [$serialLine, $retailLine],
            'payments' => [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 6030000]],
        ]);

        (new VoidSalesAction())->execute($sales->fresh(), 'batal');

        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertNull(SerialUnit::where('ulid', $units[0]->ulid)->value('sale_id'));
        $this->assertSame(100, (int) InventoryStock::where('product_id', $this->retail->id)->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertSame(1, SerialUnitMovement::where('doc_type', 'VOID')->where('movement_type', 'IN')->count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function retail_void_unchanged_regression()
    {
        $sales = $this->checkout->execute([
            'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'customer_id' => $this->customer->id,
            'items' => [[
                'product_id' => $this->retail->id, 'unit' => 'PCS', 'konversi' => 1,
                'qty' => 4, 'qty_base' => 4, 'harga_satuan' => 10000,
                'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                'diskon_total' => 0, 'jumlah' => 40000,
            ]],
            'payments' => [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 40000]],
        ]);

        (new VoidSalesAction())->execute($sales->fresh(), 'batal');

        $this->assertSame(100, (int) InventoryStock::where('product_id', $this->retail->id)->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertSame(0, SerialUnitMovement::count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
}
