<?php

namespace Tests\Feature\Serial;

use App\Actions\Sales\CheckoutSalesAction;
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
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Penjualan serial (Fase A — checkout):
 * - unit terpilih → status terjual + sale_id/sale_detail_id/sold_at, qty=jumlah SN
 * - hpp_at_time = rata cost_per_unit unit yang dijual; avg_cost agregat = Metode A (unit tersisa)
 * - movement OUT per unit; guard: produk serial wajib pilih SN (tutup known-issue)
 * - regresi: produk retail tak berubah
 */
class SerialSalesCheckoutTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterWarehouse $warehouse2;
    protected MasterPosTerminal $terminal;
    protected PosTerminalShift $shift;
    protected MasterCustomer $customer;
    protected MasterMetodePembayaran $cash;
    protected MasterProduk $serial;
    protected MasterProduk $retail;
    protected CheckoutSalesAction $action;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_sales_percent', 0, 'integer');
        SettingService::set('rounding.sales_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        Permission::firstOrCreate(['name' => 'pos.access', 'guard_name' => 'web']);
        $this->user = User::factory()->create();
        $this->user->givePermissionTo('pos.access');
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);
        $this->warehouse2 = MasterWarehouse::factory()->create(['status' => 'active']);

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
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->retail->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 100, 'avg_cost' => 5000]
        );
        StockCard::record([
            'product_id' => $this->retail->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(), 'qty_in' => 100, 'qty_out' => 0, 'cost_per_unit' => 5000,
        ]);
        StockCard::$skipObserver = false;

        $this->action = new CheckoutSalesAction();
    }

    /** Seed unit serial konsisten (inventory_stock + stock_card + unit). @return SerialUnit[] */
    private function seedSerialUnits(MasterWarehouse $wh, array $costs): array
    {
        $count = count($costs);
        $avg = array_sum($costs) / $count;
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $wh->id],
            ['qty' => $count, 'avg_cost' => $avg]
        );
        StockCard::record([
            'product_id' => $this->serial->id, 'warehouse_id' => $wh->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(), 'qty_in' => $count, 'qty_out' => 0, 'cost_per_unit' => $avg,
        ]);
        StockCard::$skipObserver = false;
        $this->serial->update(['avg_cost' => $avg]);

        $units = [];
        foreach ($costs as $i => $c) {
            $units[] = SerialUnit::create([
                'product_id' => $this->serial->id, 'warehouse_id' => $wh->id,
                'serial_number' => "SN-{$wh->id}-" . ($i + 1), 'harga_modal' => $c, 'cost_per_unit' => $c, 'status' => 'tersedia',
            ]);
        }
        return $units;
    }

    private function serialItem(array $ulids, float $harga, array $overrides = []): array
    {
        $qty = count($ulids);
        return array_merge([
            'product_id' => $this->serial->id, 'unit' => 'UNIT', 'konversi' => 1,
            'qty' => $qty, 'qty_base' => $qty, 'harga_satuan' => $harga,
            'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
            'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
            'diskon_total' => 0, 'jumlah' => $qty * $harga,
            'serial_unit_ids' => $ulids,
        ], $overrides);
    }

    private function baseData(array $items, array $payments): array
    {
        return [
            'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'customer_id' => $this->customer->id,
            'items' => $items, 'payments' => $payments,
        ];
    }
    #[Test]
    public function serial_sale_marks_units_sold_sets_hpp_and_recomputes_avg()
    {
        // 3 unit: 4jt, 4jt, 5,2jt → avg awal 4,4jt
        $units = $this->seedSerialUnits($this->warehouse, [4000000, 4000000, 5200000]);

        // Jual 2 unit (4jt + 4jt) @ harga 6jt
        $sales = $this->action->execute($this->baseData(
            [$this->serialItem([$units[0]->ulid, $units[1]->ulid], 6000000)],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 12000000]]
        ));

        $detail = $sales->details->first();
        // hpp_at_time = rata unit terjual = 4jt
        $this->assertEqualsWithDelta(4000000, (float) $detail->hpp_at_time, 0.01);
        $this->assertEquals(2, (int) $detail->qty_base);
        $this->assertEqualsCanonicalizing([$units[0]->ulid, $units[1]->ulid], $detail->serial_unit_ids);

        // Unit terjual → status + sale link
        foreach ([$units[0], $units[1]] as $u) {
            $f = SerialUnit::where('ulid', $u->ulid)->first();
            $this->assertSame('terjual', $f->status);
            $this->assertSame($sales->id, $f->sale_id);
            $this->assertSame($detail->id, $f->sale_detail_id);
            $this->assertNotNull($f->sold_at);
        }
        // Unit sisa tetap tersedia
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[2]->ulid)->value('status'));

        // avg_cost agregat = unit tersisa (5,2jt) — Metode A
        $this->assertEqualsWithDelta(5200000, (float) $this->serial->fresh()->avg_cost, 0.01);

        // stock_card SALES: cost = hpp unit, avg bergeser 4,4jt → 5,2jt
        $sc = StockCard::where('transaction_id', $sales->id)->where('transaction_type', 'SALES')->first();
        $this->assertEqualsWithDelta(4000000, (float) $sc->cost_per_unit, 0.01);
        $this->assertEqualsWithDelta(4400000, (float) $sc->avg_cost_before, 0.01);
        $this->assertEqualsWithDelta(5200000, (float) $sc->avg_cost_after, 0.01);

        // stok agregat & movement
        $this->assertSame(1, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertSame(2, SerialUnitMovement::where('doc_type', 'SALES')->where('movement_type', 'OUT')->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function serial_sale_without_units_is_rejected_by_action()
    {
        $this->seedSerialUnits($this->warehouse, [4000000]);

        $this->expectException(ValidationException::class);
        $this->action->execute($this->baseData(
            [$this->serialItem([], 6000000, ['qty' => 1, 'qty_base' => 1, 'jumlah' => 6000000])],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 6000000]]
        ));
    }
    #[Test]
    public function serial_unit_from_wrong_warehouse_is_rejected()
    {
        $this->seedSerialUnits($this->warehouse, [4000000]);
        $other = $this->seedSerialUnits($this->warehouse2, [4000000]); // unit di gudang lain

        $this->expectException(ValidationException::class);
        $this->action->execute($this->baseData(
            [$this->serialItem([$other[0]->ulid], 6000000)],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 6000000]]
        ));
    }
    #[Test]
    public function http_checkout_blocks_serial_product_without_sn()
    {
        $this->seedSerialUnits($this->warehouse, [4000000]);

        $res = $this->postJson('/api/v1/pos/checkout', [
            'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'customer_id' => $this->customer->id,
            'items' => [$this->serialItem([], 6000000, ['serial_unit_ids' => [], 'qty' => 1, 'qty_base' => 1, 'jumlah' => 6000000])],
            'payments' => [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 6000000]],
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('nomor seri', strtolower($res->json('message') ?? ''));
        $this->assertSame(0, DocSales::count());
    }
    #[Test]
    public function http_checkout_allows_trillion_value_serial_price()
    {
        // Harga satuan skala triliun harus lolos (batas lama 9.999.999 sudah dinaikkan)
        $units = $this->seedSerialUnits($this->warehouse, [9000000]);
        $harga = 1000000000000; // 1 triliun

        $res = $this->postJson('/api/v1/pos/checkout', [
            'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'customer_id' => $this->customer->id,
            'items' => [$this->serialItem([$units[0]->ulid], $harga)],
            'payments' => [['metode_pembayaran_id' => $this->cash->id, 'nominal' => $harga]],
        ]);

        $res->assertStatus(201);
        $this->assertSame('terjual', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertEqualsWithDelta($harga, (float) $res->json('data.sales.grand_total'), 0.01);
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function serial_sale_rejects_when_sn_count_not_match_qty_base()
    {
        $units = $this->seedSerialUnits($this->warehouse, [4000000, 4000000]);

        $this->expectException(ValidationException::class);
        // qty_base 2 tapi hanya 1 SN dikirim
        $this->action->execute($this->baseData(
            [$this->serialItem([$units[0]->ulid], 6000000, ['qty' => 2, 'qty_base' => 2, 'jumlah' => 12000000])],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 12000000]]
        ));
    }
    #[Test]
    public function serial_sale_rejects_duplicate_sn_in_payload()
    {
        $units = $this->seedSerialUnits($this->warehouse, [4000000, 4000000]);

        $this->expectException(ValidationException::class);
        // SN sama 2× → dedup jadi 1, tak sama dengan qty_base 2
        $this->action->execute($this->baseData(
            [$this->serialItem([$units[0]->ulid, $units[0]->ulid], 6000000, ['qty' => 2, 'qty_base' => 2, 'jumlah' => 12000000])],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 12000000]]
        ));
    }
    #[Test]
    public function serial_sale_rejects_unit_belonging_to_other_product()
    {
        $units = $this->seedSerialUnits($this->warehouse, [4000000]);

        // Produk serial lain + 1 unit tersedia
        $other = MasterProduk::create([
            'kode_produk' => 'SERHP2', 'nama_produk' => 'iPhone Serial 2', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
        $otherUnit = SerialUnit::create([
            'product_id' => $other->id, 'warehouse_id' => $this->warehouse->id,
            'serial_number' => 'OTHER-1', 'harga_modal' => 4000000, 'cost_per_unit' => 4000000, 'status' => 'tersedia',
        ]);

        $this->expectException(ValidationException::class);
        // Jual produk $this->serial tapi pakai SN milik produk lain
        $this->action->execute($this->baseData(
            [$this->serialItem([$otherUnit->ulid], 6000000)],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 6000000]]
        ));
    }
    #[Test]
    public function selling_all_serial_units_resets_avg_to_zero_without_hpp_reset_card()
    {
        $units = $this->seedSerialUnits($this->warehouse, [4000000, 5000000]);

        $this->action->execute($this->baseData(
            [$this->serialItem([$units[0]->ulid, $units[1]->ulid], 6000000)],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 12000000]]
        ));

        $this->assertEqualsWithDelta(0, (float) $this->serial->fresh()->avg_cost, 0.01);
        $this->assertSame(0, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->warehouse->id)->value('qty'));
        // Serial pakai Metode A (avg 0 saat habis) — bukan HPP_RESET terpisah
        $this->assertSame(0, StockCard::where('product_id', $this->serial->id)->where('transaction_type', 'HPP_RESET')->count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function serial_and_retail_in_same_checkout_both_correct()
    {
        $units = $this->seedSerialUnits($this->warehouse, [4000000]);

        $sales = $this->action->execute($this->baseData(
            [
                $this->serialItem([$units[0]->ulid], 6000000),
                [
                    'product_id' => $this->retail->id, 'unit' => 'PCS', 'konversi' => 1,
                    'qty' => 2, 'qty_base' => 2, 'harga_satuan' => 10000,
                    'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                    'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                    'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                    'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                    'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                    'diskon_total' => 0, 'jumlah' => 20000,
                ],
            ],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 6020000]]
        ));

        $this->assertSame(2, $sales->details->count());
        $this->assertSame('terjual', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertSame(98, (int) InventoryStock::where('product_id', $this->retail->id)->where('warehouse_id', $this->warehouse->id)->value('qty'));
        // Retail hpp = avg_cost; serial hpp = cost unit
        $retailDetail = $sales->details->firstWhere('product_id', $this->retail->id);
        $serialDetail = $sales->details->firstWhere('product_id', $this->serial->id);
        $this->assertEqualsWithDelta(5000, (float) $retailDetail->hpp_at_time, 0.01);
        $this->assertEqualsWithDelta(4000000, (float) $serialDetail->hpp_at_time, 0.01);
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function lookup_serial_unit_returns_card_and_sellable_flag()
    {
        $units = $this->seedSerialUnits($this->warehouse, [4000000]);
        $sn = SerialUnit::where('ulid', $units[0]->ulid)->value('serial_number');

        // Tersedia di gudang ini → sellable; product.id WAJIB ada (dipakai frontend
        // sebagai product_id baris keranjang — kalau hilang, unit gagal masuk keranjang)
        $this->getJson("/api/v1/serial-units/lookup?serial_number={$sn}&warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.sellable', true)
            ->assertJsonPath('data.unit.serial_number', $sn)
            ->assertJsonPath('data.unit.product.id', $this->serial->id);

        // Gudang lain → tidak sellable + ada alasan
        $this->getJson("/api/v1/serial-units/lookup?serial_number={$sn}&warehouse_id={$this->warehouse2->id}")
            ->assertOk()
            ->assertJsonPath('data.sellable', false);

        // SN tak terdaftar → 404
        $this->getJson("/api/v1/serial-units/lookup?serial_number=NOTEXIST&warehouse_id={$this->warehouse->id}")
            ->assertStatus(404);
    }
    #[Test]
    public function lookup_by_kode_internal_returns_exact_unit()
    {
        $units = $this->seedSerialUnits($this->warehouse, [4000000, 4200000]);
        $u0 = SerialUnit::where('ulid', $units[0]->ulid)->first();

        // Scan kode_internal (UNIK) → langsung unit itu, matched_by kode_internal
        $this->getJson("/api/v1/serial-units/lookup?code={$u0->kode_internal}&warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.matched_by', 'kode_internal')
            ->assertJsonPath('data.sellable', true)
            ->assertJsonPath('data.unit.ulid', $u0->ulid)
            ->assertJsonPath('data.unit.kode_internal', $u0->kode_internal);
    }
    #[Test]
    public function lookup_by_duplicate_serial_number_returns_ambiguous_candidates()
    {
        // 2 unit ber-SN SAMA, dua-duanya tersedia di gudang ini → scan SN = ambigu
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 2, 'avg_cost' => 4000000]
        );
        StockCard::record([
            'product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(), 'qty_in' => 2, 'qty_out' => 0, 'cost_per_unit' => 4000000,
        ]);
        StockCard::$skipObserver = false;
        foreach ([0, 1] as $i) {
            SerialUnit::create([
                'product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse->id,
                'serial_number' => 'DUPSN', 'harga_modal' => 4000000, 'cost_per_unit' => 4000000, 'status' => 'tersedia',
            ]);
        }

        $res = $this->getJson("/api/v1/serial-units/lookup?code=DUPSN&warehouse_id={$this->warehouse->id}")
            ->assertOk()
            ->assertJsonPath('data.ambiguous', true)
            ->assertJsonPath('data.matched_by', 'serial_number');

        $this->assertCount(2, $res->json('data.candidates'));
        $kodes = collect($res->json('data.candidates'))->pluck('kode_internal');
        $this->assertSame(2, $kodes->unique()->count());
    }
    #[Test]
    public function lookup_serial_number_with_single_sellable_here_is_not_ambiguous()
    {
        // 2 unit ber-SN sama: 1 tersedia di gudang ini, 1 di gudang LAIN → tak ambigu (cuma 1 sellable di sini)
        SerialUnit::create(['product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse->id, 'serial_number' => 'DUP-W', 'harga_modal' => 1, 'cost_per_unit' => 1, 'status' => 'tersedia']);
        SerialUnit::create(['product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse2->id, 'serial_number' => 'DUP-W', 'harga_modal' => 1, 'cost_per_unit' => 1, 'status' => 'tersedia']);

        $res = $this->getJson("/api/v1/serial-units/lookup?code=DUP-W&warehouse_id={$this->warehouse->id}")->assertOk();

        $this->assertNull($res->json('data.ambiguous'));
        $res->assertJsonPath('data.sellable', true);
        $res->assertJsonPath('data.matched_by', 'serial_number');
        // Unit yang dikembalikan adalah yang di gudang ini (warehouse.id di-hidden → cek via ulid)
        $res->assertJsonPath('data.unit.warehouse.ulid', $this->warehouse->ulid);
    }
    #[Test]
    public function lookup_forbidden_without_permission()
    {
        $this->actingAs(User::factory()->create()); // tanpa pos.access / serial-intake.view

        $this->getJson("/api/v1/serial-units/lookup?code=APAPUN&warehouse_id={$this->warehouse->id}")
            ->assertStatus(403);
    }
    #[Test]
    public function available_units_accessible_with_pos_access_for_picker()
    {
        // Picker SN di POS memanggil serial-units/available — kasir (pos.access) harus boleh
        $this->seedSerialUnits($this->warehouse, [4000000, 4200000]);

        $res = $this->getJson("/api/v1/serial-units/available?product_id={$this->serial->ulid}&warehouse_id={$this->warehouse->id}")
            ->assertOk();
        $this->assertCount(2, $res->json('data.items'));
    }
    #[Test]
    public function receipt_endpoint_includes_serial_unit_details()
    {
        $units = $this->seedSerialUnits($this->warehouse, [4000000, 4200000]);
        $sales = $this->action->execute($this->baseData(
            [$this->serialItem([$units[0]->ulid, $units[1]->ulid], 6000000)],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 12000000]]
        ));

        $res = $this->getJson("/api/v1/pos/sales/{$sales->ulid}")->assertOk();
        $detail = $res->json('data.sales.details.0');

        // Tiap baris serial bawa daftar unit (SN + field lengkap) untuk dicetak di nota
        $this->assertCount(2, $detail['serial_units']);
        $sns = collect($detail['serial_units'])->pluck('serial_number')->all();
        $this->assertEqualsCanonicalizing(
            [SerialUnit::where('ulid', $units[0]->ulid)->value('serial_number'), SerialUnit::where('ulid', $units[1]->ulid)->value('serial_number')],
            $sns
        );
        foreach (['serial_number', 'grade', 'battery_health', 'account_status', 'catatan'] as $f) {
            $this->assertArrayHasKey($f, $detail['serial_units'][0]);
        }
    }
    #[Test]
    public function retail_sale_unchanged_regression()
    {
        $sales = $this->action->execute($this->baseData(
            [[
                'product_id' => $this->retail->id, 'unit' => 'PCS', 'konversi' => 1,
                'qty' => 3, 'qty_base' => 3, 'harga_satuan' => 10000,
                'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
                'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
                'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
                'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
                'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
                'diskon_total' => 0, 'jumlah' => 30000,
            ]],
            [['metode_pembayaran_id' => $this->cash->id, 'nominal' => 30000]]
        ));

        $detail = $sales->details->first();
        $this->assertEqualsWithDelta(5000, (float) $detail->hpp_at_time, 0.01); // = avg_cost
        $this->assertNull($detail->serial_unit_ids);
        $this->assertSame(97, (int) InventoryStock::where('product_id', $this->retail->id)->where('warehouse_id', $this->warehouse->id)->value('qty'));
        // tak ada movement serial untuk retail
        $this->assertSame(0, SerialUnitMovement::count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
}
