<?php

namespace Tests\Feature\StockOpname;

use App\Actions\StockOpname\ApproveStockOpnameAction;
use App\Actions\StockOpname\CreateStockOpnameAction;
use App\Models\DocAdjustment;
use App\Models\DocStockOpname;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class StockOpnameCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterProduk $product;
    protected CreateStockOpnameAction $createAction;
    protected ApproveStockOpnameAction $approveAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Test Product',
            'avg_cost' => 10000,
            'status' => 'active',
        ]);

        // System stock = 50 (+ kartu PURCHASE padanan agar invariant stock_card konsisten
        // → data:verify lulus setelah perubahan stok).
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 50, 'avg_cost' => 10000]
        );
        StockCard::record([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 50, 'qty_out' => 0, 'cost_per_unit' => 10000,
            'avg_cost_before' => 10000, 'avg_cost_after' => 10000,
        ]);
        StockCard::$skipObserver = false;

        $this->createAction = new CreateStockOpnameAction();
        $this->approveAction = new ApproveStockOpnameAction();
    }

    private function baseData(int $qtyPhysical = 50, array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->warehouse->id,
            'tanggal_opname' => '2026-04-12 10:00:00',
            'mode' => 'partial',
            'notes' => 'Test opname',
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'qty_physical' => $qtyPhysical,
                ],
            ],
        ], $overrides);
    }
    #[Test]
    public function create_opname_captures_system_stock_and_calculates_difference()
    {
        // System = 50, Physical = 48 → difference = -2
        $opname = $this->createAction->execute($this->baseData(48));

        $this->assertEquals('draft', $opname->status);
        $this->assertStringStartsWith('OPN-', $opname->nomor_dokumen);

        $detail = $opname->details->first();
        $this->assertEquals(50, $detail->qty_system);
        $this->assertEquals(48, $detail->qty_physical);
        $this->assertEquals(-2, $detail->qty_difference);
    }
    #[Test]
    public function create_opname_blocks_duplicate_draft_for_same_warehouse()
    {
        $this->createAction->execute($this->baseData(50));

        // Try to create another draft for same warehouse
        $this->expectException(ValidationException::class);
        $this->createAction->execute($this->baseData(50));
    }
    #[Test]
    public function approve_opname_without_difference_does_not_create_adjustment()
    {
        // System = 50, Physical = 50 → difference = 0
        $opname = $this->createAction->execute($this->baseData(50));

        $this->approveAction->execute($opname);

        $this->assertEquals('approved', $opname->fresh()->status);
        $this->assertEquals(0, DocAdjustment::count(), 'No adjustment should be created');
    }
    #[Test]
    public function approve_opname_with_negative_difference_auto_creates_kredit_adjustment()
    {
        // System = 50, Physical = 45 → difference = -5 → adjustment kredit 5
        $opname = $this->createAction->execute($this->baseData(45));

        $this->approveAction->execute($opname);

        $adjustment = DocAdjustment::first();
        $this->assertNotNull($adjustment);
        $this->assertEquals('approved', $adjustment->status, 'Auto-approved by opname flow');
        $this->assertEquals('opname', $adjustment->source);

        $detail = $adjustment->details->first();
        $this->assertEquals('kredit', $detail->jenis);
        $this->assertEquals(5, $detail->qty);

        // Stock should reflect adjustment
        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(45, $stock->qty);
    }
    #[Test]
    public function approve_opname_with_positive_difference_auto_creates_debit_adjustment()
    {
        // System = 50, Physical = 55 → difference = +5 → adjustment debit 5
        $opname = $this->createAction->execute($this->baseData(55));

        $this->approveAction->execute($opname);

        $adjustment = DocAdjustment::first();
        $this->assertNotNull($adjustment);
        $this->assertEquals('approved', $adjustment->status);

        $detail = $adjustment->details->first();
        $this->assertEquals('debit', $detail->jenis);
        $this->assertEquals(5, $detail->qty);

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(55, $stock->qty);
    }
    #[Test]
    public function approve_opname_creates_stock_opname_stock_card_entry()
    {
        $opname = $this->createAction->execute($this->baseData(48));

        $this->approveAction->execute($opname);

        $opnameEntry = StockCard::where('transaction_id', $opname->id)
            ->where('transaction_type', 'STOCK_OPNAME')
            ->first();

        $this->assertNotNull($opnameEntry);
        $this->assertEquals(0, $opnameEntry->qty_in, 'STOCK_OPNAME is recording-only, no qty change');
        $this->assertEquals(0, $opnameEntry->qty_out);
        $this->assertStringContainsString('sistem=50', $opnameEntry->notes);
        $this->assertStringContainsString('fisik=48', $opnameEntry->notes);
    }
    #[Test]
    public function approve_opname_throws_when_already_approved()
    {
        $opname = $this->createAction->execute($this->baseData(50));
        $this->approveAction->execute($opname);

        $this->expectException(ValidationException::class);
        $this->approveAction->execute($opname->fresh());
    }

    // ===================== EDGE CASE TAMBAHAN (galak) =====================

    /**
     * Selisih KURANG (fisik < sistem): adjustment kredit turunan dengan qty = |selisih|,
     * tertaut ke opname (opname_id), stok turun eksak. data:verify wajib 0.
     */
    #[Test]
    public function selisih_kurang_buat_adjustment_kredit_turunan_eksak()
    {
        // sistem 50, fisik 42 → selisih -8
        $opname = $this->createAction->execute($this->baseData(42));
        $this->approveAction->execute($opname);

        $adjustment = DocAdjustment::first();
        $this->assertNotNull($adjustment);
        $this->assertSame('opname', $adjustment->source);
        $this->assertSame($opname->id, (int) $adjustment->opname_id);
        $this->assertSame('approved', $adjustment->status);

        $detail = $adjustment->details->first();
        $this->assertSame('kredit', $detail->jenis);
        $this->assertSame(8, (int) $detail->qty);

        // Stok 50 - 8 = 42 eksak
        $this->assertSame(42, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));

        // Kartu ADJUSTMENT_OUT qty_out 8 terbentuk
        $card = StockCard::where('transaction_id', $adjustment->id)
            ->where('transaction_type', 'ADJUSTMENT_OUT')->first();
        $this->assertNotNull($card);
        $this->assertSame(8.0, (float) $card->qty_out);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Selisih LEBIH (fisik > sistem): adjustment debit turunan, qty = selisih, stok naik eksak.
     * data:verify wajib 0.
     */
    #[Test]
    public function selisih_lebih_buat_adjustment_debit_turunan_eksak()
    {
        // sistem 50, fisik 57 → selisih +7
        $opname = $this->createAction->execute($this->baseData(57));
        $this->approveAction->execute($opname);

        $adjustment = DocAdjustment::first();
        $this->assertNotNull($adjustment);
        $detail = $adjustment->details->first();
        $this->assertSame('debit', $detail->jenis);
        $this->assertSame(7, (int) $detail->qty);

        $this->assertSame(57, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));

        $card = StockCard::where('transaction_id', $adjustment->id)
            ->where('transaction_type', 'ADJUSTMENT_IN')->first();
        $this->assertNotNull($card);
        $this->assertSame(7.0, (float) $card->qty_in);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Fisik == sistem: tidak ada adjustment, stok tetap, hanya kartu STOCK_OPNAME (recording).
     * data:verify wajib 0.
     */
    #[Test]
    public function selisih_cocok_tidak_ubah_stok_dan_tanpa_adjustment()
    {
        $opname = $this->createAction->execute($this->baseData(50));
        $this->approveAction->execute($opname);

        $this->assertSame(0, DocAdjustment::count());
        $this->assertSame(50, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));

        // Tepat satu kartu STOCK_OPNAME, qty in/out 0
        $cards = StockCard::where('transaction_id', $opname->id)
            ->where('transaction_type', 'STOCK_OPNAME')->get();
        $this->assertCount(1, $cards);
        $this->assertSame(0.0, (float) $cards->first()->qty_in);
        $this->assertSame(0.0, (float) $cards->first()->qty_out);

        // Tidak ada kartu adjustment
        $this->assertSame(0, StockCard::whereIn('transaction_type', ['ADJUSTMENT_IN', 'ADJUSTMENT_OUT'])->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Approve me-REFRESH qty_system dari stok aktual saat approve, bukan snapshot saat create.
     * Stok berubah jadi 60 setelah opname dibuat (fisik 50 dicatat saat sistem masih 50);
     * selisih dihitung ulang = 50 - 60 = -10 → adjustment kredit 10, stok jadi 50.
     */
    #[Test]
    public function approve_refresh_qty_system_dari_stok_aktual()
    {
        // Buat opname dengan fisik 50 saat sistem masih 50 (selisih awal 0)
        $opname = $this->createAction->execute($this->baseData(50));
        $this->assertSame(0, (int) $opname->details->first()->qty_difference);

        // Stok berubah jadi 60 SEBELUM approve (mis. ada transaksi lain)
        StockCard::$skipObserver = true;
        InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->update(['qty' => 60]);
        // Tambah kartu PURCHASE +10 supaya invariant stock_card konsisten
        StockCard::record([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 10, 'qty_out' => 0, 'cost_per_unit' => 10000,
            'avg_cost_before' => 10000, 'avg_cost_after' => 10000,
        ]);
        StockCard::$skipObserver = false;

        $this->approveAction->execute($opname);

        // qty_system di-refresh ke 60, selisih dihitung ulang = 50 - 60 = -10
        $detail = $opname->fresh()->details->first();
        $this->assertSame(60, (int) $detail->qty_system);
        $this->assertSame(-10, (int) $detail->qty_difference);

        // Adjustment kredit 10 → stok 60 - 10 = 50
        $adjustment = DocAdjustment::first();
        $this->assertSame('kredit', $adjustment->details->first()->jenis);
        $this->assertSame(10, (int) $adjustment->details->first()->qty);
        $this->assertSame(50, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Approve kedua kali ditolak & TIDAK menggandakan adjustment maupun kartu opname.
     */
    #[Test]
    public function approve_opname_kedua_kali_tidak_gandakan_adjustment()
    {
        $opname = $this->createAction->execute($this->baseData(45)); // selisih -5
        $this->approveAction->execute($opname);

        $this->assertSame(1, DocAdjustment::count());
        $opnameCardsAfterFirst = StockCard::where('transaction_id', $opname->id)
            ->where('transaction_type', 'STOCK_OPNAME')->count();
        $this->assertSame(1, $opnameCardsAfterFirst);

        try {
            $this->approveAction->execute($opname->fresh());
            $this->fail('Approve kedua seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Tetap 1 adjustment, stok tetap 45
        $this->assertSame(1, DocAdjustment::count());
        $this->assertSame(45, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));
    }
}
