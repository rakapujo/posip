<?php

namespace Tests\Feature\Reports;

use App\Actions\Sales\CheckoutSalesAction;
use App\Exports\SalesPerNotaExport;
use App\Models\InventoryStock;
use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\PosTerminalShift;
use App\Models\SerialUnit;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Export "Penjualan per Nota" — kolom "Nomor Seri" menggabungkan SN semua unit serial
 * pada nota (dipisah koma). Nota tanpa serial → kosong. Lookup ulid→serial_number 1 batch.
 */
class SalesPerNotaSerialExportTest extends TestCase
{
    use RefreshDatabase;

    /** Index kolom "Kode Internal" lalu "Nomor Seri" pada map() (15 kolom sebelumnya: 0..14). */
    private const KODE_COL = 15;
    private const SN_COL = 16;

    protected User $user;
    protected MasterWarehouse $warehouse;
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

    /** @return SerialUnit[] */
    private function seedSerialUnits(array $serialNumbers, float $cost = 4000000): array
    {
        $count = count($serialNumbers);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => $count, 'avg_cost' => $cost]
        );
        StockCard::record([
            'product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(), 'qty_in' => $count, 'qty_out' => 0, 'cost_per_unit' => $cost,
        ]);
        StockCard::$skipObserver = false;
        $this->serial->update(['avg_cost' => $cost]);

        $units = [];
        foreach ($serialNumbers as $sn) {
            $units[] = SerialUnit::create([
                'product_id' => $this->serial->id, 'warehouse_id' => $this->warehouse->id,
                'serial_number' => $sn, 'harga_modal' => $cost, 'cost_per_unit' => $cost, 'status' => 'tersedia',
            ]);
        }
        return $units;
    }

    private function serialItem(array $ulids, float $harga): array
    {
        $qty = count($ulids);
        return [
            'product_id' => $this->serial->id, 'unit' => 'UNIT', 'konversi' => 1,
            'qty' => $qty, 'qty_base' => $qty, 'harga_satuan' => $harga,
            'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
            'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
            'diskon_total' => 0, 'jumlah' => $qty * $harga,
            'serial_unit_ids' => $ulids,
        ];
    }

    private function retailItem(int $qty): array
    {
        return [
            'product_id' => $this->retail->id, 'unit' => 'PCS', 'konversi' => 1,
            'qty' => $qty, 'qty_base' => $qty, 'harga_satuan' => 10000,
            'diskon_1_tipe' => 'none', 'diskon_1_nilai' => 0,
            'diskon_2_tipe' => 'none', 'diskon_2_nilai' => 0,
            'diskon_3_tipe' => 'none', 'diskon_3_nilai' => 0,
            'diskon_4_tipe' => 'none', 'diskon_4_nilai' => 0,
            'diskon_5_tipe' => 'none', 'diskon_5_nilai' => 0,
            'diskon_total' => 0, 'jumlah' => $qty * 10000,
        ];
    }

    private function checkout(array $items, float $nominal)
    {
        return $this->action->execute([
            'terminal_id' => $this->terminal->id, 'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id, 'customer_id' => $this->customer->id,
            'items' => $items,
            'payments' => [['metode_pembayaran_id' => $this->cash->id, 'nominal' => $nominal]],
        ]);
    }
    #[Test]
    public function headings_include_nomor_seri_as_last_column()
    {
        $export = new SalesPerNotaExport('2026-01-01', '2026-12-31');
        $headings = $export->headings();

        $this->assertSame('Kode Internal', $headings[self::KODE_COL]);
        $this->assertSame('Nomor Seri', $headings[self::SN_COL]);
        $this->assertSame('Nomor Seri', end($headings));
    }
    #[Test]
    public function serial_note_lists_all_serial_numbers_comma_separated()
    {
        $units = $this->seedSerialUnits(['IP14-0001', 'IP14-0002']);
        $this->checkout([$this->serialItem([$units[0]->ulid, $units[1]->ulid], 6000000)], 12000000);

        $export = new SalesPerNotaExport('2026-01-01', '2026-12-31');
        $rows = collect($export->query()->get())->map(fn ($r) => $export->map($r));

        $this->assertCount(1, $rows);
        $serialCell = $rows->first()[self::SN_COL];

        // Kedua SN muncul, dipisah ", " (urutan apa pun)
        $this->assertStringContainsString('IP14-0001', $serialCell);
        $this->assertStringContainsString('IP14-0002', $serialCell);
        $this->assertSame(
            ['IP14-0001', 'IP14-0002'],
            collect(explode(', ', $serialCell))->sort()->values()->all()
        );

        // Kolom Kode Internal berisi kode_internal kedua unit (auto KI-)
        $kodeCell = $rows->first()[self::KODE_COL];
        $expected = collect($units)->map(fn ($u) => $u->fresh()->kode_internal)->sort()->values()->all();
        $this->assertSame($expected, collect(explode(', ', $kodeCell))->sort()->values()->all());
    }
    #[Test]
    public function non_serial_note_has_empty_serial_column()
    {
        $this->checkout([$this->retailItem(2)], 20000);

        $export = new SalesPerNotaExport('2026-01-01', '2026-12-31');
        $rows = collect($export->query()->get())->map(fn ($r) => $export->map($r));

        $this->assertCount(1, $rows);
        $this->assertSame('', $rows->first()[self::SN_COL]);
        $this->assertSame('', $rows->first()[self::KODE_COL]);
    }
}
