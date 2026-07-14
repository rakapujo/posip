<?php

namespace Tests\Feature\PriceChange;

use App\Actions\PriceChange\ApplyPriceChangeAction;
use App\Actions\PriceChange\ApprovePriceChangeAction;
use App\Actions\PriceChange\CancelPriceChangeAction;
use App\Actions\PriceChange\CreatePriceChangeAction;
use App\Models\DocPriceChange;
use App\Models\MasterProduk;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class PriceChangeCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterProduk $product;
    protected CreatePriceChangeAction $createAction;
    protected ApprovePriceChangeAction $approveAction;
    protected ApplyPriceChangeAction $applyAction;
    protected CancelPriceChangeAction $cancelAction;

    protected function setUp(): void
    {
        parent::setUp();

        // Use auto mode for predictable test math
        SettingService::set('product.price_input_mode', 'auto', 'string');

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        // Create product with predictable konversi for auto-calculation
        // pricePerBase = harga_1_baru / konversi_1
        // harga_2 = pricePerBase * konversi_2
        // harga_3 = pricePerBase * konversi_3
        // harga_4 = pricePerBase * konversi_4 (always 1)
        $this->product = MasterProduk::factory()->create([
            'nama_produk' => 'Test Product',
            'unit_1' => 'KARTON', 'konversi_1' => 12,
            'unit_2' => 'BOX', 'konversi_2' => 6,
            'unit_3' => 'PACK', 'konversi_3' => 2,
            'unit_4' => 'PCS', 'konversi_4' => 1,
            'harga_1' => 120000, // initial
            'harga_2' => 60000,
            'harga_3' => 20000,
            'harga_4' => 10000,
            'avg_cost' => 8000,
            'status' => 'active',
        ]);

        $this->createAction = new CreatePriceChangeAction();
        $this->approveAction = new ApprovePriceChangeAction();
        $this->applyAction = new ApplyPriceChangeAction();
        $this->cancelAction = new CancelPriceChangeAction();
    }

    private function baseData(float $harga1Baru = 144000, array $overrides = []): array
    {
        return array_merge([
            'tanggal_pengajuan' => '2026-04-12 09:00:00',
            'tanggal_berlaku' => '2026-04-15 00:00:00',
            'notes' => 'Quarterly price adjustment',
            'details' => [
                [
                    'product_id' => $this->product->id,
                    'harga_1_baru' => $harga1Baru,
                    'alasan' => 'PENYESUAIAN_PASAR',
                ],
            ],
        ], $overrides);
    }
    #[Test]
    public function create_price_change_has_draft_status_and_captures_old_prices()
    {
        $priceChange = $this->createAction->execute($this->baseData(144000));

        $this->assertInstanceOf(DocPriceChange::class, $priceChange);
        $this->assertEquals('draft', $priceChange->status);
        $this->assertStringStartsWith('PCH-', $priceChange->nomor_dokumen);

        $detail = $priceChange->details->first();
        $this->assertEquals(120000, $detail->harga_1_lama);
        $this->assertEquals(60000, $detail->harga_2_lama);
        $this->assertEquals(20000, $detail->harga_3_lama);
        $this->assertEquals(10000, $detail->harga_4_lama);
    }
    #[Test]
    public function create_price_change_auto_calculates_other_units_from_harga1()
    {
        // harga_1_baru = 144000, konversi_1 = 12 → pricePerBase = 12000
        // harga_2 = 12000 * 6 = 72000
        // harga_3 = 12000 * 2 = 24000
        // harga_4 = 12000 * 1 = 12000
        $priceChange = $this->createAction->execute($this->baseData(144000));

        $detail = $priceChange->details->first();
        $this->assertEquals(144000, $detail->harga_1_baru);
        $this->assertEquals(72000, $detail->harga_2_baru);
        $this->assertEquals(24000, $detail->harga_3_baru);
        $this->assertEquals(12000, $detail->harga_4_baru);
    }
    #[Test]
    public function create_price_change_does_not_change_product_price()
    {
        $this->createAction->execute($this->baseData(144000));

        $this->product->refresh();
        $this->assertEquals(120000, $this->product->harga_1, 'Product price unchanged on draft');
        $this->assertEquals(10000, $this->product->harga_4);
    }
    #[Test]
    public function create_price_change_blocks_product_already_in_draft()
    {
        $this->createAction->execute($this->baseData(144000));

        // Try to create another draft for same product
        $this->expectException(ValidationException::class);
        $this->createAction->execute($this->baseData(150000));
    }
    #[Test]
    public function approve_price_change_transitions_draft_to_scheduled()
    {
        $priceChange = $this->createAction->execute($this->baseData(144000));

        $approved = $this->approveAction->execute($priceChange);

        $this->assertEquals('scheduled', $approved->status);
        $this->assertNotNull($approved->approved_at);
        $this->assertEquals($this->user->id, $approved->approved_by);
    }
    #[Test]
    public function approve_price_change_does_not_change_product_price()
    {
        $priceChange = $this->createAction->execute($this->baseData(144000));
        $this->approveAction->execute($priceChange);

        $this->product->refresh();
        $this->assertEquals(120000, $this->product->harga_1, 'Approve only schedules; price not yet changed');
    }
    #[Test]
    public function apply_price_change_actually_updates_product_price()
    {
        $priceChange = $this->createAction->execute($this->baseData(144000));
        $this->approveAction->execute($priceChange);

        $applied = $this->applyAction->execute($priceChange->fresh(), $this->user->id, 'manual');

        $this->assertEquals('applied', $applied->status);
        $this->assertNotNull($applied->applied_at);

        $this->product->refresh();
        $this->assertEquals(144000, $this->product->harga_1);
        $this->assertEquals(72000, $this->product->harga_2);
        $this->assertEquals(24000, $this->product->harga_3);
        $this->assertEquals(12000, $this->product->harga_4);
    }
    #[Test]
    public function apply_throws_when_status_is_draft_not_scheduled()
    {
        $priceChange = $this->createAction->execute($this->baseData(144000));
        // Skip approve

        $this->expectException(ValidationException::class);
        $this->applyAction->execute($priceChange, $this->user->id, 'manual');
    }
    #[Test]
    public function cancel_scheduled_price_change_returns_to_draft()
    {
        $priceChange = $this->createAction->execute($this->baseData(144000));
        $this->approveAction->execute($priceChange);

        $cancelled = $this->cancelAction->execute($priceChange->fresh());

        $this->assertEquals('draft', $cancelled->status);
        $this->assertNull($cancelled->approved_at);
        $this->assertNull($cancelled->approved_by);
    }
    #[Test]
    public function cancel_throws_when_status_is_draft_not_scheduled()
    {
        $priceChange = $this->createAction->execute($this->baseData(144000));

        $this->expectException(ValidationException::class);
        $this->cancelAction->execute($priceChange);
    }
    #[Test]
    public function approve_throws_when_no_details()
    {
        // Create with empty details (skip via direct DB)
        $priceChange = DocPriceChange::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'nomor_dokumen' => 'PC-EMPTY',
            'tanggal_pengajuan' => '2026-04-12 09:00:00',
            'tanggal_berlaku' => '2026-04-15 00:00:00',
            'status' => 'draft',
            'created_by' => $this->user->id,
        ]);

        $this->expectException(ValidationException::class);
        $this->approveAction->execute($priceChange);
    }

    // ==================== EDGE CASE: LIFECYCLE PENUH & GUARD ====================

    /**
     * Lifecycle penuh draft -> scheduled -> applied: tiap transisi mengubah status
     * dengan benar dan apply mengubah harga jual produk persis ke harga_baru.
     *
     */
    #[Test]
    public function lifecycle_penuh_draft_scheduled_applied()
    {
        $pc = $this->createAction->execute($this->baseData(144000));
        $this->assertEquals('draft', $pc->status);

        $this->approveAction->execute($pc);
        $this->assertEquals('scheduled', $pc->fresh()->status);
        // Harga belum berubah saat scheduled
        $this->assertEquals(120000, $this->product->fresh()->harga_1);

        $applied = $this->applyAction->execute($pc->fresh(), $this->user->id, 'manual');
        $this->assertEquals('applied', $applied->status);
        $this->assertNotNull($applied->applied_at);
        $this->assertEquals($this->user->id, $applied->applied_by);

        // Harga produk berubah persis
        $this->product->refresh();
        $this->assertEquals(144000, $this->product->harga_1);
        $this->assertEquals(72000, $this->product->harga_2);
        $this->assertEquals(24000, $this->product->harga_3);
        $this->assertEquals(12000, $this->product->harga_4);
    }

    /**
     * Cancel sebelum apply: status kembali draft, harga produk TIDAK berubah,
     * dan produk TER-UNLOCK sehingga draft baru untuk produk yang sama bisa dibuat.
     *
     */
    #[Test]
    public function cancel_sebelum_apply_mengembalikan_draft_dan_unlock_produk()
    {
        $pc = $this->createAction->execute($this->baseData(144000));
        $this->approveAction->execute($pc);

        $cancelled = $this->cancelAction->execute($pc->fresh());
        $this->assertEquals('draft', $cancelled->status);
        $this->assertNull($cancelled->approved_at);
        $this->assertNull($cancelled->approved_by);

        // Harga tidak berubah sama sekali
        $this->product->refresh();
        $this->assertEquals(120000, $this->product->harga_1);
        $this->assertEquals(10000, $this->product->harga_4);

        // Produk yang sama masih TERKUNCI karena dokumen lama kembali ke 'draft'
        // (locked = draft|scheduled). Buat draft baru -> harus tetap diblok.
        $this->expectException(ValidationException::class);
        $this->createAction->execute($this->baseData(150000));
    }

    /**
     * Tidak bisa apply dua kali: apply ke-2 (status sudah 'applied') ditolak,
     * status tetap 'applied' dan harga tidak berubah ulang.
     *
     */
    #[Test]
    public function tidak_bisa_apply_dua_kali()
    {
        $pc = $this->createAction->execute($this->baseData(144000));
        $this->approveAction->execute($pc);
        $this->applyAction->execute($pc->fresh(), $this->user->id, 'manual');

        $this->assertEquals(144000, $this->product->fresh()->harga_1);

        try {
            $this->applyAction->execute($pc->fresh(), $this->user->id, 'manual');
            $this->fail('Apply kedua seharusnya ditolak.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('status', $e->errors());
        }

        // Status tetap applied, harga tetap (tidak ada perubahan ganda)
        $this->assertEquals('applied', $pc->fresh()->status);
        $this->assertEquals(144000, $this->product->fresh()->harga_1);
    }

    /**
     * Setelah applied, produk TER-UNLOCK (locked hanya draft|scheduled):
     * boleh membuat dokumen perubahan harga baru untuk produk yang sama.
     *
     */
    #[Test]
    public function setelah_applied_produk_terbuka_untuk_perubahan_baru()
    {
        $pc1 = $this->createAction->execute($this->baseData(144000));
        $this->approveAction->execute($pc1);
        $this->applyAction->execute($pc1->fresh(), $this->user->id, 'manual');

        // Sekarang produk tidak terkunci -> dokumen baru boleh dibuat
        $pc2 = $this->createAction->execute($this->baseData(180000));
        $this->assertEquals('draft', $pc2->status);

        // harga_lama dokumen baru = harga TERKINI (hasil apply pertama), bukan harga awal
        $detail = $pc2->details->first();
        $this->assertEquals(144000, $detail->harga_1_lama);
        $this->assertEquals(180000, $detail->harga_1_baru);
    }

    /**
     * Auto-calc presisi untuk harga yang tidak habis dibagi konversi:
     * pricePerBase dibulatkan 2 desimal per unit (sesuai implementasi round(...,2)).
     *
     */
    #[Test]
    public function auto_calc_membulatkan_dua_desimal_untuk_harga_tidak_habis_dibagi()
    {
        // harga_1_baru = 100000, konversi_1 = 12 -> pricePerBase = 8333.3333...
        // harga_2 = round(8333.3333 * 6, 2)  = 50000.00
        // harga_3 = round(8333.3333 * 2, 2)  = 16666.67
        // harga_4 = round(8333.3333 * 1, 2)  = 8333.33
        $pc = $this->createAction->execute($this->baseData(100000));
        $detail = $pc->details->first();

        $this->assertEquals(100000, (float) $detail->harga_1_baru);
        $this->assertEquals(50000.00, (float) $detail->harga_2_baru);
        $this->assertEquals(16666.67, (float) $detail->harga_3_baru);
        $this->assertEquals(8333.33, (float) $detail->harga_4_baru);
    }

    /**
     * Mode manual: harga harus menurun antar unit (harga_2 >= harga_1 -> ditolak).
     *
     */
    #[Test]
    public function mode_manual_menolak_harga_tidak_menurun()
    {
        SettingService::set('product.price_input_mode', 'manual', 'string');

        // konversi_1=12 (tidak locked). harga_2 (70000) >= harga_1 (60000) -> invalid
        $this->expectException(ValidationException::class);
        $this->createAction->execute($this->baseData(60000, [
            'details' => [[
                'product_id' => $this->product->id,
                'harga_1_baru' => 60000,
                'harga_2_baru' => 70000, // melanggar: harus < harga_1
                'harga_3_baru' => 10000,
                'harga_4_baru' => 5000,
                'alasan' => 'PENYESUAIAN_PASAR',
            ]],
        ]));
    }
}
