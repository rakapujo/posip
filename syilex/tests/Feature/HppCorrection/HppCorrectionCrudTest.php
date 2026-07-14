<?php

namespace Tests\Feature\HppCorrection;

use App\Actions\HppCorrection\ApproveHppCorrectionAction;
use App\Actions\HppCorrection\CreateHppCorrectionAction;
use App\Models\DocHppCorrection;
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

class HppCorrectionCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterProduk $product;
    protected CreateHppCorrectionAction $createAction;
    protected ApproveHppCorrectionAction $approveAction;

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

        // Seed stok awal 50 + stock_card PURCHASE padanan supaya invariant
        // SUM(stock_card.qty_in - qty_out) === inventory_stock.qty terpenuhi
        // (agar `data:verify` lulus). HPP_CORRECTION tidak mengubah qty.
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 50, 'avg_cost' => 10000]
        );
        StockCard::record([
            'product_id' => $this->product->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => '2026-04-01',
            'qty_in' => 50, 'qty_out' => 0, 'cost_per_unit' => 10000,
        ]);
        StockCard::$skipObserver = false;

        $this->createAction = new CreateHppCorrectionAction();
        $this->approveAction = new ApproveHppCorrectionAction();
    }

    private function baseData(float $hppBaru = 12000, array $overrides = []): array
    {
        return array_merge([
            'tanggal_koreksi' => '2026-04-12 10:00:00',
            'notes' => 'HPP correction test',
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'hpp_baru' => $hppBaru,
                    'alasan' => 'KOREKSI_HARGA_BELI',
                ],
            ],
        ], $overrides);
    }
    #[Test]
    public function create_hpp_correction_captures_current_hpp_as_hpp_lama()
    {
        $correction = $this->createAction->execute($this->baseData(12000));

        $this->assertInstanceOf(DocHppCorrection::class, $correction);
        $this->assertEquals('draft', $correction->status);
        $this->assertStringStartsWith('HPC-', $correction->nomor_dokumen);

        $detail = $correction->details->first();
        $this->assertEquals(10000, $detail->hpp_lama, 'Should capture current product avg_cost');
        $this->assertEquals(12000, $detail->hpp_baru);
        $this->assertEquals('KOREKSI_HARGA_BELI', $detail->alasan);
    }
    #[Test]
    public function create_hpp_correction_does_not_change_product_avg_cost()
    {
        $this->createAction->execute($this->baseData(12000));

        $this->product->refresh();
        $this->assertEquals(10000, $this->product->avg_cost, 'avg_cost should remain unchanged on draft');
    }
    #[Test]
    public function create_hpp_correction_blocks_duplicate_draft_globally()
    {
        $this->createAction->execute($this->baseData(12000));

        // Try to create another draft (different product or same — both should be blocked)
        $product2 = MasterProduk::factory()->create(['avg_cost' => 5000, 'status' => 'active']);

        $this->expectException(ValidationException::class);
        $this->createAction->execute([
            'tanggal_koreksi' => '2026-04-12 10:00:00',
            'details' => [[
                'product_id' => $product2->id,
                'hpp_baru' => 6000,
                'alasan' => 'KOREKSI_DATA',
            ]],
        ]);
    }
    #[Test]
    public function approve_hpp_correction_updates_product_avg_cost()
    {
        $correction = $this->createAction->execute($this->baseData(15000));

        $this->approveAction->execute($correction);

        $this->product->refresh();
        $this->assertEquals(15000, $this->product->avg_cost, 'avg_cost should be updated to hpp_baru');
    }
    #[Test]
    public function approve_hpp_correction_syncs_to_inventory_stock()
    {
        $correction = $this->createAction->execute($this->baseData(15000));

        $this->approveAction->execute($correction);

        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertEquals(15000, $stock->avg_cost, 'inventory_stock avg_cost should sync');
    }
    #[Test]
    public function approve_hpp_correction_creates_hpp_correction_stock_card_with_null_warehouse()
    {
        $correction = $this->createAction->execute($this->baseData(15000));

        $this->approveAction->execute($correction);

        $stockCard = StockCard::where('transaction_id', $correction->id)
            ->where('transaction_type', 'HPP_CORRECTION')
            ->first();

        $this->assertNotNull($stockCard);
        $this->assertNull($stockCard->warehouse_id, 'HPP correction is global, no warehouse');
        $this->assertEquals(0, $stockCard->qty_in);
        $this->assertEquals(0, $stockCard->qty_out);
        $this->assertEquals(10000, $stockCard->avg_cost_before);
        $this->assertEquals(15000, $stockCard->avg_cost_after);
    }
    #[Test]
    public function approve_hpp_correction_does_not_change_inventory_qty()
    {
        $correction = $this->createAction->execute($this->baseData(15000));

        $this->approveAction->execute($correction);

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(50, $stock->qty, 'qty should not change, only avg_cost');
    }
    #[Test]
    public function approve_hpp_correction_throws_when_already_approved()
    {
        $correction = $this->createAction->execute($this->baseData(12000));
        $this->approveAction->execute($correction);

        $this->expectException(ValidationException::class);
        $this->approveAction->execute($correction->fresh());
    }

    // ==================== EDGE CASE: GUARD SERIAL, NILAI EKSAK, INVARIANT ====================

    /**
     * Produk serial DITOLAK di koreksi HPP agregat (harus pakai menu Koreksi HPP Serial).
     * Pastikan error key 'details' dan avg_cost produk serial tidak berubah.
     *
     */
    #[Test]
    public function create_hpp_correction_menolak_produk_serial()
    {
        $serial = MasterProduk::factory()->create([
            'nama_produk' => 'Produk Serial',
            'avg_cost' => 7000,
            'is_serial' => true,
            'status' => 'active',
        ]);

        try {
            $this->createAction->execute([
                'tanggal_koreksi' => '2026-04-12 10:00:00',
                'details' => [[
                    'product_id' => $serial->id,
                    'hpp_baru' => 9000,
                    'alasan' => 'KOREKSI_DATA',
                ]],
            ]);
            $this->fail('Koreksi HPP agregat untuk produk serial seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('details', $e->errors());
        }

        // avg_cost serial tidak berubah, dan tidak ada dokumen koreksi terbentuk
        $this->assertEquals(7000, $serial->fresh()->avg_cost);
        $this->assertEquals(0, DocHppCorrection::count());
    }

    /**
     * stock_card HPP_CORRECTION: avg_before/after EKSAK, qty_in/out = 0,
     * cost_per_unit = hpp_baru, warehouse null. qty stok tidak berubah.
     *
     */
    #[Test]
    public function approve_hpp_correction_mencatat_stock_card_dengan_nilai_eksak()
    {
        $correction = $this->createAction->execute($this->baseData(15000));
        $this->approveAction->execute($correction);

        $card = StockCard::where('transaction_id', $correction->id)
            ->where('transaction_type', 'HPP_CORRECTION')->first();

        $this->assertNotNull($card);
        $this->assertNull($card->warehouse_id);
        $this->assertEquals(0, $card->qty_in);
        $this->assertEquals(0, $card->qty_out);
        $this->assertEquals(10000.0, (float) $card->avg_cost_before);
        $this->assertEquals(15000.0, (float) $card->avg_cost_after);
        $this->assertEquals(15000.0, (float) $card->cost_per_unit);

        // Label alasan masuk ke notes stock_card (KOREKSI_HARGA_BELI -> 'Koreksi Harga Beli')
        $this->assertStringContainsString('Koreksi Harga Beli', (string) $card->notes);

        // qty fisik gudang TIDAK berubah (hanya nilai HPP)
        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(50, $stock->qty);

        // Invariant stok tetap konsisten setelah koreksi HPP
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * hpp_lama di-capture ulang pada saat APPROVE (bukan saat draft):
     * jika avg_cost produk berubah setelah draft dibuat, avg_cost_before di stock_card
     * dan hpp_lama detail mengikuti nilai TERKINI saat approve.
     *
     */
    #[Test]
    public function approve_hpp_correction_capture_ulang_hpp_lama_saat_approve()
    {
        // Draft dibuat saat avg_cost = 10000 (capture awal 10000)
        $correction = $this->createAction->execute($this->baseData(20000));
        $this->assertEquals(10000, $correction->details->first()->hpp_lama);

        // avg_cost berubah jadi 13000 SEBELUM approve (mis. ada pembelian baru)
        $this->product->update(['avg_cost' => 13000]);

        $this->approveAction->execute($correction->fresh());

        // hpp_lama detail di-update ke 13000 (nilai terkini saat approve)
        $this->assertEquals(13000, $correction->fresh()->details->first()->hpp_lama);

        // stock_card avg_cost_before = 13000, avg_cost_after = 20000
        $card = StockCard::where('transaction_id', $correction->id)
            ->where('transaction_type', 'HPP_CORRECTION')->first();
        $this->assertEquals(13000.0, (float) $card->avg_cost_before);
        $this->assertEquals(20000.0, (float) $card->avg_cost_after);

        // avg_cost final = hpp_baru
        $this->assertEquals(20000, $this->product->fresh()->avg_cost);
    }

    /**
     * Koreksi HPP ke 0 diterapkan eksak (avg_cost jadi 0) dan tersinkron ke inventory_stock.
     *
     */
    #[Test]
    public function approve_hpp_correction_dapat_set_hpp_menjadi_nol()
    {
        $correction = $this->createAction->execute($this->baseData(0));
        $this->approveAction->execute($correction);

        $this->assertEquals(0, $this->product->fresh()->avg_cost);

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(0.0, (float) $stock->avg_cost);
        $this->assertEquals(50, $stock->qty, 'qty tidak berubah');

        $card = StockCard::where('transaction_id', $correction->id)
            ->where('transaction_type', 'HPP_CORRECTION')->first();
        $this->assertEquals(10000.0, (float) $card->avg_cost_before);
        $this->assertEquals(0.0, (float) $card->avg_cost_after);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Approve hanya sekali: approve ke-2 ditolak dan TIDAK membuat stock_card kedua
     * maupun mengubah avg_cost lagi.
     *
     */
    #[Test]
    public function approve_hpp_correction_hanya_sekali_tanpa_efek_ganda()
    {
        $correction = $this->createAction->execute($this->baseData(15000));
        $this->approveAction->execute($correction);

        try {
            $this->approveAction->execute($correction->fresh());
            $this->fail('Approve kedua seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Tetap 1 stock_card HPP_CORRECTION, avg_cost tetap 15000 (tidak terdobel)
        $this->assertEquals(1, StockCard::where('transaction_id', $correction->id)
            ->where('transaction_type', 'HPP_CORRECTION')->count());
        $this->assertEquals(15000, $this->product->fresh()->avg_cost);
    }
}
