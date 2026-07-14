<?php

namespace Tests\Feature\Serial;

use App\Models\DocSerialHppCorrection;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\SerialUnitMovement;
use App\Models\StockCard;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Fase 5 — Koreksi HPP Serial (per-unit): koreksi harga_modal & cost_per_unit unit
 * tersedia; default TIDAK mengubah avg_cost agregat produk.
 */
class SerialHppCorrectionTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $serial;
    protected MasterWarehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['serial-hpp.view', 'serial-hpp.create', 'serial-hpp.update', 'serial-hpp.delete', 'serial-hpp.approve'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::all());
        $this->actingAs($this->admin);

        // Pajak deterministik: PPN 10% MASUK HPP
        SettingService::set('tax.tax_purchase_included_in_hpp', '1', 'boolean');
        SettingService::set('tax.tax_purchase_percent', 10, 'integer');

        $this->serial = MasterProduk::create([
            'kode_produk' => 'SER1', 'nama_produk' => 'iPhone Serial', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 1100, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
        $this->wh = MasterWarehouse::create(['kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang 1', 'is_saleable' => true, 'status' => 'active']);
    }

    private function seedUnit(string $sn = 'SN-1', string $status = 'tersedia'): SerialUnit
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id],
            ['qty' => 1, 'avg_cost' => 1100]
        );
        StockCard::record([
            'product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => 1, 'qty_out' => 0, 'cost_per_unit' => 1100,
        ]);
        StockCard::$skipObserver = false;

        return SerialUnit::create([
            'product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id,
            'serial_number' => $sn, 'harga_modal' => 1000, 'cost_per_unit' => 1100, 'status' => $status,
        ]);
    }
    #[Test]
    public function create_and_approve_corrects_unit_and_propagates_avg_cost()
    {
        $unit = $this->seedUnit();

        // Komponen: Modal 1000 + Kirim 100 + Lain 0 = DPP 1100; Pajak 10% = 110; Landed = 1210
        $res = $this->postJson('/api/v1/serial-hpp-corrections', [
            'product_id' => $this->serial->ulid,
            'tanggal' => now()->toDateString(),
            'notes' => 'Koreksi modal salah input',
            'units' => [
                ['serial_unit_id' => $unit->ulid, 'harga_modal_baru' => 1000, 'biaya_kirim_baru' => 100, 'biaya_lain_baru' => 0],
            ],
        ])->assertCreated();

        $ulid = $res->json('data.serial_hpp_correction.ulid');

        // Detail: rincian + landed terhitung otomatis
        $detail = DocSerialHppCorrection::where('ulid', $ulid)->first()->details()->first();
        $this->assertSame(100.00, (float) $detail->biaya_kirim_baru);
        $this->assertSame(110.00, (float) $detail->pajak_baru);
        $this->assertSame(1210.0000, (float) $detail->cost_per_unit_baru);
        $this->assertSame('1000.00', (string) $detail->before['harga_modal']);

        $this->postJson("/api/v1/serial-hpp-corrections/{$ulid}/approve")->assertOk();

        $unit->refresh();
        $this->assertSame(1000.00, (float) $unit->harga_modal);
        $this->assertSame(1210.0000, (float) $unit->cost_per_unit);

        // Propagasi (Metode A): avg_cost = rata-rata cost_per_unit unit tersedia (1 unit) = 1210
        $this->assertSame(1210.0, (float) $this->serial->fresh()->avg_cost);

        // stock_card HPP_CORRECTION tercatat (tampil di Pergerakan HPP)
        $card = StockCard::where('transaction_type', 'HPP_CORRECTION')->where('product_id', $this->serial->id)->first();
        $this->assertNotNull($card);
        $this->assertSame(1100.0000, (float) $card->avg_cost_before);
        $this->assertSame(1210.0000, (float) $card->avg_cost_after);

        // Movement HPP_SERIAL tercatat
        $this->assertSame(1, SerialUnitMovement::where('doc_type', 'HPP_SERIAL')->where('serial_unit_id', $unit->id)->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function cannot_correct_non_tersedia_unit()
    {
        $unit = $this->seedUnit('SN-SOLD', 'terjual');

        $this->postJson('/api/v1/serial-hpp-corrections', [
            'product_id' => $this->serial->ulid,
            'units' => [
                ['serial_unit_id' => $unit->ulid, 'harga_modal_baru' => 1500, 'cost_per_unit_baru' => 1600],
            ],
        ])->assertStatus(422);
    }
    #[Test]
    public function units_endpoint_returns_tersedia_with_current_cost()
    {
        $unit = $this->seedUnit();
        $this->seedUnit('SN-SOLD', 'terjual');

        $res = $this->getJson("/api/v1/serial-hpp-corrections/units?product_id={$this->serial->ulid}")->assertOk();

        $items = $res->json('data.units');
        $this->assertCount(1, $items);
        $this->assertSame('SN-1', $items[0]['serial_number']);
    }
    #[Test]
    public function correction_excludes_tax_from_landed_when_setting_off()
    {
        // Pajak TIDAK masuk HPP → landed = DPP (modal+kirim+lain), tanpa pajak
        SettingService::set('tax.tax_purchase_included_in_hpp', '0', 'boolean');
        $unit = $this->seedUnit();

        $res = $this->postJson('/api/v1/serial-hpp-corrections', [
            'product_id' => $this->serial->ulid,
            'tanggal' => now()->toDateString(),
            'units' => [
                ['serial_unit_id' => $unit->ulid, 'harga_modal_baru' => 1000, 'biaya_kirim_baru' => 100, 'biaya_lain_baru' => 0],
            ],
        ])->assertCreated();

        $detail = DocSerialHppCorrection::where('ulid', $res->json('data.serial_hpp_correction.ulid'))->first()->details()->first();
        $this->assertSame(0.00, (float) $detail->pajak_baru);
        $this->assertSame(1100.0000, (float) $detail->cost_per_unit_baru); // = DPP, tanpa pajak
    }
    #[Test]
    public function cannot_approve_already_approved_correction()
    {
        $unit = $this->seedUnit();
        $res = $this->postJson('/api/v1/serial-hpp-corrections', [
            'product_id' => $this->serial->ulid,
            'tanggal' => now()->toDateString(),
            'units' => [
                ['serial_unit_id' => $unit->ulid, 'harga_modal_baru' => 1000, 'biaya_kirim_baru' => 100, 'biaya_lain_baru' => 0],
            ],
        ])->assertCreated();
        $ulid = $res->json('data.serial_hpp_correction.ulid');

        $this->postJson("/api/v1/serial-hpp-corrections/{$ulid}/approve")->assertOk();
        // Approve kedua kali → ditolak (bukan draft)
        $this->postJson("/api/v1/serial-hpp-corrections/{$ulid}/approve")->assertStatus(422);
    }
}
