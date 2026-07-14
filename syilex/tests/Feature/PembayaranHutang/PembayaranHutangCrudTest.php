<?php

namespace Tests\Feature\PembayaranHutang;

use App\Actions\PembayaranHutang\CompletePembayaranHutangAction;
use App\Actions\PembayaranHutang\CreatePembayaranHutangAction;
use App\Actions\PurchaseOrder\ApprovePurchaseOrderAction;
use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Models\DocPembayaranHutang;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\SupplierHutang;
use App\Models\User;
use App\Models\SupplierDeposit;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PembayaranHutangCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterSupplier $supplier;
    protected MasterProduk $product;
    protected SupplierHutang $hutang;
    protected CreatePembayaranHutangAction $createAction;
    protected CompletePembayaranHutangAction $completeAction;

    protected function setUp(): void
    {
        parent::setUp();

        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');

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
            'avg_cost' => 5000,
            'status' => 'active',
        ]);

        // Seed stok awal 10 + stock_card PURCHASE padanan supaya invariant stok
        // (SUM(stock_card.qty_in - qty_out) === inventory_stock.qty) konsisten,
        // termasuk setelah PO approve di bawah menambah 10 lagi. Syarat agar
        // `data:verify` lulus pada edge-case ledger/deposit.
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 5000]
        );
        StockCard::record([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => '2026-04-01',
            'qty_in' => 10, 'qty_out' => 0, 'cost_per_unit' => 5000,
        ]);
        StockCard::$skipObserver = false;

        // Create + approve a PO to generate a SupplierHutang
        $createPo = new CreatePurchaseOrderAction();
        $approvePo = new ApprovePurchaseOrderAction();

        $po = $createPo->execute([
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
        $approvePo->execute($po);

        $this->hutang = SupplierHutang::where('po_id', $po->id)->first();
        // hutang nominal_awal = 100000

        $this->createAction = new CreatePembayaranHutangAction();
        $this->completeAction = new CompletePembayaranHutangAction();
    }

    private function basePaymentData(float $nominal, array $overrides = []): array
    {
        return array_merge([
            'tanggal' => '2026-04-15',
            'supplier_id' => $this->supplier->id,
            'metode_pembayaran' => 'cash',
            'notes' => 'Pembayaran sebagian',
            'details' => [[
                'hutang_id' => $this->hutang->id,
                'nominal_dibayar' => $nominal,
                'sumber' => 'cash',
            ]],
        ], $overrides);
    }
    #[Test]
    public function create_payment_has_draft_status_and_correct_totals()
    {
        $payment = $this->createAction->execute($this->basePaymentData(50000));

        $this->assertInstanceOf(DocPembayaranHutang::class, $payment);
        $this->assertEquals('draft', $payment->status);
        $this->assertStringStartsWith('PBH-', $payment->nomor_dokumen);
        $this->assertEquals(50000, $payment->total_bayar_cash);
        $this->assertEquals(0, $payment->total_bayar_deposit);
        $this->assertEquals(50000, $payment->total_pembayaran);
        $this->assertEquals(1, $payment->details->count());
    }
    #[Test]
    public function create_payment_throws_when_hutang_belongs_to_other_supplier()
    {
        $otherSupplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-OTHER',
            'nama_supplier' => 'Other Supplier',
            'nama_pic' => 'Jane',
            'telepon' => '081555',
            'tempo_default' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->createAction->execute($this->basePaymentData(50000, [
            'supplier_id' => $otherSupplier->id, // Mismatch — hutang belongs to original supplier
        ]));
    }
    #[Test]
    public function complete_payment_updates_hutang_remaining()
    {
        $payment = $this->createAction->execute($this->basePaymentData(40000));

        $this->completeAction->execute($payment);

        $hutang = $this->hutang->fresh();
        $this->assertEquals(40000, $hutang->nominal_terbayar);
        $this->assertEquals(60000, $hutang->sisa_hutang);
        $this->assertEquals('partial', $hutang->status);
    }
    #[Test]
    public function complete_payment_marks_hutang_as_paid_when_full()
    {
        $payment = $this->createAction->execute($this->basePaymentData(100000));

        $this->completeAction->execute($payment);

        $hutang = $this->hutang->fresh();
        $this->assertEquals(100000, $hutang->nominal_terbayar);
        $this->assertEquals(0, $hutang->sisa_hutang);
        $this->assertEquals('paid', $hutang->status);
    }
    #[Test]
    public function complete_payment_updates_status_to_completed()
    {
        $payment = $this->createAction->execute($this->basePaymentData(50000));

        $completed = $this->completeAction->execute($payment);

        $this->assertEquals('completed', $completed->status);
        $this->assertNotNull($completed->completed_at);
        $this->assertEquals($this->user->id, $completed->completed_by);
    }
    #[Test]
    public function complete_throws_when_payment_exceeds_remaining_hutang()
    {
        // hutang nominal = 100000, try to pay 150000
        $payment = $this->createAction->execute($this->basePaymentData(150000));

        $this->expectException(ValidationException::class);
        $this->completeAction->execute($payment);
    }
    #[Test]
    public function complete_throws_when_already_completed()
    {
        $payment = $this->createAction->execute($this->basePaymentData(50000));
        $this->completeAction->execute($payment);

        $this->expectException(ValidationException::class);
        $this->completeAction->execute($payment->fresh());
    }
    #[Test]
    public function partial_payments_can_be_made_in_sequence()
    {
        // First payment: 30000
        $payment1 = $this->createAction->execute($this->basePaymentData(30000));
        $this->completeAction->execute($payment1);

        // Hutang: paid 30000, sisa 70000
        $this->assertEquals(70000, $this->hutang->fresh()->sisa_hutang);

        // Second payment: 50000
        $payment2 = $this->createAction->execute($this->basePaymentData(50000));
        $this->completeAction->execute($payment2);

        // Hutang: paid 80000, sisa 20000
        $this->assertEquals(80000, $this->hutang->fresh()->nominal_terbayar);
        $this->assertEquals(20000, $this->hutang->fresh()->sisa_hutang);
        $this->assertEquals('partial', $this->hutang->fresh()->status);

        // Third payment that would exceed: 30000 (but only 20000 left)
        $payment3 = $this->createAction->execute($this->basePaymentData(30000));
        $this->expectException(ValidationException::class);
        $this->completeAction->execute($payment3);
    }

    // ==================== EDGE CASE: LEDGER EKSAK, DEPOSIT, BOUNDARY ====================

    /**
     * Helper: buat deposit supplier. retur_id NOT NULL di SQLite, jadi buat PR dummy.
     */
    private function createDeposit(float $nominalAwal, ?int $supplierId = null): SupplierDeposit
    {
        $supplierId = $supplierId ?? $this->supplier->id;

        $returId = DB::table('doc_purchase_return')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'RTR-' . fake()->unique()->numerify('######'),
            'tanggal' => '2026-04-10',
            'supplier_id' => $supplierId,
            'warehouse_id' => $this->warehouse->id,
            'po_id' => $this->hutang->po_id,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return SupplierDeposit::create([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $supplierId,
            'retur_id' => $returId,
            'tanggal' => '2026-04-12',
            'nominal_awal' => $nominalAwal,
            'nominal_terpakai' => 0,
            'sisa_deposit' => $nominalAwal,
            'status' => 'available',
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Invariant ledger: sisa_hutang === nominal_awal − Σ pembayaran (eksak), dan
     * `data:verify` HARUS 0 (hutang ledger konsisten) setelah pembayaran complete.
     *
     */
    #[Test]
    public function ledger_sisa_hutang_eksak_dan_data_verify_lulus()
    {
        // nominal_awal = 100000
        $payment = $this->createAction->execute($this->basePaymentData(40000));
        $this->completeAction->execute($payment);

        $hutang = $this->hutang->fresh();
        // sisa = 100000 - 40000 = 60000 (eksak)
        $this->assertEquals(60000, (float) $hutang->sisa_hutang);
        $this->assertEquals(40000, (float) $hutang->nominal_terbayar);
        $this->assertEquals(
            (float) $hutang->nominal_awal - (float) $hutang->nominal_terbayar,
            (float) $hutang->sisa_hutang
        );

        // Invariant ledger lulus
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Boundary: membayar PERSIS sebesar sisa hutang diterima (sisa = 0, status paid).
     *
     */
    #[Test]
    public function bayar_persis_sebesar_sisa_diterima_dan_lunas()
    {
        // Bayar sebagian 100000 dipecah: dulu 60000, lalu sisa persis 40000
        $p1 = $this->createAction->execute($this->basePaymentData(60000));
        $this->completeAction->execute($p1);
        $this->assertEquals(40000, (float) $this->hutang->fresh()->sisa_hutang);

        // Bayar persis 40000 (= sisa) -> diterima, lunas
        $p2 = $this->createAction->execute($this->basePaymentData(40000));
        $this->completeAction->execute($p2);

        $hutang = $this->hutang->fresh();
        $this->assertEquals(0, (float) $hutang->sisa_hutang);
        $this->assertEquals(100000, (float) $hutang->nominal_terbayar);
        $this->assertEquals('paid', $hutang->status);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Pembayaran via DEPOSIT supplier: deposit berkurang persis, hutang berkurang,
     * dan ledger tetap konsisten (data:verify == 0). Deposit ikut dihitung di ledger.
     *
     */
    #[Test]
    public function pembayaran_via_deposit_mengurangi_deposit_dan_hutang_eksak()
    {
        $deposit = $this->createDeposit(70000);

        // Bayar 50000 dari deposit
        $payment = $this->createAction->execute([
            'tanggal' => '2026-04-15',
            'supplier_id' => $this->supplier->id,
            'metode_pembayaran' => 'cash',
            'details' => [[
                'hutang_id' => $this->hutang->id,
                'nominal_dibayar' => 50000,
                'sumber' => 'deposit',
            ]],
            'deposit_usages' => [[
                'deposit_id' => $deposit->id,
                'nominal_digunakan' => 50000,
            ]],
        ]);

        // Header: total deposit 50000, cash 0
        $this->assertEquals(0, (float) $payment->total_bayar_cash);
        $this->assertEquals(50000, (float) $payment->total_bayar_deposit);
        $this->assertEquals(50000, (float) $payment->total_pembayaran);

        $this->completeAction->execute($payment);

        // Deposit: sisa 70000 - 50000 = 20000, terpakai 50000, status used_partial
        $deposit->refresh();
        $this->assertEquals(20000, (float) $deposit->sisa_deposit);
        $this->assertEquals(50000, (float) $deposit->nominal_terpakai);
        $this->assertEquals('used_partial', $deposit->status);

        // Hutang: sisa 100000 - 50000 = 50000
        $hutang = $this->hutang->fresh();
        $this->assertEquals(50000, (float) $hutang->sisa_hutang);
        $this->assertEquals('partial', $hutang->status);

        // Ledger konsisten (deposit detail ikut dihitung)
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Penggunaan deposit melebihi sisa deposit ditolak saat complete; hutang & deposit
     * tidak berubah.
     *
     */
    #[Test]
    public function pembayaran_deposit_melebihi_sisa_deposit_ditolak()
    {
        $deposit = $this->createDeposit(30000);

        // Coba pakai 50000 dari deposit yang sisanya hanya 30000
        $payment = $this->createAction->execute([
            'tanggal' => '2026-04-15',
            'supplier_id' => $this->supplier->id,
            'metode_pembayaran' => 'cash',
            'details' => [[
                'hutang_id' => $this->hutang->id,
                'nominal_dibayar' => 50000,
                'sumber' => 'deposit',
            ]],
            'deposit_usages' => [[
                'deposit_id' => $deposit->id,
                'nominal_digunakan' => 50000,
            ]],
        ]);

        try {
            $this->completeAction->execute($payment);
            $this->fail('Penggunaan deposit melebihi sisa seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('deposit_usages', $e->errors());
        }

        // Tidak ada perubahan
        $this->assertEquals(30000, (float) $deposit->fresh()->sisa_deposit);
        $this->assertEquals('available', $deposit->fresh()->status);
        $this->assertEquals(100000, (float) $this->hutang->fresh()->sisa_hutang);
        $this->assertEquals('draft', $payment->fresh()->status);
    }

    /**
     * Deposit milik supplier lain ditolak saat create.
     *
     */
    #[Test]
    public function pembayaran_deposit_milik_supplier_lain_ditolak()
    {
        $otherSupplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-DEPX',
            'nama_supplier' => 'Other Dep Supplier',
            'nama_pic' => 'Z',
            'telepon' => '08111',
            'tempo_default' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);
        $foreignDeposit = $this->createDeposit(70000, $otherSupplier->id);

        $this->expectException(ValidationException::class);
        $this->createAction->execute([
            'tanggal' => '2026-04-15',
            'supplier_id' => $this->supplier->id,
            'metode_pembayaran' => 'cash',
            'details' => [[
                'hutang_id' => $this->hutang->id,
                'nominal_dibayar' => 50000,
                'sumber' => 'deposit',
            ]],
            'deposit_usages' => [[
                'deposit_id' => $foreignDeposit->id, // milik supplier lain
                'nominal_digunakan' => 50000,
            ]],
        ]);
    }

    /**
     * Complete hanya sekali: complete ke-2 ditolak dan TIDAK mengurangi hutang dua kali.
     *
     */
    #[Test]
    public function complete_hanya_sekali_tanpa_pengurangan_ganda()
    {
        $payment = $this->createAction->execute($this->basePaymentData(40000));
        $this->completeAction->execute($payment);

        $this->assertEquals(60000, (float) $this->hutang->fresh()->sisa_hutang);

        try {
            $this->completeAction->execute($payment->fresh());
            $this->fail('Complete kedua seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Hutang tidak dikurangi dua kali
        $this->assertEquals(60000, (float) $this->hutang->fresh()->sisa_hutang);
        $this->assertEquals(40000, (float) $this->hutang->fresh()->nominal_terbayar);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
}
