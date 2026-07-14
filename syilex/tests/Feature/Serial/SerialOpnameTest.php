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
 * Fase 3 — Stock Opname untuk produk serial (checklist SN hadir):
 * SN yang tak tercentang hadir → selisih kurang → unit ditandai 'hilang' lewat
 * adjustment turunan. Selisih lebih ditolak.
 */
class SerialOpnameTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $serial;
    protected MasterWarehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['opname.view', 'opname.create', 'opname.approve', 'serial-intake.view'] as $perm) {
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
            'qty_in' => $count, 'qty_out' => 0, 'cost_per_unit' => 1000,
        ]);
        StockCard::$skipObserver = false;

        $units = [];
        for ($i = 1; $i <= $count; $i++) {
            $units[] = SerialUnit::create([
                'product_id' => $this->serial->id, 'warehouse_id' => $this->wh->id,
                'serial_number' => "SN-{$i}", 'harga_modal' => 1000, 'cost_per_unit' => 1000, 'status' => 'tersedia',
            ]);
        }
        return $units;
    }

    private function createOpname(array $present): string
    {
        $res = $this->postJson('/api/v1/opnames', [
            'warehouse_id' => $this->wh->id,
            'tanggal_opname' => now()->toDateString(),
            'mode' => 'partial',
            'details' => [
                [
                    'product_id' => $this->serial->id,
                    'qty_physical' => count($present),
                    'serial_unit_ids_present' => $present,
                ],
            ],
        ])->assertCreated();

        return $res->json('data.opname.ulid');
    }
    #[Test]
    public function opname_missing_serial_unit_marked_hilang()
    {
        $units = $this->seedUnits(3);
        // Hadir hanya 2; SN-3 tidak ketemu fisik
        $ulid = $this->createOpname([$units[0]->ulid, $units[1]->ulid]);

        $this->postJson("/api/v1/opnames/{$ulid}/approve")->assertOk();

        $this->assertSame('hilang', SerialUnit::where('ulid', $units[2]->ulid)->value('status'));
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[1]->ulid)->value('status'));

        $this->assertSame(2, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->wh->id)->value('qty'));

        // Movement OUT untuk unit hilang (via adjustment turunan)
        $this->assertSame(1, SerialUnitMovement::where('doc_type', 'ADJUSTMENT')->where('serial_unit_id', $units[2]->id)->where('to_status', 'hilang')->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function opname_all_present_no_change()
    {
        $units = $this->seedUnits(2);
        $ulid = $this->createOpname([$units[0]->ulid, $units[1]->ulid]);

        $this->postJson("/api/v1/opnames/{$ulid}/approve")->assertOk();

        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertSame('tersedia', SerialUnit::where('ulid', $units[1]->ulid)->value('status'));
        $this->assertSame(2, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->wh->id)->value('qty'));
        $this->assertSame(0, SerialUnitMovement::count());
    }
    #[Test]
    public function opname_none_present_marks_all_hilang()
    {
        $units = $this->seedUnits(2);
        // Tak ada unit yang hadir fisik → semua hilang
        $ulid = $this->createOpname([]);

        $this->postJson("/api/v1/opnames/{$ulid}/approve")->assertOk();

        $this->assertSame('hilang', SerialUnit::where('ulid', $units[0]->ulid)->value('status'));
        $this->assertSame('hilang', SerialUnit::where('ulid', $units[1]->ulid)->value('status'));
        $this->assertSame(0, (int) InventoryStock::where('product_id', $this->serial->id)->where('warehouse_id', $this->wh->id)->value('qty'));
        $this->assertSame(2, SerialUnitMovement::where('to_status', 'hilang')->count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function opname_show_attaches_present_serial_units()
    {
        $units = $this->seedUnits(3);
        $ulid = $this->createOpname([$units[0]->ulid, $units[1]->ulid]); // 2 hadir, SN-3 tidak

        // Detail show me-resolve serial_unit_ids_present → unit HADIR (kode_internal/SN)
        $res = $this->getJson("/api/v1/opnames/{$ulid}")->assertOk();
        $serialUnits = collect($res->json('data.opname.details.0.serial_units'));

        $this->assertCount(2, $serialUnits); // hanya yang ditandai hadir
        $this->assertTrue($serialUnits->every(fn ($u) => str_starts_with((string) ($u['kode_internal'] ?? ''), 'KI-')));
        $this->assertNotContains('SN-3', $serialUnits->pluck('serial_number')->all());
    }
}
