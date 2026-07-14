<?php

namespace Tests\Feature\Serial;

use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 3 — Adjustment-keluar (kredit) untuk produk serial: unit terpilih jadi 'rusak',
 * valuasi stok pakai cost_per_unit unit, dicatat movement OUT. Debit serial ditolak.
 */
class SerialAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $serial;
    protected MasterWarehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['adjustment.view', 'adjustment.create', 'adjustment.approve', 'serial-intake.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::all());
        $this->actingAs($this->admin);

        $this->serial = MasterProduk::create([
            'kode_produk' => 'SER1', 'nama_produk' => 'iPhone Serial', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
        $this->wh = MasterWarehouse::create(['kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang 1', 'is_saleable' => true, 'status' => 'active']);
    }

    /** @return SerialUnit[] */
    private function seedUnits(int $count): array
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id],
            ['qty' => $count, 'avg_cost' => 1000]
        );
        StockCard::record([
            'product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => $count, 'qty_out' => 0, 'cost_per_unit' => 1500,
        ]);
        StockCard::$skipObserver = false;

        $units = [];
        for ($i = 1; $i <= $count; $i++) {
            $units[] = SerialUnit::create([
                'product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id,
                'serial_number' => "SN-{$i}", 'harga_modal' => 1000, 'cost_per_unit' => 1500, 'status' => 'tersedia',
            ]);
        }
        return $units;
    }
    #[Test]
    public function adjustment_out_serial_marks_rusak_and_records_movement()
    {
        $units = $this->seedUnits(3);
        $out = [$units[0]->ulid, $units[1]->ulid];

        $res = $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                ['product_id' => $this->serial->id, 'jenis' => 'kredit', 'qty' => 2, 'serial_unit_ids' => $out],
            ],
        ])->assertCreated();

        $ulid = $res->json('data.adjustment.ulid');
        $this->postJson("/api/v1/adjustments/{$ulid}/approve")->assertOk();

        $this->assertSame('rusak', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertSame('rusak', SerialUnit::where('ulid', $units[1]->ulid)->value('status'));
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[2]->ulid)->value('status'));

        $this->assertSame(1, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->wh->id)->value('qty'));

        // Movement OUT untuk tiap unit
        $this->assertSame(2, SerialUnitMovement::where('doc_type', 'ADJUSTMENT')->where('movement_type', 'OUT')->count());

        // Valuasi stock_card pakai cost_per_unit unit (1500), bukan avg_cost produk (0)
        $card = StockCard::where('transaction_type', 'ADJUSTMENT_OUT')->where('product_id', $this->serial->id)->first();
        $this->assertSame(1500.0, (float) $card->cost_per_unit);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function adjustment_out_serial_uses_per_unit_status()
    {
        $units = $this->seedUnits(3);

        $res = $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                [
                    'product_id' => $this->serial->id, 'jenis' => 'kredit', 'qty' => 2,
                    'serial_unit_ids' => [$units[0]->ulid, $units[1]->ulid],
                    // unit 0 → hilang, unit 1 tak disebut → default rusak
                    'serial_unit_statuses' => [$units[0]->ulid => 'hilang'],
                ],
            ],
        ])->assertCreated();

        $ulid = $res->json('data.adjustment.ulid');
        $this->postJson("/api/v1/adjustments/{$ulid}/approve")->assertOk();

        $this->assertSame('hilang', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertSame('rusak', SerialUnit::where('ulid', $units[1]->ulid)->value('status'));
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[2]->ulid)->value('status'));

        // Movement OUT mencatat to_status sesuai pilihan
        $this->assertSame('hilang', SerialUnitMovement::where('serial_unit_id', $units[0]->id)->where('movement_type', 'OUT')->value('to_status'));
        $this->assertSame('rusak', SerialUnitMovement::where('serial_unit_id', $units[1]->id)->where('movement_type', 'OUT')->value('to_status'));

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function adjustment_show_lists_serial_units_with_kode_internal_and_fate()
    {
        $units = $this->seedUnits(2);

        $res = $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                [
                    'product_id' => $this->serial->id, 'jenis' => 'kredit', 'qty' => 2,
                    'serial_unit_ids' => [$units[0]->ulid, $units[1]->ulid],
                    'serial_unit_statuses' => [$units[0]->ulid => 'hilang'],
                ],
            ],
        ])->assertCreated();
        $ulid = $res->json('data.adjustment.ulid');

        // Detail show menampilkan unit serial (kode_internal/SN) + fate per unit (hilang/rusak)
        $show = $this->getJson("/api/v1/adjustments/{$ulid}")->assertOk();
        $serialUnits = collect($show->json('data.adjustment.details.0.serial_units'));

        $this->assertCount(2, $serialUnits);
        $this->assertTrue($serialUnits->every(fn ($u) => str_starts_with((string) ($u['kode_internal'] ?? ''), 'KI-')));
        $this->assertEquals(['hilang', 'rusak'], $serialUnits->pluck('fate')->sort()->values()->all());
    }
    #[Test]
    public function adjustment_in_serial_rejected()
    {
        $this->seedUnits(2);

        $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                ['product_id' => $this->serial->id, 'jenis' => 'debit', 'qty' => 1],
            ],
        ])->assertStatus(422);
    }
    #[Test]
    public function adjustment_out_serial_requires_units()
    {
        $this->seedUnits(2);

        $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                ['product_id' => $this->serial->id, 'jenis' => 'kredit', 'qty' => 1, 'serial_unit_ids' => []],
            ],
        ])->assertStatus(422);
    }
    #[Test]
    public function adjustment_out_serial_rejects_unit_from_other_warehouse()
    {
        $this->seedUnits(2); // di wh
        $wh2 = MasterWarehouse::create(['kode_warehouse' => 'WH2', 'nama_warehouse' => 'Gudang 2', 'is_saleable' => true, 'status' => 'active']);
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $wh2->id],
            ['qty' => 1, 'avg_cost' => 1500]
        );
        StockCard::record([
            'product_id' => $this->serial->id, 'warehouse_id' => $wh2->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(), 'qty_in' => 1, 'qty_out' => 0, 'cost_per_unit' => 1500,
        ]);
        StockCard::$skipObserver = false;
        $u2 = SerialUnit::create([
            'product_id' => $this->serial->id, 'warehouse_id' => $wh2->id,
            'serial_number' => 'SNB-1', 'harga_modal' => 1000, 'cost_per_unit' => 1500, 'status' => 'tersedia',
        ]);

        // Adjustment di wh, tapi pilih unit yang ada di wh2
        $res = $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                ['product_id' => $this->serial->id, 'jenis' => 'kredit', 'qty' => 1, 'serial_unit_ids' => [$u2->ulid]],
            ],
        ])->assertCreated();
        $this->postJson("/api/v1/adjustments/{$res->json('data.adjustment.ulid')}/approve")->assertStatus(422);

        // Unit tak berubah
        $this->assertSame('tersedia', SerialUnit::where('ulid', $u2->ulid)->value('status'));
    }
    #[Test]
    public function adjustment_out_serial_rejects_unit_not_available()
    {
        $units = $this->seedUnits(2);
        // unit[0] sudah rusak (bukan tersedia)
        SerialUnit::where('ulid', $units[0]->ulid)->update(['status' => 'rusak']);

        $res = $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                ['product_id' => $this->serial->id, 'jenis' => 'kredit', 'qty' => 1, 'serial_unit_ids' => [$units[0]->ulid]],
            ],
        ])->assertCreated();
        $this->postJson("/api/v1/adjustments/{$res->json('data.adjustment.ulid')}/approve")->assertStatus(422);
    }
    #[Test]
    public function adjustment_rejects_invalid_serial_unit_status()
    {
        $units = $this->seedUnits(2);

        // status fate selain rusak/hilang (mis. 'terjual') → ditolak validasi (422)
        $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [[
                'product_id' => $this->serial->id, 'jenis' => 'kredit', 'qty' => 1,
                'serial_unit_ids' => [$units[0]->ulid],
                'serial_unit_statuses' => [$units[0]->ulid => 'terjual'],
            ]],
        ])->assertStatus(422);
    }
    #[Test]
    public function adjustment_ignores_status_for_unselected_unit_and_defaults_rusak()
    {
        $units = $this->seedUnits(2);

        // Map status untuk unit yang TIDAK dipilih (units[1]) → diabaikan; units[0] tanpa map → default 'rusak'
        $res = $this->postJson('/api/v1/adjustments', [
            'warehouse_id' => $this->wh->id,
            'tanggal' => now()->toDateString(),
            'details' => [[
                'product_id' => $this->serial->id, 'jenis' => 'kredit', 'qty' => 1,
                'serial_unit_ids' => [$units[0]->ulid],
                'serial_unit_statuses' => [$units[1]->ulid => 'hilang'],
            ]],
        ])->assertCreated();
        $this->postJson("/api/v1/adjustments/{$res->json('data.adjustment.ulid')}/approve")->assertOk();

        $this->assertSame('rusak', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));     // default
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[1]->ulid)->value('status'));  // tak terpilih → tak berubah
    }
}
