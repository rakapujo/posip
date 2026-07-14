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
 * Fase 2 — Transfer antar gudang untuk produk serial:
 * unit fisik (SN) pindah warehouse_id, status tetap tersedia, dicatat 2 movement,
 * stok agregat & invariant tetap konsisten.
 */
class SerialTransferTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $serial;
    protected MasterWarehouse $wh1;
    protected MasterWarehouse $wh2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transfer.view', 'transfer.create', 'transfer.approve', 'serial-intake.view'] as $perm) {
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
        $this->wh1 = MasterWarehouse::create(['kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang 1', 'is_saleable' => true, 'status' => 'active']);
        $this->wh2 = MasterWarehouse::create(['kode_warehouse' => 'WH2', 'nama_warehouse' => 'Gudang 2', 'is_saleable' => true, 'status' => 'active']);
    }

    /** Seed stok serial konsisten di satu gudang (inventory_stock + stock_card + unit tersedia). */
    private function seedUnits(int $count, MasterWarehouse $wh, string $prefix = 'SN'): array
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $this->serial->id, 'warehouse_id' => $wh->id],
            ['qty' => $count, 'avg_cost' => 1000]
        );
        StockCard::record([
            'product_id' => $this->serial->id, 'warehouse_id' => $wh->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => $count, 'qty_out' => 0, 'cost_per_unit' => 1000,
        ]);
        StockCard::$skipObserver = false;

        $units = [];
        for ($i = 1; $i <= $count; $i++) {
            $units[] = SerialUnit::create([
                'product_id' => $this->serial->id, 'warehouse_id' => $wh->id,
                'serial_number' => "{$prefix}-{$i}", 'harga_modal' => 1000, 'cost_per_unit' => 1000, 'status' => 'tersedia',
            ]);
        }
        return $units;
    }

    private function createTransfer(array $serialUlids): string
    {
        $res = $this->postJson('/api/v1/transfers', [
            'warehouse_from_id' => $this->wh1->id,
            'warehouse_to_id' => $this->wh2->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                ['product_id' => $this->serial->id, 'qty' => count($serialUlids), 'serial_unit_ids' => $serialUlids],
            ],
        ])->assertCreated();

        return $res->json('data.transfer.ulid');
    }
    #[Test]
    public function transfer_serial_moves_units_and_records_movements()
    {
        $units = $this->seedUnits(3, $this->wh1);
        $moved = [$units[0]->ulid, $units[1]->ulid];

        $ulid = $this->createTransfer($moved);
        $this->postJson("/api/v1/transfers/{$ulid}/approve")->assertOk();

        // Unit terpilih pindah ke wh2; sisanya tetap di wh1
        $this->assertSame($this->wh2->id, SerialUnit::where('ulid', $units[0]->ulid)->value('warehouse_id'));
        $this->assertSame($this->wh2->id, SerialUnit::where('ulid', $units[1]->ulid)->value('warehouse_id'));
        $this->assertSame($this->wh1->id, SerialUnit::where('ulid', $units[2]->ulid)->value('warehouse_id'));

        // Status tetap tersedia
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));

        // Stok agregat
        $this->assertSame(1, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->wh1->id)->value('qty'));
        $this->assertSame(2, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->wh2->id)->value('qty'));

        // 2 unit × (OUT+IN) = 4 movement
        $this->assertSame(4, SerialUnitMovement::where('doc_type', 'TRANSFER')->count());
        $this->assertSame(1, SerialUnitMovement::where('serial_unit_id', $units[0]->id)->where('movement_type', 'TRANSFER_OUT')->count());
        $this->assertSame(1, SerialUnitMovement::where('serial_unit_id', $units[0]->id)->where('movement_type', 'TRANSFER_IN')->count());

        // Invariant tetap konsisten
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function transfer_serial_rejects_unit_from_wrong_warehouse()
    {
        $this->seedUnits(2, $this->wh1);
        $wh2Units = $this->seedUnits(1, $this->wh2, 'SNB');

        // Draft transfer wh1→wh2 tapi unit yang dipilih ada di wh2 (salah gudang sumber)
        $ulid = $this->createTransfer([$wh2Units[0]->ulid]);

        $this->postJson("/api/v1/transfers/{$ulid}/approve")->assertStatus(422);

        // Tidak ada perubahan
        $this->assertSame($this->wh2->id, SerialUnit::where('ulid', $wh2Units[0]->ulid)->value('warehouse_id'));
        $this->assertSame(0, SerialUnitMovement::count());
    }
    #[Test]
    public function transfer_create_rejects_serial_without_units()
    {
        $this->seedUnits(2, $this->wh1);

        $this->postJson('/api/v1/transfers', [
            'warehouse_from_id' => $this->wh1->id,
            'warehouse_to_id' => $this->wh2->id,
            'tanggal' => now()->toDateString(),
            'details' => [
                ['product_id' => $this->serial->id, 'qty' => 1, 'serial_unit_ids' => []],
            ],
        ])->assertStatus(422);
    }
    #[Test]
    public function transfer_serial_without_biaya_does_not_change_avg_cost()
    {
        $units = $this->seedUnits(3, $this->wh1);
        $this->serial->update(['avg_cost' => 1000]);

        $ulid = $this->createTransfer([$units[0]->ulid]);
        $this->postJson("/api/v1/transfers/{$ulid}/approve")->assertOk();

        // Transfer tanpa biaya → HPP agregat tak berubah (§2B)
        $this->assertEqualsWithDelta(1000, (float) $this->serial->fresh()->avg_cost, 0.01);
        $this->assertSame(0, StockCard::where('product_id', $this->serial->id)->where('transaction_type', 'HPP_CORRECTION')->count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function transfer_show_attaches_serial_units_with_kode_internal()
    {
        $units = $this->seedUnits(3, $this->wh1);
        $ulid = $this->createTransfer([$units[0]->ulid, $units[1]->ulid]);

        // Detail show me-resolve serial_unit_ids → unit (kode_internal/SN) untuk tampilan + PDF
        $res = $this->getJson("/api/v1/transfers/{$ulid}")->assertOk();
        $serialUnits = collect($res->json('data.transfer.details.0.serial_units'));

        $this->assertCount(2, $serialUnits);
        $this->assertTrue($serialUnits->every(fn ($u) => str_starts_with((string) ($u['kode_internal'] ?? ''), 'KI-')));
        $this->assertTrue($serialUnits->every(fn ($u) => !empty($u['serial_number'])));
    }
}
