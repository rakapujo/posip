<?php

namespace Tests\Feature\Repack;

use App\Actions\Repack\ApproveRepackAction;
use App\Actions\Repack\CreateRepackAction;
use App\Actions\Repack\UpdateRepackAction;
use App\Models\DocRepack;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class RepackCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterWarehouse $warehouse;
    protected MasterProduk $inputProduct;
    protected MasterProduk $outputProduct;
    protected CreateRepackAction $createAction;
    protected UpdateRepackAction $updateAction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        // Input product: KARTON (bahan)
        $this->inputProduct = MasterProduk::factory()->create([
            'nama_produk' => 'Karton Source',
            'avg_cost' => 12000,
            'status' => 'active',
        ]);

        // Output product: PCS (hasil)
        $this->outputProduct = MasterProduk::factory()->create([
            'nama_produk' => 'PCS Result',
            'avg_cost' => 0,
            'status' => 'active',
        ]);

        // Initial stock: 10 karton input, 0 pcs output.
        // Catat juga stock_card PURCHASE padanan supaya invariant
        // SUM(stock_card.qty_in - qty_out) === inventory_stock.qty terpenuhi
        // (syarat agar `data:verify` lulus setelah mutasi repack).
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->inputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 10, 'avg_cost' => 12000]
        );
        StockCard::record([
            'product_id' => $this->inputProduct->id, 'warehouse_id' => $this->warehouse->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => '2026-04-01',
            'qty_in' => 10, 'qty_out' => 0, 'cost_per_unit' => 12000,
        ]);
        InventoryStock::updateOrCreate(
            ['product_id' => $this->outputProduct->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        StockCard::$skipObserver = false;

        $this->createAction = new CreateRepackAction();
        $this->updateAction = new UpdateRepackAction();
    }

    private function baseData(array $overrides = []): array
    {
        return array_merge([
            'warehouse_id' => $this->warehouse->id,
            'tipe' => 'pecah',
            'tanggal' => '2026-04-12',
            'biaya_repack' => 5000,
            'notes' => 'Test repack',
            'inputs' => [
                ['product_id' => $this->inputProduct->id, 'qty' => 2],
            ],
            'outputs' => [
                ['product_id' => $this->outputProduct->id, 'qty' => 24],
            ],
        ], $overrides);
    }
    #[Test]
    public function create_repack_has_draft_status_and_stores_inputs_outputs()
    {
        $repack = $this->createAction->execute($this->baseData());

        $this->assertEquals('draft', $repack->status);
        $this->assertEquals('pecah', $repack->tipe);
        $this->assertStringStartsWith('RPK-', $repack->nomor_dokumen);
        $this->assertEquals(5000, $repack->biaya_repack);
        $this->assertEquals(1, $repack->inputs->count());
        $this->assertEquals(1, $repack->outputs->count());
        $this->assertEquals(2, $repack->inputs->first()->qty);
        $this->assertEquals(24, $repack->outputs->first()->qty);
    }
    #[Test]
    public function create_repack_does_not_change_inventory_stock()
    {
        $this->createAction->execute($this->baseData());

        $inputStock = InventoryStock::where('product_id', $this->inputProduct->id)->first();
        $outputStock = InventoryStock::where('product_id', $this->outputProduct->id)->first();

        $this->assertEquals(10, $inputStock->qty, 'Input stock unchanged on create');
        $this->assertEquals(0, $outputStock->qty, 'Output stock unchanged on create');
    }
    #[Test]
    public function create_repack_with_gabung_type()
    {
        $repack = $this->createAction->execute($this->baseData([
            'tipe' => 'gabung',
        ]));

        $this->assertEquals('gabung', $repack->tipe);
    }
    #[Test]
    public function create_repack_cost_per_unit_zero_until_approve()
    {
        $repack = $this->createAction->execute($this->baseData());

        // cost_per_unit & total_cost placeholder as zero in create; calculated on approve
        $this->assertEquals(0, $repack->inputs->first()->cost_per_unit);
        $this->assertEquals(0, $repack->outputs->first()->cost_per_unit);
        $this->assertEquals(0, $repack->total_cost_input);
        $this->assertEquals(0, $repack->total_cost_output);
    }
    #[Test]
    public function update_repack_on_draft_replaces_inputs_and_outputs()
    {
        $repack = $this->createAction->execute($this->baseData());

        $updated = $this->updateAction->execute($repack->fresh(), $this->baseData([
            'biaya_repack' => 8000,
            'inputs' => [
                ['product_id' => $this->inputProduct->id, 'qty' => 3],
            ],
            'outputs' => [
                ['product_id' => $this->outputProduct->id, 'qty' => 36],
            ],
        ]));

        $this->assertEquals(8000, $updated->biaya_repack);
        $this->assertEquals(1, $updated->inputs->count());
        $this->assertEquals(3, $updated->inputs->first()->qty);
        $this->assertEquals(36, $updated->outputs->first()->qty);
    }
    #[Test]
    public function update_repack_throws_when_already_approved()
    {
        $repack = $this->createAction->execute($this->baseData());

        (new ApproveRepackAction())->execute($repack);

        $this->expectException(ValidationException::class);
        $this->updateAction->execute($repack->fresh(), $this->baseData());
    }

    // ==================== EDGE CASE: KONSISTENSI STOK & NILAI ====================

    /**
     * Approve repack: bahan keluar (REPACK_OUT) dan hasil masuk (REPACK_IN) WAJIB
     * konsisten — stok bahan berkurang persis, stok hasil bertambah persis, dan
     * tiap mutasi inventory punya entry stock_card padanan (qty cocok).
     *
     */
    #[Test]
    public function approve_repack_mengubah_stok_bahan_dan_hasil_secara_eksak()
    {
        // Awal: bahan 10, hasil 0. Pakai 2 bahan -> 24 hasil.
        $repack = $this->createAction->execute($this->baseData());

        $approved = (new ApproveRepackAction())->execute($repack);
        $this->assertEquals('approved', $approved->status);

        // Stok bahan: 10 - 2 = 8 (eksak)
        $inputStock = InventoryStock::where('product_id', $this->inputProduct->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(8, $inputStock->qty);

        // Stok hasil: 0 + 24 = 24 (eksak)
        $outputStock = InventoryStock::where('product_id', $this->outputProduct->id)
            ->where('warehouse_id', $this->warehouse->id)->first();
        $this->assertEquals(24, $outputStock->qty);

        // REPACK_OUT: qty_out tepat 2, qty_in 0
        $outCard = StockCard::where('product_id', $this->inputProduct->id)
            ->where('transaction_type', 'REPACK_OUT')->first();
        $this->assertNotNull($outCard);
        $this->assertEquals(2, $outCard->qty_out);
        $this->assertEquals(0, $outCard->qty_in);
        $this->assertEquals($repack->id, $outCard->transaction_id);

        // REPACK_IN: qty_in tepat 24, qty_out 0
        $inCard = StockCard::where('product_id', $this->outputProduct->id)
            ->where('transaction_type', 'REPACK_IN')->first();
        $this->assertNotNull($inCard);
        $this->assertEquals(24, $inCard->qty_in);
        $this->assertEquals(0, $inCard->qty_out);

        // Invariant stok global: data:verify HARUS 0 (tidak ada mismatch)
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * HPP hasil dihitung dari nilai bahan + biaya_repack, dan nilai total kekal:
     * total_cost_output === total_cost_input + biaya_repack.
     *
     */
    #[Test]
    public function approve_repack_menghitung_hpp_hasil_dari_bahan_plus_biaya_dan_nilai_kekal()
    {
        // 2 bahan @ avg_cost 12000 = 24000 ; biaya 5000 ; total output value = 29000
        // cost_per_unit hasil = 29000 / 24 ≈ 1208.333...
        $repack = $this->createAction->execute($this->baseData());
        $approved = (new ApproveRepackAction())->execute($repack)->fresh();

        $expectedInputValue = 2 * 12000.0;            // 24000
        $expectedOutputValue = $expectedInputValue + 5000.0; // 29000
        $expectedOutputCpu = $expectedOutputValue / 24;       // 1208.3333

        $this->assertEquals($expectedInputValue, (float) $approved->total_cost_input);
        $this->assertEquals($expectedOutputValue, (float) $approved->total_cost_output);

        // Nilai kekal: output value = input value + biaya repack
        $this->assertEquals(
            (float) $approved->total_cost_input + (float) $approved->biaya_repack,
            (float) $approved->total_cost_output
        );

        // cost_per_unit input = snapshot avg_cost bahan (12000)
        $this->assertEquals(12000.0, (float) $approved->inputs->first()->cost_per_unit);

        // cost_per_unit output dihitung dari nilai bahan + biaya
        $this->assertEqualsWithDelta($expectedOutputCpu, (float) $approved->outputs->first()->cost_per_unit, 0.0001);

        // avg_cost produk hasil (mulai dari 0 stok) = cost_per_unit hasil
        $this->outputProduct->refresh();
        $this->assertEqualsWithDelta($expectedOutputCpu, (float) $this->outputProduct->avg_cost, 0.0001);
    }

    /**
     * Biaya repack didistribusikan proporsional terhadap qty antar beberapa output.
     *
     */
    #[Test]
    public function approve_repack_mendistribusikan_biaya_proporsional_ke_banyak_output()
    {
        $output2 = MasterProduk::factory()->create([
            'nama_produk' => 'PCS Result 2', 'avg_cost' => 0, 'status' => 'active',
        ]);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $output2->id, 'warehouse_id' => $this->warehouse->id],
            ['qty' => 0, 'avg_cost' => 0]
        );
        StockCard::$skipObserver = false;

        // 2 bahan @12000 = 24000 + biaya 6000 = 30000 total output value
        // output A 10 pcs, output B 20 pcs -> total 30 pcs
        // cost_per_unit kedua output sama = 30000/30 = 1000
        $repack = $this->createAction->execute($this->baseData([
            'biaya_repack' => 6000,
            'outputs' => [
                ['product_id' => $this->outputProduct->id, 'qty' => 10],
                ['product_id' => $output2->id, 'qty' => 20],
            ],
        ]));
        $approved = (new ApproveRepackAction())->execute($repack)->fresh();

        $this->assertEquals(30000.0, (float) $approved->total_cost_output);

        $outA = $approved->outputs->firstWhere('product_id', $this->outputProduct->id);
        $outB = $approved->outputs->firstWhere('product_id', $output2->id);

        // cost_per_unit sama (1000), total_cost proporsional ke qty
        $this->assertEqualsWithDelta(1000.0, (float) $outA->cost_per_unit, 0.0001);
        $this->assertEqualsWithDelta(1000.0, (float) $outB->cost_per_unit, 0.0001);
        $this->assertEqualsWithDelta(10000.0, (float) $outA->total_cost, 0.0001);
        $this->assertEqualsWithDelta(20000.0, (float) $outB->total_cost, 0.0001);

        // Jumlah total_cost per-output kembali ke total_cost_output (nilai kekal)
        $this->assertEqualsWithDelta(
            (float) $approved->total_cost_output,
            (float) $outA->total_cost + (float) $outB->total_cost,
            0.0001
        );

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Stok bahan kurang -> approve diblok (negative stock tidak diizinkan default),
     * dan TIDAK ada mutasi stok yang terjadi.
     *
     */
    #[Test]
    public function approve_repack_diblok_ketika_stok_bahan_tidak_cukup()
    {
        SettingService::set('stock.negative_mode', 'block', 'string');

        // Stok bahan hanya 10, minta 999
        $repack = $this->createAction->execute($this->baseData([
            'inputs' => [['product_id' => $this->inputProduct->id, 'qty' => 999]],
            'outputs' => [['product_id' => $this->outputProduct->id, 'qty' => 24]],
        ]));

        try {
            (new ApproveRepackAction())->execute($repack);
            $this->fail('Approve seharusnya gagal karena stok bahan tidak cukup.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('stock', $e->errors());
        }

        // Status tetap draft, stok tidak berubah, tidak ada stock_card repack
        $this->assertEquals('draft', $repack->fresh()->status);
        $this->assertEquals(10, InventoryStock::where('product_id', $this->inputProduct->id)->first()->qty);
        $this->assertEquals(0, InventoryStock::where('product_id', $this->outputProduct->id)->first()->qty);
        $this->assertEquals(0, StockCard::whereIn('transaction_type', ['REPACK_OUT', 'REPACK_IN'])->count());
    }

    /**
     * Approve hanya boleh sekali: approve ke-2 ditolak dan tidak menggandakan stok.
     *
     */
    #[Test]
    public function approve_repack_hanya_sekali_dan_tidak_menggandakan_stok()
    {
        $repack = $this->createAction->execute($this->baseData());
        (new ApproveRepackAction())->execute($repack);

        // Snapshot stok setelah approve pertama
        $inputAfter = InventoryStock::where('product_id', $this->inputProduct->id)->first()->qty;
        $outputAfter = InventoryStock::where('product_id', $this->outputProduct->id)->first()->qty;
        $this->assertEquals(8, $inputAfter);
        $this->assertEquals(24, $outputAfter);

        // Approve kedua -> ValidationException
        try {
            (new ApproveRepackAction())->execute($repack->fresh());
            $this->fail('Approve kedua seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Stok TIDAK berubah (tidak ada mutasi ganda)
        $this->assertEquals(8, InventoryStock::where('product_id', $this->inputProduct->id)->first()->qty);
        $this->assertEquals(24, InventoryStock::where('product_id', $this->outputProduct->id)->first()->qty);
        $this->assertEquals(1, StockCard::where('transaction_type', 'REPACK_OUT')->count());
        $this->assertEquals(1, StockCard::where('transaction_type', 'REPACK_IN')->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
}
