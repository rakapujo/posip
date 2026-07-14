<?php

namespace Tests\Feature\Adjustment;

use App\Actions\Adjustment\ApproveAdjustmentAction;
use App\Actions\Adjustment\CreateAdjustmentAction;
use App\Actions\Adjustment\UpdateAdjustmentAction;
use App\Models\DocAdjustment;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class AdjustmentCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterProduk $product;
    protected CreateAdjustmentAction $createAction;
    protected UpdateAdjustmentAction $updateAction;

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

        // Initial stock = 50 + kartu PURCHASE padanan agar invariant stock_card konsisten
        // (SUM(qty_in - qty_out) === inventory_stock.qty), supaya data:verify lulus.
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

        $this->createAction = new CreateAdjustmentAction();
        $this->updateAction = new UpdateAdjustmentAction();
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->warehouse->id,
            'tanggal' => '2026-04-12',
            'keterangan' => 'Test adjustment',
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'jenis' => 'debit', // IN
                    'qty' => 10,
                ],
            ],
        ], $overrides);
    }
    #[Test]
    public function create_adjustment_has_draft_status_and_captures_current_stock()
    {
        $adjustment = $this->createAction->execute($this->baseData());

        $this->assertEquals('draft', $adjustment->status);
        $this->assertStringStartsWith('ADJ-', $adjustment->nomor_dokumen);
        $this->assertEquals(1, $adjustment->details->count());

        $detail = $adjustment->details->first();
        $this->assertEquals(50, $detail->stok_sistem, 'stok_sistem should capture current stock');
        $this->assertEquals(10, $detail->qty);
        $this->assertEquals(60, $detail->stok_akhir, 'debit: 50 + 10 = 60');
    }
    #[Test]
    public function create_adjustment_kredit_calculates_stok_akhir_as_subtraction()
    {
        $adjustment = $this->createAction->execute($this->baseData([
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'jenis' => 'kredit', // OUT
                    'qty' => 15,
                ],
            ],
        ]));

        $detail = $adjustment->details->first();
        $this->assertEquals('kredit', $detail->jenis);
        $this->assertEquals(50, $detail->stok_sistem);
        $this->assertEquals(35, $detail->stok_akhir, 'kredit: 50 - 15 = 35');
    }
    #[Test]
    public function create_adjustment_does_not_change_inventory_stock()
    {
        $this->createAction->execute($this->baseData());

        $stock = InventoryStock::where('product_id', $this->product->id)->first();
        $this->assertEquals(50, $stock->qty, 'Stock should remain unchanged until approval');
    }
    #[Test]
    public function update_adjustment_on_draft_replaces_details()
    {
        $adjustment = $this->createAction->execute($this->baseData());
        $originalDetailId = $adjustment->details->first()->id;

        $updated = $this->updateAction->execute($adjustment->fresh(), $this->baseData([
            'keterangan' => 'Updated keterangan',
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'jenis' => 'kredit',
                    'qty' => 5,
                ],
            ],
        ]));

        $this->assertEquals('Updated keterangan', $updated->keterangan);
        $this->assertEquals(1, $updated->details->count());

        $newDetail = $updated->details->first();
        $this->assertNotEquals($originalDetailId, $newDetail->id, 'Old detail should be deleted, new one created');
        $this->assertEquals('kredit', $newDetail->jenis);
        $this->assertEquals(5, $newDetail->qty);
    }
    #[Test]
    public function update_adjustment_throws_when_already_approved()
    {
        $adjustment = $this->createAction->execute($this->baseData());

        // Approve the adjustment first
        (new ApproveAdjustmentAction())->execute($adjustment);

        // Try to update approved adjustment
        $this->expectException(ValidationException::class);
        $this->updateAction->execute($adjustment->fresh(), $this->baseData());
    }

    // ===================== EDGE CASE TAMBAHAN (galak) =====================

    /**
     * Approve debit (ADJUSTMENT_IN): stok bertambah eksak + stock_card ADJUSTMENT_IN.
     * HPP direkalkulasi (§2B), tapi karena cost masuk == avg lama (10000),
     * weighted-average tetap 10000. data:verify wajib 0.
     */
    #[Test]
    public function approve_debit_menambah_stok_dan_buat_stock_card_adjustment_in()
    {
        $adjustment = $this->createAction->execute($this->baseData()); // debit qty 10

        (new ApproveAdjustmentAction())->execute($adjustment);

        // Status approved eksak
        $this->assertSame('approved', $adjustment->fresh()->status);

        // Stok 50 + 10 = 60 eksak
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertSame(60, (int) $stock->qty);

        // Tepat satu stock_card ADJUSTMENT_IN dengan qty_in 10, qty_out 0
        $cards = StockCard::where('transaction_id', $adjustment->id)
            ->where('transaction_type', 'ADJUSTMENT_IN')->get();
        $this->assertCount(1, $cards);
        $card = $cards->first();
        $this->assertSame(10.0, (float) $card->qty_in);
        $this->assertSame(0.0, (float) $card->qty_out);

        // HPP recalc weighted-average: (50*10000 + 10*10000)/60 = 10000 (tidak berubah krn cost sama)
        $this->assertSame(10000.0, (float) $this->product->fresh()->avg_cost);
        $this->assertSame(10000.0, (float) $card->avg_cost_before);
        $this->assertSame(10000.0, (float) $card->avg_cost_after);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Approve kredit (ADJUSTMENT_OUT): stok berkurang eksak + stock_card ADJUSTMENT_OUT.
     * HPP TIDAK direkalkulasi (avg_before == avg_after). data:verify wajib 0.
     */
    #[Test]
    public function approve_kredit_mengurangi_stok_dan_hpp_tidak_berubah()
    {
        $adjustment = $this->createAction->execute($this->baseData([
            'details' => [
                ['product_id' => $this->product->id, 'jenis' => 'kredit', 'qty' => 15],
            ],
        ]));

        (new ApproveAdjustmentAction())->execute($adjustment);

        // Stok 50 - 15 = 35 eksak
        $stock = InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)
            ->first();
        $this->assertSame(35, (int) $stock->qty);

        $card = StockCard::where('transaction_id', $adjustment->id)
            ->where('transaction_type', 'ADJUSTMENT_OUT')->first();
        $this->assertNotNull($card);
        $this->assertSame(0.0, (float) $card->qty_in);
        $this->assertSame(15.0, (float) $card->qty_out);

        // HPP tidak berubah (kredit by design tidak recalc)
        $this->assertSame(10000.0, (float) $this->product->fresh()->avg_cost);
        $this->assertSame((float) $card->avg_cost_before, (float) $card->avg_cost_after);
        $this->assertSame(10000.0, (float) $card->avg_cost_after);

        // Tidak ada HPP_RESET (stok global masih 35)
        $this->assertSame(0, StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Kredit melebihi stok diblok saat setting stock.negative_mode = block.
     * Stok TIDAK boleh berubah & tak ada stock_card yang terbentuk.
     */
    #[Test]
    public function approve_kredit_melebihi_stok_diblok_saat_setting_block()
    {
        SettingService::set('stock.negative_mode', 'block', 'string');

        // qty 60 > stok 50
        $adjustment = $this->createAction->execute($this->baseData([
            'details' => [
                ['product_id' => $this->product->id, 'jenis' => 'kredit', 'qty' => 60],
            ],
        ]));

        try {
            (new ApproveAdjustmentAction())->execute($adjustment);
            $this->fail('Approve seharusnya gagal karena stok tidak mencukupi (mode block).');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        // Stok tetap 50, status tetap draft, tak ada stock_card adjustment
        $this->assertSame(50, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertSame('draft', $adjustment->fresh()->status);
        $this->assertSame(0, StockCard::where('transaction_id', $adjustment->id)->count());
    }

    /**
     * Saat negative_mode = allow, kredit melebihi stok DIIZINKAN → stok negatif.
     * data:verify tetap 0 (stock_card padanan terbentuk).
     */
    #[Test]
    public function approve_kredit_melebihi_stok_diizinkan_saat_setting_allow()
    {
        SettingService::set('stock.negative_mode', 'allow', 'string');

        $adjustment = $this->createAction->execute($this->baseData([
            'details' => [
                ['product_id' => $this->product->id, 'jenis' => 'kredit', 'qty' => 60],
            ],
        ]));

        (new ApproveAdjustmentAction())->execute($adjustment);

        // 50 - 60 = -10
        $this->assertSame(-10, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertSame('approved', $adjustment->fresh()->status);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Approve hanya boleh sekali. Approve kedua melempar ValidationException
     * dan TIDAK menggandakan stock_card (idempotensi status).
     */
    #[Test]
    public function approve_kedua_kali_ditolak_dan_tidak_gandakan_stock_card()
    {
        $adjustment = $this->createAction->execute($this->baseData()); // debit 10
        (new ApproveAdjustmentAction())->execute($adjustment);

        $cardsAfterFirst = StockCard::where('transaction_id', $adjustment->id)->count();
        $this->assertSame(1, $cardsAfterFirst);

        try {
            (new ApproveAdjustmentAction())->execute($adjustment->fresh());
            $this->fail('Approve kedua seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Stok tetap 60, stock_card tetap 1
        $this->assertSame(60, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertSame(1, StockCard::where('transaction_id', $adjustment->id)->count());
    }

    /**
     * Dua produk berbeda dalam satu adjustment (debit + kredit) diproses eksak & independen.
     * Produk A (50) debit 10 → 60; Produk B (30) kredit 5 → 25. data:verify wajib 0.
     */
    #[Test]
    public function dua_produk_berbeda_diproses_independen_eksak()
    {
        $product2 = MasterProduk::factory()->create(['avg_cost' => 8000, 'status' => 'active']);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $product2->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 30, 'avg_cost' => 8000]
        );
        StockCard::record([
            'product_id' => $product2->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 30, 'qty_out' => 0, 'cost_per_unit' => 8000,
            'avg_cost_before' => 8000, 'avg_cost_after' => 8000,
        ]);
        StockCard::$skipObserver = false;

        $adjustment = $this->createAction->execute($this->baseData([
            'details' => [
                ['product_id' => $this->product->id, 'jenis' => 'debit', 'qty' => 10],
                ['product_id' => $product2->id, 'jenis' => 'kredit', 'qty' => 5],
            ],
        ]));

        (new ApproveAdjustmentAction())->execute($adjustment);

        $this->assertSame(60, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertSame(25, (int) InventoryStock::where('product_id', $product2->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));

        $this->assertSame(1, StockCard::where('transaction_id', $adjustment->id)
            ->where('transaction_type', 'ADJUSTMENT_IN')->count());
        $this->assertSame(1, StockCard::where('transaction_id', $adjustment->id)
            ->where('transaction_type', 'ADJUSTMENT_OUT')->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Kredit yang menghabiskan SELURUH stok global memicu HPP_RESET ke 0.
     */
    #[Test]
    public function kredit_menghabiskan_seluruh_stok_memicu_hpp_reset()
    {
        $adjustment = $this->createAction->execute($this->baseData([
            'details' => [
                ['product_id' => $this->product->id, 'jenis' => 'kredit', 'qty' => 50],
            ],
        ]));

        (new ApproveAdjustmentAction())->execute($adjustment);

        $this->assertSame(0, (int) InventoryStock::where('product_id', $this->product->id)
            ->where('warehouse_id', $this->warehouse->id)->value('qty'));
        $this->assertSame(0.0, (float) $this->product->fresh()->avg_cost);

        $reset = StockCard::where('product_id', $this->product->id)
            ->where('transaction_type', 'HPP_RESET')->first();
        $this->assertNotNull($reset);
        $this->assertSame(10000.0, (float) $reset->avg_cost_before);
        $this->assertSame(0.0, (float) $reset->avg_cost_after);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Duplikat produk dalam satu adjustment ditolak di layer controller (HTTP 422).
     */
    #[Test]
    public function duplikat_produk_dalam_satu_adjustment_ditolak_via_http()
    {
        foreach (['adjustment.create'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->user->givePermissionTo('adjustment.create');

        $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->warehouse->id,
            'tanggal' => '2026-04-12',
            'details' => [
                ['product_id' => $this->product->id, 'jenis' => 'debit', 'qty' => 1],
                ['product_id' => $this->product->id, 'jenis' => 'kredit', 'qty' => 1],
            ],
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['details']);

        // Tak ada dokumen tersimpan
        $this->assertSame(0, DocAdjustment::count());
    }
}
