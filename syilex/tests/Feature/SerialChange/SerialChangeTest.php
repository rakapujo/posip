<?php

namespace Tests\Feature\SerialChange;

use App\Models\DocSerialChange;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Perubahan Data Serial — koreksi data unit tersedia (HJ + SN + atribut), alur draft → approved.
 */
class SerialChangeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $produk;
    protected MasterWarehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['serial-change.view', 'serial-change.create', 'serial-change.update', 'serial-change.delete', 'serial-change.approve'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['serial-change.view', 'serial-change.create', 'serial-change.update', 'serial-change.delete', 'serial-change.approve']);
        $this->actingAs($this->admin);

        $this->produk = $this->serialProduk('LAP_M2');
        $this->wh = MasterWarehouse::create(['kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang', 'is_saleable' => true, 'status' => 'active']);
    }

    private function serialProduk(string $kode): MasterProduk
    {
        return MasterProduk::create([
            'kode_produk' => $kode, 'nama_produk' => "Produk {$kode}", 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
    }

    private function makeUnit(MasterProduk $p, string $sn, string $status = 'tersedia'): SerialUnit
    {
        return SerialUnit::create([
            'product_id' => $p->id, 'warehouse_id' => $this->wh->id, 'serial_number' => $sn,
            'harga_modal' => 10000000, 'cost_per_unit' => 10000000, 'harga_jual' => 12000000,
            'grade' => 'A', 'battery_condition' => 'Original', 'battery_health' => 90,
            'account_status' => 'unlocked', 'status' => $status,
        ]);
    }

    /** Payload koreksi 1 unit dari nilai unit sekarang + override. */
    private function unitPayload(SerialUnit $u, array $override = []): array
    {
        return array_merge([
            'serial_unit_id' => $u->ulid,
            'serial_number' => $u->serial_number,
            'harga_jual' => (float) $u->harga_jual,
            'grade' => $u->grade,
            'battery_condition' => $u->battery_condition,
            'battery_health' => (float) $u->battery_health,
            'account_status' => $u->account_status,
        ], $override);
    }

    private function createChange(array $units, array $override = [])
    {
        return $this->postJson('/api/v1/serial-changes', array_merge([
            'product_id' => $this->produk->ulid,
            'units' => $units,
        ], $override));
    }
    #[Test]
    public function approve_applies_changes_to_unit()
    {
        $u = $this->makeUnit($this->produk, 'SN-OLD');

        $ulid = $this->createChange([
            $this->unitPayload($u, ['serial_number' => 'SN-FIXED', 'grade' => 'B', 'harga_jual' => 13500000, 'account_status' => 'locked']),
        ])->assertStatus(201)->json('data.serial_change.ulid');

        // Draft: unit BELUM berubah
        $this->assertSame('SN-OLD', $u->fresh()->serial_number);

        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);

        $u->refresh();
        $this->assertSame('SN-FIXED', $u->serial_number);
        $this->assertSame('B', $u->grade);
        $this->assertEquals(13500000, (float) $u->harga_jual);
        $this->assertSame('locked', $u->account_status);
    }
    #[Test]
    public function sold_unit_cannot_be_corrected()
    {
        $u = $this->makeUnit($this->produk, 'SN-SOLD', 'terjual');

        $this->createChange([$this->unitPayload($u, ['serial_number' => 'SN-X'])])
            ->assertStatus(422);
    }
    #[Test]
    public function new_sn_colliding_with_other_unit_is_allowed()
    {
        $a = $this->makeUnit($this->produk, 'SN-A');
        $b = $this->makeUnit($this->produk, 'SN-B');

        // Ubah A → SN-B (sudah dipakai unit lain) → DIBOLEHKAN (SN tak unik; kode_internal tetap beda)
        $ulid = $this->createChange([$this->unitPayload($a, ['serial_number' => 'SN-B'])])
            ->assertStatus(201)->json('data.serial_change.ulid');
        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);

        $this->assertSame('SN-B', $a->fresh()->serial_number);
        $this->assertSame('SN-B', $b->fresh()->serial_number);
        // Identitas tetap terpisah lewat kode_internal
        $this->assertNotSame($a->fresh()->kode_internal, $b->fresh()->kode_internal);
    }
    #[Test]
    public function swapping_sn_between_units_is_allowed()
    {
        $a = $this->makeUnit($this->produk, 'SN-A');
        $b = $this->makeUnit($this->produk, 'SN-B');

        $ulid = $this->createChange([
            $this->unitPayload($a, ['serial_number' => 'SN-B']),
            $this->unitPayload($b, ['serial_number' => 'SN-A']),
        ])->assertStatus(201)->json('data.serial_change.ulid');

        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);

        $this->assertSame('SN-B', $a->fresh()->serial_number);
        $this->assertSame('SN-A', $b->fresh()->serial_number);
    }
    #[Test]
    public function same_sn_on_different_product_is_allowed()
    {
        $produkB = $this->serialProduk('HP_15');
        $unitB = $this->makeUnit($produkB, 'SHARED-SN');
        $a = $this->makeUnit($this->produk, 'SN-A');

        // Ubah unit produk A → 'SHARED-SN' (ada di produk B, tapi beda produk → boleh)
        $ulid = $this->createChange([$this->unitPayload($a, ['serial_number' => 'SHARED-SN'])])
            ->assertStatus(201)->json('data.serial_change.ulid');

        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);
        $this->assertSame('SHARED-SN', $a->fresh()->serial_number);
    }
    #[Test]
    public function gudang_cannot_approve()
    {
        $u = $this->makeUnit($this->produk, 'SN-G');
        $ulid = $this->createChange([$this->unitPayload($u, ['serial_number' => 'SN-G2'])])
            ->assertStatus(201)->json('data.serial_change.ulid');

        $gudang = User::factory()->create();
        $gudang->givePermissionTo(['serial-change.view', 'serial-change.create', 'serial-change.update', 'serial-change.delete']);
        $this->actingAs($gudang);

        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(403);
    }
    #[Test]
    public function units_endpoint_returns_only_tersedia()
    {
        $this->makeUnit($this->produk, 'SN-1', 'tersedia');
        $this->makeUnit($this->produk, 'SN-2', 'terjual');

        $res = $this->getJson('/api/v1/serial-changes/units?product_id=' . $this->produk->ulid);
        $res->assertStatus(200);
        $this->assertCount(1, $res->json('data.units'));
    }
    #[Test]
    public function approving_twice_is_rejected()
    {
        $u = $this->makeUnit($this->produk, 'SN-DUP');
        $ulid = $this->createChange([$this->unitPayload($u, ['serial_number' => 'SN-DUP2'])])
            ->assertStatus(201)->json('data.serial_change.ulid');

        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);

        // Approve kedua → status sudah 'approved', bukan draft → 422
        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(422);

        // Unit hanya berubah sekali (tetap SN-DUP2), tak rusak oleh approve ganda
        $this->assertSame('SN-DUP2', $u->fresh()->serial_number);
    }
    #[Test]
    public function approve_snapshots_old_values_into_before_exactly()
    {
        $u = $this->makeUnit($this->produk, 'SN-BEF'); // grade A, HJ 12jt, battery 90, unlocked

        $ulid = $this->createChange([
            $this->unitPayload($u, [
                'serial_number' => 'SN-AFT', 'grade' => 'C',
                'harga_jual' => 9990000, 'battery_health' => 77, 'account_status' => 'locked',
            ]),
        ])->assertStatus(201)->json('data.serial_change.ulid');

        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);

        $detail = DocSerialChange::where('ulid', $ulid)->first()->details()->first();
        $before = $detail->before;

        // Snapshot 'before' = nilai LAMA persis (sebelum koreksi)
        $this->assertSame('SN-BEF', $before['serial_number']);
        $this->assertSame('A', $before['grade']);
        $this->assertEquals(12000000, (float) $before['harga_jual']);
        $this->assertEquals(90, (float) $before['battery_health']);
        $this->assertSame('unlocked', $before['account_status']);

        // Nilai baru tersimpan di detail
        $this->assertSame('SN-AFT', $detail->serial_number);
        $this->assertSame('C', $detail->grade);
        $this->assertEquals(9990000, (float) $detail->harga_jual);
    }
    #[Test]
    public function harga_modal_is_never_touched_by_change_module()
    {
        $u = $this->makeUnit($this->produk, 'SN-HM'); // harga_modal 10jt, cost 10jt
        $ulid = $this->createChange([$this->unitPayload($u, ['serial_number' => 'SN-HM2', 'harga_jual' => 99000000])])
            ->assertStatus(201)->json('data.serial_change.ulid');

        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);

        $u->refresh();
        // Modul ini koreksi harga_jual & atribut — HPP/modal sengaja dikecualikan
        $this->assertEquals(10000000, (float) $u->harga_modal);
        $this->assertEquals(10000000, (float) $u->cost_per_unit);
        $this->assertEquals(99000000, (float) $u->harga_jual);
    }
    #[Test]
    public function unit_belonging_to_other_product_is_rejected_on_create()
    {
        $produkB = $this->serialProduk('HP_99');
        $unitB = $this->makeUnit($produkB, 'SN-B-OWN');

        // Header product = $this->produk, tapi unit milik produk B → ditolak
        $this->createChange([$this->unitPayload($unitB, ['serial_number' => 'SN-B-NEW'])])
            ->assertStatus(422);
    }
    #[Test]
    public function non_serial_product_is_rejected()
    {
        $retail = MasterProduk::create([
            'kode_produk' => 'RTL_NS', 'nama_produk' => 'Charger', 'status' => 'active',
            'is_serial' => false, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => 'BC-NS',
            'unit_1' => 'PCS', 'konversi_1' => 1, 'harga_1' => 1000,
            'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 1000,
            'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 1000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 1000,
        ]);
        $u = $this->makeUnit($this->produk, 'SN-NS'); // unit valid (produk serial)

        // product_id menunjuk produk retail → resolveData tolak (bukan produk serial)
        $this->createChange([$this->unitPayload($u)], ['product_id' => $retail->ulid])
            ->assertStatus(422);
    }
    #[Test]
    public function empty_units_array_is_rejected_by_validation()
    {
        $this->createChange([])->assertStatus(422)->assertJsonValidationErrors('units');
    }
    #[Test]
    public function invalid_grade_value_is_rejected_by_validation()
    {
        $u = $this->makeUnit($this->produk, 'SN-GR');
        $this->createChange([$this->unitPayload($u, ['grade' => 'Z'])])
            ->assertStatus(422)->assertJsonValidationErrors('units.0.grade');
    }
    #[Test]
    public function battery_health_above_100_is_rejected_by_validation()
    {
        $u = $this->makeUnit($this->produk, 'SN-BH');
        $this->createChange([$this->unitPayload($u, ['battery_health' => 150])])
            ->assertStatus(422)->assertJsonValidationErrors('units.0.battery_health');
    }
    #[Test]
    public function update_only_allowed_on_draft_and_replaces_details()
    {
        $a = $this->makeUnit($this->produk, 'SN-U1');
        $b = $this->makeUnit($this->produk, 'SN-U2');

        $ulid = $this->createChange([$this->unitPayload($a, ['serial_number' => 'SN-U1X'])])
            ->assertStatus(201)->json('data.serial_change.ulid');

        // Update draft: ganti penuh detail jadi unit B
        $this->putJson("/api/v1/serial-changes/{$ulid}", [
            'product_id' => $this->produk->ulid,
            'units' => [$this->unitPayload($b, ['serial_number' => 'SN-U2X'])],
        ])->assertStatus(200);

        $change = DocSerialChange::where('ulid', $ulid)->first();
        $this->assertSame(1, $change->details()->count());
        $this->assertSame('SN-U2X', $change->details()->first()->serial_number);
        $this->assertEquals(1, $change->total_unit);

        // Setelah approve → tak bisa di-update lagi
        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);
        $this->putJson("/api/v1/serial-changes/{$ulid}", [
            'product_id' => $this->produk->ulid,
            'units' => [$this->unitPayload($a, ['serial_number' => 'SN-U1Z'])],
        ])->assertStatus(422);
    }
    #[Test]
    public function approve_skips_unit_that_became_sold_after_draft()
    {
        $a = $this->makeUnit($this->produk, 'SN-S1');
        $b = $this->makeUnit($this->produk, 'SN-S2');

        $ulid = $this->createChange([
            $this->unitPayload($a, ['serial_number' => 'SN-S1X', 'grade' => 'B']),
            $this->unitPayload($b, ['serial_number' => 'SN-S2X', 'grade' => 'B']),
        ])->assertStatus(201)->json('data.serial_change.ulid');

        // Unit A keburu terjual sebelum approve
        $a->update(['status' => 'terjual']);

        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);

        // A dilewati (tetap SN lama + grade lama), B diterapkan
        $a->refresh();
        $b->refresh();
        $this->assertSame('SN-S1', $a->serial_number);
        $this->assertSame('A', $a->grade);
        $this->assertSame('SN-S2X', $b->serial_number);
        $this->assertSame('B', $b->grade);

        // Dokumen tetap approved
        $this->assertSame('approved', DocSerialChange::where('ulid', $ulid)->value('status'));
    }
    #[Test]
    public function units_endpoint_requires_create_or_update_permission()
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['serial-change.view']); // hanya view
        $this->actingAs($viewer);

        $this->getJson('/api/v1/serial-changes/units?product_id=' . $this->produk->ulid)
            ->assertStatus(403);
    }
    #[Test]
    public function units_endpoint_returns_404_for_unknown_product()
    {
        $this->getJson('/api/v1/serial-changes/units?product_id=NOTAULID')
            ->assertStatus(404);
    }
    #[Test]
    public function create_requires_permission()
    {
        $u = $this->makeUnit($this->produk, 'SN-PERM');
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['serial-change.view']);
        $this->actingAs($viewer);

        $this->createChange([$this->unitPayload($u, ['serial_number' => 'SN-PERM2'])])
            ->assertStatus(403);
    }
    #[Test]
    public function duplicate_new_sn_within_same_payload_is_allowed()
    {
        $a = $this->makeUnit($this->produk, 'SN-DA');
        $b = $this->makeUnit($this->produk, 'SN-DB');

        // Dua unit dikoreksi ke SN baru yang sama → DIBOLEHKAN (SN tak unik)
        $ulid = $this->createChange([
            $this->unitPayload($a, ['serial_number' => 'SN-SAME']),
            $this->unitPayload($b, ['serial_number' => 'SN-SAME']),
        ])->assertStatus(201)->json('data.serial_change.ulid');
        $this->postJson("/api/v1/serial-changes/{$ulid}/approve")->assertStatus(200);

        $this->assertSame('SN-SAME', $a->fresh()->serial_number);
        $this->assertSame('SN-SAME', $b->fresh()->serial_number);
    }
}
