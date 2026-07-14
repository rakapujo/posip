<?php

namespace Tests\Feature\PurchaseReturn;

use App\Actions\PurchaseReturn\ApprovePurchaseReturnAction;
use App\Actions\PurchaseReturn\CreatePurchaseReturnAction;
use App\Actions\PurchaseReturn\LockPurchaseReturnAction;
use App\Models\DocPurchaseReturn;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\SupplierDeposit;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PurchaseReturnCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterSupplier $supplier;
    protected MasterProduk $product;
    protected CreatePurchaseReturnAction $createAction;
    protected LockPurchaseReturnAction $lockAction;
    protected ApprovePurchaseReturnAction $approveAction;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');
        SettingService::set('stock.negative_mode', 'block', 'string');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->supplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-001',
            'nama_supplier' => 'Test Supplier',
            'nama_pic' => 'John',
            'telepon' => '081234',
            'tempo_default' => 14,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Test Product',
            'avg_cost' => 8000,
            'status' => 'active',
        ]);

        // Initial stock = 50
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 50, 'avg_cost' => 8000]
        );
        StockCard::$skipObserver = false;

        $this->createAction = new CreatePurchaseReturnAction();
        $this->lockAction = new LockPurchaseReturnAction();
        $this->approveAction = new ApprovePurchaseReturnAction();
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '2026-04-12',
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'notes' => 'Barang rusak',
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'unit_used' => 'PCS',
                    'unit_konversi' => 1,
                    'qty_in_unit' => 5,
                    'harga_per_unit' => 8000,
                ],
            ],
        ], $overrides);
    }

    /**
     * Seed saldo awal dengan stock_card padanan supaya invariant
     * SUM(qty_in - qty_out) === inventory_stock.qty terpenuhi (data:verify HIJAU).
     * setUp() mengisi inventory_stock=50 TANPA stock_card; helper ini menggantinya.
     */
    private function seedOpeningBalanceWithStockCard(int $qty = 50, float $cost = 8000): void
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => $qty, 'avg_cost' => $cost]
        );
        StockCard::record([
            'product_id' => $this->product->id,
            'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'ADJUSTMENT_IN',
            'tanggal' => '2026-04-01',
            'qty_in' => $qty,
            'qty_out' => 0,
            'cost_per_unit' => $cost,
            'avg_cost_before' => 0,
            'avg_cost_after' => $cost,
        ]);
        StockCard::$skipObserver = false;
        $this->product->update(['avg_cost' => $cost]);
    }
    #[Test]
    public function create_purchase_return_has_draft_status()
    {
        $retur = $this->createAction->execute($this->baseData());

        $this->assertInstanceOf(DocPurchaseReturn::class, $retur);
        $this->assertEquals('draft', $retur->status);
        $this->assertStringStartsWith('RPB-', $retur->nomor_dokumen);
        $this->assertEquals(1, $retur->details->count());
        $this->assertEquals(40000, $retur->nilai_kalkulasi, '5 × 8000 = 40000');
    }
    #[Test]
    public function create_purchase_return_does_not_change_stock()
    {
        $this->createAction->execute($this->baseData());

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(50, $stock->qty, 'Stock unchanged on create (only on lock)');
    }
    #[Test]
    public function lock_purchase_return_reduces_stock_and_changes_status()
    {
        $retur = $this->createAction->execute($this->baseData());

        $locked = $this->lockAction->execute($retur->fresh());

        $this->assertEquals('lock', $locked->status);
        $this->assertNotNull($locked->locked_at);
        $this->assertEquals($this->user->id, $locked->locked_by);

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(45, $stock->qty, 'Stock reduced by 5 after lock');
    }
    #[Test]
    public function lock_purchase_return_creates_purchase_return_stock_card()
    {
        $retur = $this->createAction->execute($this->baseData());

        $this->lockAction->execute($retur->fresh());

        $stockCard = StockCard::where('transaction_id', $retur->id)
            ->where('transaction_type', 'PURCHASE_RETURN')
            ->first();

        $this->assertNotNull($stockCard);
        $this->assertEquals(0, $stockCard->qty_in);
        $this->assertEquals(5, $stockCard->qty_out);
        $this->assertEquals(8000, $stockCard->avg_cost_before);
        $this->assertEquals(8000, $stockCard->avg_cost_after, 'HPP does not change on PURCHASE_RETURN');
    }
    #[Test]
    public function lock_throws_when_stock_insufficient()
    {
        $retur = $this->createAction->execute($this->baseData([
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => 100, // More than stock (50)
                'harga_per_unit' => 8000,
            ]],
        ]));

        $this->expectException(ValidationException::class);
        $this->lockAction->execute($retur->fresh());
    }
    #[Test]
    public function approve_purchase_return_creates_supplier_deposit()
    {
        $retur = $this->createAction->execute($this->baseData());
        $this->lockAction->execute($retur->fresh());

        $approved = $this->approveAction->execute($retur->fresh(), [
            'nilai_diakui' => 40000,
            'catatan_approval' => 'Fully refunded',
        ]);

        $this->assertEquals('approved', $approved->status);
        $this->assertEquals(40000, $approved->nilai_diakui);
        $this->assertEquals(0, $approved->selisih);

        $deposit = SupplierDeposit::where('retur_id', $retur->id)->first();
        $this->assertNotNull($deposit);
        $this->assertEquals('available', $deposit->status);
        $this->assertEquals(40000, $deposit->nominal_awal);
        $this->assertEquals(40000, $deposit->sisa_deposit);
    }
    #[Test]
    public function approve_with_partial_nilai_diakui_tracks_selisih()
    {
        $retur = $this->createAction->execute($this->baseData());
        $this->lockAction->execute($retur->fresh());

        // nilai_kalkulasi = 40000, nilai_diakui = 30000 → selisih -10000
        $approved = $this->approveAction->execute($retur->fresh(), [
            'nilai_diakui' => 30000,
        ]);

        $this->assertEquals(30000, $approved->nilai_diakui);
        $this->assertEquals(-10000, $approved->selisih);

        $deposit = SupplierDeposit::where('retur_id', $retur->id)->first();
        $this->assertEquals(30000, $deposit->nominal_awal);
    }
    #[Test]
    public function approve_with_zero_nilai_diakui_does_not_create_deposit()
    {
        $retur = $this->createAction->execute($this->baseData());
        $this->lockAction->execute($retur->fresh());

        $this->approveAction->execute($retur->fresh(), [
            'nilai_diakui' => 0,
        ]);

        $this->assertEquals(0, SupplierDeposit::where('retur_id', $retur->id)->count());
    }
    #[Test]
    public function approve_throws_when_status_is_draft_not_lock()
    {
        $retur = $this->createAction->execute($this->baseData());
        // Skip lock step

        $this->expectException(ValidationException::class);
        $this->approveAction->execute($retur->fresh(), ['nilai_diakui' => 40000]);
    }
    #[Test]
    public function lock_throws_when_already_locked()
    {
        $retur = $this->createAction->execute($this->baseData());
        $this->lockAction->execute($retur->fresh());

        $this->expectException(ValidationException::class);
        $this->lockAction->execute($retur->fresh());
    }

    // ===================================================================
    // EDGE CASE TAMBAHAN — valuasi cost eksak, invariant stok, lifecycle
    // ===================================================================
    #[Test]
    public function lock_retur_valuasi_cost_per_unit_pakai_hpp_dan_invariant_hijau()
    {
        // Saldo awal 50@8000 (dengan stock_card). Retur 5 pcs.
        // PURCHASE_RETURN: qty_out 5, cost_per_unit = HPP saat ini (8000), HPP tidak berubah.
        $this->seedOpeningBalanceWithStockCard(50, 8000);
        $retur = $this->createAction->execute($this->baseData());

        $this->lockAction->execute($retur->fresh());

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(45, $stock->qty, '50 - 5');
        $this->assertSame('8000.0000', (string) $stock->avg_cost, 'HPP tidak berubah pada PURCHASE_RETURN.');

        $card = StockCard::where('transaction_id', $retur->id)
            ->where('transaction_type', 'PURCHASE_RETURN')->first();
        $this->assertEquals(0, $card->qty_in);
        $this->assertEquals(5, $card->qty_out);
        $this->assertSame('8000.0000', (string) $card->cost_per_unit, 'valuasi keluar = HPP non-serial.');
        $this->assertSame('8000.0000', (string) $card->avg_cost_before);
        $this->assertSame('8000.0000', (string) $card->avg_cost_after);

        $this->assertSame(
            0,
            Artisan::call('data:verify', ['--fail-on-mismatch' => true]),
            'Invariant stok harus konsisten setelah lock retur.'
        );
    }

    /**
     * Regresi bug dedup: dua baris detail untuk produk SAMA dulu memecah invariant
     * (inventory di-decrement 2× tapi stock_card di-dedup). Setelah perbaikan,
     * LockPurchaseReturnAction memberi guard defense-in-depth (tolak produk ganda),
     * sehingga stok TIDAK berubah & invariant tetap konsisten.
     */
    #[Test]
    public function lock_menolak_dua_baris_produk_sama()
    {
        $this->seedOpeningBalanceWithStockCard(50, 8000);

        $retur = $this->createAction->execute($this->baseData([
            'details' => [
                ['product_id' => $this->product->id, 'unit_used' => 'PCS', 'unit_konversi' => 1, 'qty_in_unit' => 3, 'harga_per_unit' => 8000],
                ['product_id' => $this->product->id, 'unit_used' => 'PCS', 'unit_konversi' => 1, 'qty_in_unit' => 2, 'harga_per_unit' => 8000],
            ],
        ]));

        try {
            $this->lockAction->execute($retur->fresh());
            $this->fail('Lock dua baris produk sama seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details', $e->errors());
        }

        // Lock di-rollback → stok utuh, tak ada stock_card retur, invariant bersih
        $this->assertEquals(50, InventoryStock::where('product_id', $this->product->id)->value('qty'));
        $this->assertSame(0, StockCard::where('transaction_id', $retur->id)->where('transaction_type', 'PURCHASE_RETURN')->count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function lock_throws_with_exact_message_when_already_locked()
    {
        $retur = $this->createAction->execute($this->baseData());
        $this->lockAction->execute($retur->fresh());

        try {
            $this->lockAction->execute($retur->fresh());
            $this->fail('Lock kedua seharusnya melempar ValidationException.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
            $this->assertEquals(
                'Hanya retur dengan status draft dan memiliki detail yang dapat dikunci.',
                $e->errors()['status'][0]
            );
        }
    }
    #[Test]
    public function lock_ganda_tidak_mengurangi_stok_dua_kali()
    {
        // Lock pertama mengurangi 5 (50→45). Lock kedua ditolak → stok tetap 45.
        $this->seedOpeningBalanceWithStockCard(50, 8000);
        $retur = $this->createAction->execute($this->baseData());
        $this->lockAction->execute($retur->fresh());

        try {
            $this->lockAction->execute($retur->fresh());
        } catch (ValidationException $e) {
            // expected
        }

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(45, $stock->qty, 'Stok tidak boleh berkurang dua kali.');
        $this->assertEquals(
            1,
            StockCard::where('transaction_id', $retur->id)->where('transaction_type', 'PURCHASE_RETURN')->count(),
            'Hanya satu entri stock_card PURCHASE_RETURN.'
        );
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function lock_insufficient_stock_pesan_eksak_dan_stok_tidak_berubah()
    {
        // Stok 50, retur 100 → ditolak (negative_mode = block dari setUp).
        $this->seedOpeningBalanceWithStockCard(50, 8000);
        $retur = $this->createAction->execute($this->baseData([
            'details' => [[
                'product_id' => $this->product->id, 'unit_used' => 'PCS', 'unit_konversi' => 1,
                'qty_in_unit' => 100, 'harga_per_unit' => 8000,
            ]],
        ]));

        try {
            $this->lockAction->execute($retur->fresh());
            $this->fail('Seharusnya melempar ValidationException stok tidak cukup.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(50, $stock->qty, 'Stok tidak boleh berubah saat lock gagal.');
        $this->assertEquals('draft', $retur->fresh()->status, 'Status tetap draft.');
        $this->assertEquals(0, StockCard::where('transaction_id', $retur->id)->count());
    }
    #[Test]
    public function retur_bebas_tanpa_po_id_berhasil_dibuat_dan_dikunci()
    {
        // po_id nullable: retur tanpa referensi PO tetap valid (lihat CreatePurchaseReturnAction).
        $this->seedOpeningBalanceWithStockCard(50, 8000);
        $retur = $this->createAction->execute($this->baseData()); // tidak ada po_id

        $this->assertNull($retur->po_id, 'po_id boleh null (retur bebas).');

        $locked = $this->lockAction->execute($retur->fresh());
        $this->assertEquals('lock', $locked->status);

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(45, $stock->qty);
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function create_retur_dengan_header_diskon_persen_menghitung_nilai_kalkulasi_eksak()
    {
        // subtotal 40000, header diskon 25% → nilai_kalkulasi 30000.
        $retur = $this->createAction->execute($this->baseData([
            'diskon_1_tipe' => 'percent',
            'diskon_1_nilai' => 25,
        ]));

        $this->assertEquals(40000, $retur->subtotal, '5 × 8000');
        $this->assertEquals(10000, $retur->diskon_1_hasil, '25% × 40000');
        $this->assertEquals(10000, $retur->total_diskon_header);
        $this->assertEquals(30000, $retur->dpp);
        $this->assertEquals(30000, $retur->nilai_kalkulasi, '40000 - 10000');
    }
    #[Test]
    public function create_retur_dengan_pajak_persen_menambah_nilai_kalkulasi_eksak()
    {
        // PPN 11%: subtotal 40000 → pajak 4400 → nilai_kalkulasi 44400.
        SettingService::set('tax.tax_purchase_percent', 11, 'integer');

        $retur = $this->createAction->execute($this->baseData());

        $this->assertEquals(40000, $retur->dpp);
        $this->assertEquals(11, $retur->pajak_persen);
        $this->assertEquals(4400, $retur->pajak_nominal, '11% × 40000');
        $this->assertEquals(44400, $retur->nilai_kalkulasi, '40000 + 4400');
    }
    #[Test]
    public function approve_lifecycle_lengkap_deposit_dan_invariant_hijau()
    {
        // draft → lock (stok 50→45) → approve full (deposit 40000), invariant tetap hijau.
        $this->seedOpeningBalanceWithStockCard(50, 8000);
        $retur = $this->createAction->execute($this->baseData());
        $this->lockAction->execute($retur->fresh());

        $approved = $this->approveAction->execute($retur->fresh(), [
            'nilai_diakui' => 40000,
            'catatan_approval' => 'Disetujui penuh',
        ]);

        $this->assertEquals('approved', $approved->status);
        $this->assertEquals(0, $approved->selisih, 'nilai_diakui == nilai_kalkulasi → selisih 0');

        $deposit = SupplierDeposit::where('retur_id', $retur->id)->first();
        $this->assertEquals(40000, $deposit->nominal_awal);
        $this->assertEquals(40000, $deposit->sisa_deposit);
        $this->assertEquals('available', $deposit->status);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function approve_dengan_nilai_diakui_melebihi_kalkulasi_selisih_positif()
    {
        // nilai_kalkulasi 40000, diakui 50000 → selisih +10000, deposit 50000.
        $retur = $this->createAction->execute($this->baseData());
        $this->lockAction->execute($retur->fresh());

        $approved = $this->approveAction->execute($retur->fresh(), [
            'nilai_diakui' => 50000,
        ]);

        $this->assertEquals(50000, $approved->nilai_diakui);
        $this->assertEquals(10000, $approved->selisih, '50000 - 40000');

        $deposit = SupplierDeposit::where('retur_id', $retur->id)->first();
        $this->assertEquals(50000, $deposit->nominal_awal);
        $this->assertEquals(50000, $deposit->sisa_deposit);
    }
    #[Test]
    public function approve_nilai_diakui_negatif_ditolak_pesan_eksak()
    {
        $retur = $this->createAction->execute($this->baseData());
        $this->lockAction->execute($retur->fresh());

        try {
            $this->approveAction->execute($retur->fresh(), ['nilai_diakui' => -1]);
            $this->fail('nilai_diakui negatif seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('nilai_diakui', $e->errors());
            $this->assertEquals('Nilai diakui tidak boleh negatif.', $e->errors()['nilai_diakui'][0]);
        }

        $this->assertEquals('lock', $retur->fresh()->status, 'Status tetap lock saat approve gagal.');
        $this->assertEquals(0, SupplierDeposit::where('retur_id', $retur->id)->count());
    }
    #[Test]
    public function approve_dari_draft_ditolak_pesan_eksak()
    {
        $retur = $this->createAction->execute($this->baseData()); // belum di-lock

        try {
            $this->approveAction->execute($retur->fresh(), ['nilai_diakui' => 40000]);
            $this->fail('Approve dari draft seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
            $this->assertEquals(
                'Hanya retur dengan status lock yang dapat disetujui.',
                $e->errors()['status'][0]
            );
        }
    }
}
