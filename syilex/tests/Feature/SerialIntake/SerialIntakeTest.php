<?php

namespace Tests\Feature\SerialIntake;

use App\Models\DocSerialIntake;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\StockCard;
use App\Models\SupplierHutang;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pembelian Serial — alur draft → approved.
 * Create/Update = draft (belum sentuh stok). Approve = komit stok + HPP weighted-avg + stock_card.
 */
class SerialIntakeTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $produk;
    protected MasterWarehouse $wh;
    protected MasterSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['serial-intake.view', 'serial-intake.view_harga', 'serial-intake.create', 'serial-intake.update', 'serial-intake.delete', 'serial-intake.approve'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['serial-intake.view', 'serial-intake.view_harga', 'serial-intake.create', 'serial-intake.update', 'serial-intake.delete', 'serial-intake.approve']);
        $this->actingAs($this->admin);

        $this->produk = MasterProduk::create([
            'kode_produk' => 'LAP_M2', 'nama_produk' => 'MacBook Air M2', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);

        $this->wh = MasterWarehouse::create([
            'kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang Utama',
            'is_saleable' => true, 'status' => 'active',
        ]);

        $this->supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP1', 'nama_supplier' => 'PT Test Supplier',
            'nama_pic' => 'Budi', 'telepon' => '08123456789', 'status' => 'active',
        ]);
    }

    /** Lengkapi field unit yang kini wajib (harga_jual/grade/baterai/health/akun) bila test tak menyebut. */
    private function fillUnits(array $units): array
    {
        return array_map(fn ($u) => array_merge([
            'harga_jual' => 1000, 'grade' => 'A', 'battery_condition' => 'Original',
            'battery_health' => 90, 'account_status' => 'unlocked',
        ], $u), $units);
    }

    private function createDraft(array $units, array $override = [])
    {
        return $this->postJson('/api/v1/serial-intakes', array_merge([
            'product_id' => $this->produk->ulid,
            'warehouse_id' => $this->wh->ulid,
            'supplier_id' => $this->supplier->ulid,
            'units' => $this->fillUnits($units),
        ], $override));
    }

    private function draftUlid(array $units, array $override = []): string
    {
        return $this->createDraft($units, $override)->json('data.serial_intake.ulid');
    }

    private function approveIntake(string $ulid)
    {
        return $this->postJson("/api/v1/serial-intakes/{$ulid}/approve");
    }

    private function invariant(): int
    {
        return (int) StockCard::where('product_id', $this->produk->id)
            ->where('warehouse_id', $this->wh->id)
            ->selectRaw('COALESCE(SUM(qty_in - qty_out),0) as bal')
            ->value('bal');
    }
    #[Test]
    public function create_makes_draft_without_touching_stock()
    {
        $this->createDraft([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 20000000],
        ])->assertStatus(201)->assertJsonPath('data.serial_intake.status', 'draft');

        // Belum ada stok / HPP / stock_card (baris inventory_stock boleh ada tapi qty 0)
        $this->assertEquals(0, (float) $this->produk->fresh()->avg_cost);
        $this->assertEquals(0, (int) InventoryStock::where('product_id', $this->produk->id)->sum('qty'));
        $this->assertEquals(0, StockCard::where('product_id', $this->produk->id)->count());

        // Unit tercatat tapi status 'pending'
        $this->assertEquals(2, SerialUnit::byProduct($this->produk->id)->where('status', 'pending')->count());
        $this->assertEquals(0, SerialUnit::byProduct($this->produk->id)->tersedia()->count());
    }
    #[Test]
    public function approve_commits_stock_hpp_units_and_keeps_invariant()
    {
        $ulid = $this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 20000000, 'harga_jual' => 24000000],
        ]);

        $this->approveIntake($ulid)
            ->assertStatus(200)
            ->assertJsonPath('data.serial_intake.status', 'approved');

        $stock = InventoryStock::where('product_id', $this->produk->id)->where('warehouse_id', $this->wh->id)->first();
        $this->assertEquals(2, $stock->qty);
        $this->assertEquals(15000000, (float) $stock->avg_cost);
        $this->assertEquals(15000000, (float) $this->produk->fresh()->avg_cost);

        $this->assertEquals(2, SerialUnit::byProduct($this->produk->id)->tersedia()->count());

        $card = StockCard::where('product_id', $this->produk->id)->where('transaction_type', 'PURCHASE')->first();
        $this->assertEquals(2, $card->qty_in);
        $this->assertEquals($stock->qty, $this->invariant());
    }
    #[Test]
    public function second_approved_intake_uses_weighted_average_hpp()
    {
        $this->approveIntake($this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 20000000],
        ]))->assertStatus(200); // hpp 15jt, qty 2

        $this->approveIntake($this->draftUlid([
            ['serial_number' => 'SN-C', 'harga_modal' => 21000000],
        ]))->assertStatus(200); // newHpp = (2*15jt + 1*21jt)/3 = 17jt, qty 3

        $stock = InventoryStock::where('product_id', $this->produk->id)->where('warehouse_id', $this->wh->id)->first();
        $this->assertEquals(3, $stock->qty);
        $this->assertEquals(17000000, (float) $stock->avg_cost);
        $this->assertEquals(3, $this->invariant());
    }
    #[Test]
    public function cannot_approve_twice()
    {
        $ulid = $this->draftUlid([['serial_number' => 'SN-A', 'harga_modal' => 5000000]]);
        $this->approveIntake($ulid)->assertStatus(200);
        $this->approveIntake($ulid)->assertStatus(422);

        // Stok tidak dobel
        $this->assertEquals(1, InventoryStock::where('product_id', $this->produk->id)->first()->qty);
    }
    #[Test]
    public function update_draft_replaces_units()
    {
        $ulid = $this->draftUlid([['serial_number' => 'SN-A', 'harga_modal' => 5000000]]);

        $this->putJson("/api/v1/serial-intakes/{$ulid}", [
            'product_id' => $this->produk->ulid,
            'warehouse_id' => $this->wh->ulid,
            'supplier_id' => $this->supplier->ulid,
            'units' => $this->fillUnits([
                ['serial_number' => 'SN-B', 'harga_modal' => 6000000],
                ['serial_number' => 'SN-C', 'harga_modal' => 7000000],
            ]),
        ])->assertStatus(200)->assertJsonPath('data.serial_intake.total_unit', 2);

        $sns = SerialUnit::byProduct($this->produk->id)->pluck('serial_number')->sort()->values()->all();
        $this->assertEquals(['SN-B', 'SN-C'], $sns);
    }
    #[Test]
    public function cannot_update_or_delete_approved()
    {
        $ulid = $this->draftUlid([['serial_number' => 'SN-A', 'harga_modal' => 5000000]]);
        $this->approveIntake($ulid)->assertStatus(200);

        $this->putJson("/api/v1/serial-intakes/{$ulid}", [
            'product_id' => $this->produk->ulid, 'warehouse_id' => $this->wh->ulid,
            'units' => [['serial_number' => 'SN-Z', 'harga_modal' => 1000]],
        ])->assertStatus(422);

        $this->deleteJson("/api/v1/serial-intakes/{$ulid}")->assertStatus(422);
    }
    #[Test]
    public function delete_draft_removes_units()
    {
        $ulid = $this->draftUlid([['serial_number' => 'SN-A', 'harga_modal' => 5000000]]);

        $this->deleteJson("/api/v1/serial-intakes/{$ulid}")->assertStatus(200);

        $this->assertEquals(0, SerialUnit::byProduct($this->produk->id)->count());
    }
    #[Test]
    public function gudang_can_create_but_not_approve()
    {
        $gudang = User::factory()->create();
        $gudang->givePermissionTo(['serial-intake.view', 'serial-intake.create', 'serial-intake.update', 'serial-intake.delete']);

        $this->actingAs($gudang);
        $ulid = $this->draftUlid([['serial_number' => 'SN-G', 'harga_modal' => 1000000]]);
        $this->assertNotNull($ulid);

        $this->approveIntake($ulid)->assertStatus(403);
    }
    #[Test]
    public function intake_stores_per_unit_condition_attributes()
    {
        $this->createDraft([
            [
                'serial_number' => 'SN-COND', 'harga_modal' => 5000000,
                'grade' => 'B', 'battery_condition' => 'Replacement', 'battery_health' => 87, 'account_status' => 'unlocked',
            ],
        ])->assertStatus(201);

        $u = SerialUnit::where('serial_number', 'SN-COND')->first();
        $this->assertSame('B', $u->grade);
        $this->assertSame('Replacement', $u->battery_condition);
        $this->assertEquals(87, (float) $u->battery_health);
        $this->assertSame('unlocked', $u->account_status);
    }
    #[Test]
    public function unit_missing_required_field_is_rejected()
    {
        // Tanpa fillUnits → unit tanpa grade/jual/baterai/health/akun harus ditolak
        $this->postJson('/api/v1/serial-intakes', [
            'product_id' => $this->produk->ulid,
            'warehouse_id' => $this->wh->ulid,
            'units' => [['serial_number' => 'SN-A', 'harga_modal' => 1000000]],
        ])->assertStatus(422);
    }
    #[Test]
    public function invalid_grade_or_battery_health_is_rejected()
    {
        $this->createDraft([['serial_number' => 'SN-G', 'harga_modal' => 1000, 'grade' => 'Z']])->assertStatus(422);
        $this->createDraft([['serial_number' => 'SN-H', 'harga_modal' => 1000, 'battery_health' => 150]])->assertStatus(422);
    }
    #[Test]
    public function duplicate_sn_within_payload_is_allowed()
    {
        // SN boleh kembar (bahkan dalam 1 produk) — identitas unik = kode_internal (auto, beda per unit).
        $this->createDraft([
            ['serial_number' => 'SN-X', 'harga_modal' => 1000],
            ['serial_number' => 'SN-X', 'harga_modal' => 2000],
        ])->assertStatus(201);

        $units = SerialUnit::byProduct($this->produk->id)->get();
        $this->assertEquals(2, $units->count());
        $this->assertEquals(['SN-X', 'SN-X'], $units->pluck('serial_number')->all());
        // kode_internal auto-generate & unik per unit
        $this->assertEquals(2, $units->pluck('kode_internal')->unique()->count());
        $this->assertTrue($units->every(fn ($u) => str_starts_with($u->kode_internal, 'KI-')));
    }
    #[Test]
    public function duplicate_kode_internal_within_payload_is_rejected()
    {
        $this->createDraft([
            ['serial_number' => 'SN-A', 'harga_modal' => 1000, 'kode_internal' => 'KODE-DUP'],
            ['serial_number' => 'SN-B', 'harga_modal' => 2000, 'kode_internal' => 'KODE-DUP'],
        ])->assertStatus(422);

        $this->assertEquals(0, SerialUnit::byProduct($this->produk->id)->count());
    }
    #[Test]
    public function sn_already_registered_for_product_is_allowed()
    {
        $this->createDraft([['serial_number' => 'SN-DUP', 'harga_modal' => 5000]])->assertStatus(201);
        // SN sama boleh didaftarkan lagi (tak lagi dikunci) — unit ke-2 dapat kode_internal sendiri.
        $this->createDraft([['serial_number' => 'SN-DUP', 'harga_modal' => 6000]])->assertStatus(201);

        $this->assertEquals(2, SerialUnit::byProduct($this->produk->id)->where('serial_number', 'SN-DUP')->count());
        $this->assertEquals(2, SerialUnit::byProduct($this->produk->id)->pluck('kode_internal')->unique()->count());
    }
    #[Test]
    public function provided_kode_internal_must_be_globally_unique()
    {
        $this->createDraft([['serial_number' => 'SN-1', 'harga_modal' => 5000, 'kode_internal' => 'MYCODE1']])->assertStatus(201);
        // kode_internal yang sama (lintas dokumen) ditolak
        $this->createDraft([['serial_number' => 'SN-2', 'harga_modal' => 6000, 'kode_internal' => 'MYCODE1']])->assertStatus(422);

        $this->assertEquals(1, SerialUnit::where('kode_internal', 'MYCODE1')->count());
    }
    #[Test]
    public function peek_kode_returns_next_sequential_code()
    {
        // Belum ada unit → next = KI-0000001
        $this->getJson('/api/v1/serial-units/peek-kode')
            ->assertOk()
            ->assertJsonPath('data.highest', 0)
            ->assertJsonPath('data.next', 'KI-0000001');

        // Buat 1 unit (auto KI-{id}) → peek next = id+1
        $this->createDraft([['serial_number' => 'SN-P', 'harga_modal' => 1000]])->assertStatus(201);
        $id = SerialUnit::byProduct($this->produk->id)->value('id');

        $res = $this->getJson('/api/v1/serial-units/peek-kode')->assertOk();
        $this->assertSame($id, $res->json('data.highest'));
        $this->assertSame('KI-' . str_pad((string) ($id + 1), 7, '0', STR_PAD_LEFT), $res->json('data.next'));
    }
    #[Test]
    public function peek_kode_forbidden_without_create_or_update_permission()
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('serial-intake.view'); // view saja, tanpa create/update
        $this->actingAs($viewer);

        $this->getJson('/api/v1/serial-units/peek-kode')->assertStatus(403);
    }
    #[Test]
    public function peek_kode_ignores_non_numeric_internal_codes()
    {
        // Hanya kode berpola KI-{angka} yang dihitung; KI-nonangka & non-KI diabaikan.
        SerialUnit::create(['product_id' => $this->produk->id, 'warehouse_id' => $this->wh->id, 'serial_number' => 'A', 'harga_modal' => 0, 'kode_internal' => 'KI-0000050', 'status' => 'tersedia']);
        SerialUnit::create(['product_id' => $this->produk->id, 'warehouse_id' => $this->wh->id, 'serial_number' => 'B', 'harga_modal' => 0, 'kode_internal' => 'KI-ZZZ', 'status' => 'tersedia']);
        SerialUnit::create(['product_id' => $this->produk->id, 'warehouse_id' => $this->wh->id, 'serial_number' => 'C', 'harga_modal' => 0, 'kode_internal' => 'MANUAL-9999', 'status' => 'tersedia']);

        $res = $this->getJson('/api/v1/serial-units/peek-kode')->assertOk();
        $res->assertJsonPath('data.highest', 50);
        $res->assertJsonPath('data.next', 'KI-0000051');
    }
    #[Test]
    public function provided_kode_internal_colliding_with_soft_deleted_unit_is_rejected()
    {
        $this->createDraft([['serial_number' => 'SN-T', 'harga_modal' => 1000, 'kode_internal' => 'DELCODE']])->assertStatus(201);
        SerialUnit::where('kode_internal', 'DELCODE')->first()->delete(); // soft delete — slot UNIQUE tetap terpakai

        // kode_internal sama (withTrashed) → ditolak, sejajar UNIQUE index DB
        $this->createDraft([['serial_number' => 'SN-U', 'harga_modal' => 1000, 'kode_internal' => 'DELCODE']])->assertStatus(422);
    }
    #[Test]
    public function harga_stripped_for_view_only_user()
    {
        $ulid = $this->draftUlid([['serial_number' => 'SN-H', 'harga_modal' => 5000]]);

        // User view-only (tanpa view_harga & tanpa update) → harga disembunyikan di detail & list
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('serial-intake.view');
        $this->actingAs($viewer);

        $show = $this->getJson("/api/v1/serial-intakes/{$ulid}")->assertOk();
        $this->assertNull($show->json('data.serial_intake.grand_total'));            // total beli sembunyi
        $this->assertNull($show->json('data.serial_intake.units.0.harga_modal'));    // modal (beli) sembunyi
        // harga_jual BUKAN rahasia → tetap tampil walau tanpa view_harga
        $this->assertEquals(1000, (float) $show->json('data.serial_intake.units.0.harga_jual'));

        $list = $this->getJson('/api/v1/serial-intakes')->assertOk();
        $this->assertNull($list->json('data.items.0.grand_total'));
    }
    #[Test]
    public function harga_visible_for_user_with_view_harga()
    {
        $ulid = $this->draftUlid([['serial_number' => 'SN-H', 'harga_modal' => 5000]]);

        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['serial-intake.view', 'serial-intake.view_harga']);
        $this->actingAs($viewer);

        $show = $this->getJson("/api/v1/serial-intakes/{$ulid}")->assertOk();
        $this->assertNotNull($show->json('data.serial_intake.grand_total'));
        $this->assertEquals(5000, (float) $show->json('data.serial_intake.units.0.harga_modal'));

        $list = $this->getJson('/api/v1/serial-intakes')->assertOk();
        $this->assertNotNull($list->json('data.items.0.grand_total'));
    }
    #[Test]
    public function editor_without_view_harga_still_gets_harga_in_detail_for_edit_form()
    {
        $ulid = $this->draftUlid([['serial_number' => 'SN-H', 'harga_modal' => 5000]]);

        // Editor (punya update) tanpa view_harga: detail (dipakai form edit) TETAP kirim harga,
        // tapi list tetap strip total (read-only digate murni view_harga).
        $editor = User::factory()->create();
        $editor->givePermissionTo(['serial-intake.view', 'serial-intake.update']);
        $this->actingAs($editor);

        $show = $this->getJson("/api/v1/serial-intakes/{$ulid}")->assertOk();
        $this->assertEquals(5000, (float) $show->json('data.serial_intake.units.0.harga_modal'));

        $list = $this->getJson('/api/v1/serial-intakes')->assertOk();
        $this->assertNull($list->json('data.items.0.grand_total'));
    }
    #[Test]
    public function non_serial_product_is_rejected()
    {
        $retail = MasterProduk::create([
            'kode_produk' => 'RTL', 'nama_produk' => 'Charger', 'status' => 'active',
            'is_serial' => false, 'minimum_stok' => 0, 'avg_cost' => 0,
            'unit_1' => 'PCS', 'konversi_1' => 1, 'harga_1' => 5000,
            'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 5000,
            'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 5000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 5000,
        ]);

        $this->createDraft([['serial_number' => 'SN-1', 'harga_modal' => 1000]], ['product_id' => $retail->ulid])
            ->assertStatus(422);
    }
    #[Test]
    public function create_requires_permission()
    {
        $noPerm = User::factory()->create();
        $this->actingAs($noPerm);
        $this->createDraft([['serial_number' => 'SN-1', 'harga_modal' => 1000]])->assertStatus(403);
    }
    #[Test]
    public function biaya_tambahan_allocated_to_landed_cost_and_hpp()
    {
        $ulid = $this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 10000000],
        ], ['biaya_kirim_tipe' => 'nominal', 'biaya_kirim_nilai' => 2000000]);

        // Landed cost: tiap unit 10jt + alokasi 1jt = 11jt
        $units = SerialUnit::byProduct($this->produk->id)->orderBy('id')->get();
        $this->assertEquals(11000000, (float) $units[0]->cost_per_unit);
        $this->assertEquals(11000000, (float) $units[1]->cost_per_unit);

        $intake = DocSerialIntake::where('ulid', $ulid)->first();
        $this->assertEquals(2000000, (float) $intake->total_biaya_tambahan);

        // Approve → HPP pakai landed 11jt (bukan modal kotor 10jt)
        $this->approveIntake($ulid)->assertStatus(200);
        $this->assertEquals(11000000, (float) $this->produk->fresh()->avg_cost);
    }
    #[Test]
    public function header_discount_reduces_total_but_not_hpp()
    {
        $ulid = $this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 10000000],
        ], ['diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10]);

        $intake = DocSerialIntake::where('ulid', $ulid)->first();
        $this->assertEquals(20000000, (float) $intake->subtotal);
        $this->assertEquals(2000000, (float) $intake->total_diskon_header);       // 10% × 20jt
        $this->assertEquals(18000000, (float) $intake->total_setelah_diskon);

        // Diskon header TIDAK memengaruhi HPP (sama seperti PO) → tetap 10jt
        $this->approveIntake($ulid)->assertStatus(200);
        $this->assertEquals(10000000, (float) $this->produk->fresh()->avg_cost);
    }
    #[Test]
    public function approve_with_supplier_creates_hutang()
    {
        $ulid = $this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
        ], ['supplier_id' => $this->supplier->ulid, 'tempo_hari' => 30]);

        $this->approveIntake($ulid)->assertStatus(200);

        $intake = DocSerialIntake::where('ulid', $ulid)->first();
        $hutang = SupplierHutang::where('serial_intake_id', $intake->id)->first();
        $this->assertNotNull($hutang);
        $this->assertEquals($this->supplier->id, $hutang->supplier_id);
        $this->assertNull($hutang->po_id);
        $this->assertEquals((float) $intake->grand_total, (float) $hutang->sisa_hutang);
        $this->assertSame('unpaid', $hutang->status);
        $this->assertNotNull($intake->tanggal_jatuh_tempo);
    }
    #[Test]
    public function cash_payment_auto_settles_hutang_on_approve()
    {
        $ulid = $this->draftUlid(
            [['serial_number' => 'SN-CASH', 'harga_modal' => 5000000]],
            ['cash_payment' => true, 'cash_metode' => 'cash', 'cash_no_referensi' => 'KW-001']
        );

        $this->approveIntake($ulid)->assertStatus(200);

        $intake = DocSerialIntake::where('ulid', $ulid)->first();
        $hutang = SupplierHutang::where('serial_intake_id', $intake->id)->first();

        // Hutang tetap dibuat, TAPI langsung lunas (sisa 0, status paid)
        $this->assertNotNull($hutang);
        $this->assertSame('paid', $hutang->status);
        $this->assertEquals(0, (float) $hutang->sisa_hutang);
        $this->assertEquals((float) $hutang->nominal_awal, (float) $hutang->nominal_terbayar);

        // Pembayaran hutang otomatis tercatat & completed, dgn metode + bukti (no_referensi)
        $pay = \App\Models\DocPembayaranHutang::where('supplier_id', $this->supplier->id)->first();
        $this->assertNotNull($pay);
        $this->assertSame('completed', $pay->status);
        $this->assertEquals((float) $hutang->nominal_awal, (float) $pay->total_pembayaran);
        $this->assertSame('cash', $pay->metode_pembayaran);
        $this->assertSame('KW-001', $pay->no_referensi);
    }
    #[Test]
    public function non_cash_intake_keeps_hutang_unpaid()
    {
        $ulid = $this->draftUlid([['serial_number' => 'SN-NC', 'harga_modal' => 5000000]]);

        $this->approveIntake($ulid)->assertStatus(200);

        $intake = DocSerialIntake::where('ulid', $ulid)->first();
        $hutang = SupplierHutang::where('serial_intake_id', $intake->id)->first();
        $this->assertSame('unpaid', $hutang->status);
        $this->assertEquals(0, \App\Models\DocPembayaranHutang::count());
    }
/** F2: cash_no_referensi >50 char ditolak (cegah overflow kolom → rollback approve). */
    #[Test]
    public function cash_no_referensi_longer_than_50_chars_is_rejected()
    {
        $this->createDraft(
            [['serial_number' => 'SN-LONG', 'harga_modal' => 1000000]],
            ['cash_payment' => true, 'cash_metode' => 'cash', 'cash_no_referensi' => str_repeat('X', 60)]
        )->assertStatus(422)->assertJsonValidationErrors(['cash_no_referensi']);
    }
/** F4: cash dicentang tanpa metode ditolak (cegah fallback diam ke 'cash'). */
    #[Test]
    public function cash_payment_requires_metode()
    {
        $this->createDraft(
            [['serial_number' => 'SN-NOMETODE', 'harga_modal' => 1000000]],
            ['cash_payment' => true]
        )->assertStatus(422)->assertJsonValidationErrors(['cash_metode']);
    }
/** F9: override kode_internal berpola KI-<angka> (dicadangkan auto) ditolak. */
    #[Test]
    public function kode_internal_override_in_reserved_ki_namespace_is_rejected()
    {
        $this->createDraft([
            ['serial_number' => 'SN-RES', 'harga_modal' => 1000000, 'kode_internal' => 'KI-9999999'],
        ])->assertStatus(422);
    }
    #[Test]
    public function calculate_endpoint_returns_breakdown()
    {
        $res = $this->postJson('/api/v1/serial-intakes/calculate', [
            'units' => [['harga_modal' => 10000000], ['harga_modal' => 10000000]],
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10,
            'biaya_kirim_tipe' => 'nominal', 'biaya_kirim_nilai' => 1000000,
        ]);

        $res->assertStatus(200);
        $c = $res->json('data.calculation');
        $this->assertEquals(20000000, (float) $c['subtotal']);
        $this->assertEquals(2000000, (float) $c['total_diskon_header']);   // 10% × 20jt
        $this->assertEquals(1000000, (float) $c['total_biaya_tambahan']);
        $this->assertEquals(19000000, (float) $c['dpp']);                  // 18jt + 1jt
        $this->assertGreaterThan(0, (float) $c['grand_total']);
    }
    #[Test]
    public function supplier_is_required()
    {
        // Tanpa supplier_id → ditolak (supplier wajib supaya hutang terbentuk)
        $this->postJson('/api/v1/serial-intakes', [
            'product_id' => $this->produk->ulid,
            'warehouse_id' => $this->wh->ulid,
            'units' => $this->fillUnits([['serial_number' => 'SN-A', 'harga_modal' => 1000000]]),
        ])->assertStatus(422);
    }

    /**
     * Setelah approve, stok berubah → invariant stock_card ↔ inventory_stock WAJIB konsisten
     * (data:verify exit-code 0). Sekaligus pastikan TEPAT 1 baris stock_card PURCHASE,
     * dengan jejak avg_before/avg_after eksak (0 → 15jt).
     *
     */
    #[Test]
    public function approve_keeps_global_data_invariant_clean()
    {
        $ulid = $this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 20000000],
        ]);
        $this->approveIntake($ulid)->assertStatus(200);

        $cards = StockCard::where('product_id', $this->produk->id)->where('transaction_type', 'PURCHASE')->get();
        $this->assertCount(1, $cards);
        $card = $cards->first();
        $this->assertEquals(2, (int) $card->qty_in);
        $this->assertEquals(0, (int) $card->qty_out);
        $this->assertEquals(15000000, (float) $card->cost_per_unit);
        $this->assertEquals(0, (float) $card->avg_cost_before);
        $this->assertEquals(15000000, (float) $card->avg_cost_after);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * HPP weighted-average bersifat GLOBAL (lintas gudang), tapi qty inventory_stock per-gudang.
     * Batch-1 (2 unit @15jt) approve ke WH1; batch-2 (1 unit @21jt) approve ke WH2:
     *   - avg global = (2×15jt + 1×21jt)/3 = 17jt → tersinkron ke SEMUA baris inventory_stock
     *   - qty WH1 = 2, qty WH2 = 1 (tidak tercampur), invariant tetap bersih.
     *
     */
    #[Test]
    public function approve_into_second_warehouse_uses_global_weighted_avg_but_per_warehouse_qty()
    {
        $wh2 = MasterWarehouse::create([
            'kode_warehouse' => 'WH2', 'nama_warehouse' => 'Gudang Cabang',
            'is_saleable' => true, 'status' => 'active',
        ]);

        $this->approveIntake($this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 15000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 15000000],
        ]))->assertStatus(200);

        $this->approveIntake($this->draftUlid([
            ['serial_number' => 'SN-C', 'harga_modal' => 21000000],
        ], ['warehouse_id' => $wh2->ulid]))->assertStatus(200);

        $stockWh1 = InventoryStock::where('product_id', $this->produk->id)->where('warehouse_id', $this->wh->id)->first();
        $stockWh2 = InventoryStock::where('product_id', $this->produk->id)->where('warehouse_id', $wh2->id)->first();

        $this->assertEquals(2, (int) $stockWh1->qty);
        $this->assertEquals(1, (int) $stockWh2->qty);
        // avg global tersinkron ke kedua baris
        $this->assertEquals(17000000, (float) $stockWh1->avg_cost);
        $this->assertEquals(17000000, (float) $stockWh2->avg_cost);
        $this->assertEquals(17000000, (float) $this->produk->fresh()->avg_cost);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Landed cost = modal item + alokasi biaya tambahan; diskon HEADER menurunkan grand total
     * TAPI TIDAK menurunkan HPP per unit (konsisten dgn header_discount_reduces_total_but_not_hpp).
     * 2 unit @10jt → subtotal item 20jt; diskon header 10% → total_setelah_diskon 18jt;
     * +kirim 1jt → DPP 19jt. Alokasi biaya kirim (1jt) merata pada subtotal item (bukan setelah diskon):
     *   tiap unit landed = 10jt + (1jt/2) = 10.500.000 (exact), HPP setelah approve = 10,5jt.
     *
     */
    #[Test]
    public function landed_cost_adds_shipping_but_header_discount_does_not_lower_hpp()
    {
        $ulid = $this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 10000000],
        ], [
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10,
            'biaya_kirim_tipe' => 'nominal', 'biaya_kirim_nilai' => 1000000,
        ]);

        $units = SerialUnit::byProduct($this->produk->id)->orderBy('id')->get();
        $this->assertEquals(10500000, (float) $units[0]->cost_per_unit);
        $this->assertEquals(10500000, (float) $units[1]->cost_per_unit);

        $intake = DocSerialIntake::where('ulid', $ulid)->first();
        $this->assertEquals(20000000, (float) $intake->subtotal);
        $this->assertEquals(2000000, (float) $intake->total_diskon_header);
        $this->assertEquals(18000000, (float) $intake->total_setelah_diskon);
        $this->assertEquals(1000000, (float) $intake->total_biaya_tambahan);
        $this->assertEquals(19000000, (float) $intake->dpp);

        $this->approveIntake($ulid)->assertStatus(200);
        // HPP = landed (modal + biaya), diskon header tidak mengurangi → 10,5jt
        $this->assertEquals(10500000, (float) $this->produk->fresh()->avg_cost);
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Edit draft boleh MEMPERTAHANKAN SN lama (cek unik mengecualikan intake sendiri):
     * SN-A diganti jadi {SN-A, SN-X} tanpa error "sudah terdaftar".
     *
     */
    #[Test]
    public function update_draft_may_reuse_its_own_serial_number()
    {
        $ulid = $this->draftUlid([['serial_number' => 'SN-A', 'harga_modal' => 5000000]]);

        $this->putJson("/api/v1/serial-intakes/{$ulid}", [
            'product_id' => $this->produk->ulid,
            'warehouse_id' => $this->wh->ulid,
            'supplier_id' => $this->supplier->ulid,
            'units' => $this->fillUnits([
                ['serial_number' => 'SN-A', 'harga_modal' => 5000000],
                ['serial_number' => 'SN-X', 'harga_modal' => 6000000],
            ]),
        ])->assertStatus(200)->assertJsonPath('data.serial_intake.total_unit', 2);

        $sns = SerialUnit::byProduct($this->produk->id)->pluck('serial_number')->sort()->values()->all();
        $this->assertEquals(['SN-A', 'SN-X'], $sns);
        $this->assertEquals(2, SerialUnit::byProduct($this->produk->id)->count());
    }

    /**
     * Edit draft BOLEH memakai SN milik intake LAIN — SN tak lagi unik (boleh kembar).
     * Identitas unik tetap terjaga lewat kode_internal yang melekat ke tiap unit.
     *
     */
    #[Test]
    public function update_draft_may_use_serial_number_owned_by_another_intake()
    {
        $this->draftUlid([['serial_number' => 'SN-B', 'harga_modal' => 1000000]]);
        $target = $this->draftUlid([['serial_number' => 'SN-A', 'harga_modal' => 1000000]]);

        $this->putJson("/api/v1/serial-intakes/{$target}", [
            'product_id' => $this->produk->ulid,
            'warehouse_id' => $this->wh->ulid,
            'supplier_id' => $this->supplier->ulid,
            'units' => $this->fillUnits([['serial_number' => 'SN-B', 'harga_modal' => 1000000]]),
        ])->assertStatus(200);

        // Target diubah ke SN-B → kini ada 2 unit ber-SN 'SN-B', 0 'SN-A'
        $this->assertEquals(2, SerialUnit::byProduct($this->produk->id)->where('serial_number', 'SN-B')->count());
        $this->assertEquals(0, SerialUnit::byProduct($this->produk->id)->where('serial_number', 'SN-A')->count());
        $this->assertEquals(2, SerialUnit::byProduct($this->produk->id)->count());
    }

    /**
     * Approve gagal jika intake draft tak punya unit (guard di action). Skenario dibuat lewat
     * data-layer (draft + 0 unit) karena store HTTP memaksa min:1. Status tetap draft, stok nol.
     *
     */
    #[Test]
    public function cannot_approve_draft_without_units()
    {
        $intake = DocSerialIntake::create([
            'nomor_dokumen' => 'PBS-EMPTY', 'tanggal' => now(),
            'product_id' => $this->produk->id, 'warehouse_id' => $this->wh->id,
            'supplier_id' => $this->supplier->id,
            'total_unit' => 0, 'total_modal' => 0, 'grand_total' => 0, 'status' => 'draft',
        ]);

        $this->approveIntake($intake->ulid)->assertStatus(422);

        $this->assertSame('draft', $intake->fresh()->status);
        $this->assertEquals(0, (int) InventoryStock::where('product_id', $this->produk->id)->sum('qty'));
        $this->assertEquals(0, StockCard::where('product_id', $this->produk->id)->count());
    }

    /**
     * Diskon header nominal melebihi subtotal di-CAP ke subtotal (recursive mode) → tidak negatif.
     * Subtotal 10jt, diskon nominal 15jt → hasil diskon = 10jt, total_setelah_diskon = 0, DPP = 0.
     * Tapi HPP (cost_per_unit) = modal item (10jt) karena diskon header TIDAK didorong ke detail.
     *
     */
    #[Test]
    public function header_nominal_discount_is_capped_at_subtotal()
    {
        $ulid = $this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
        ], ['diskon_1_tipe' => 'nominal', 'diskon_1_nilai' => 15000000]);

        $intake = DocSerialIntake::where('ulid', $ulid)->first();
        $this->assertEquals(10000000, (float) $intake->subtotal);
        $this->assertEquals(10000000, (float) $intake->total_diskon_header);
        $this->assertEquals(0, (float) $intake->total_setelah_diskon);
        $this->assertEquals(0, (float) $intake->dpp);
        $this->assertEquals(0, (float) $intake->grand_total);

        // HPP per unit = modal item (diskon header tidak menurunkan HPP) → 10jt
        $this->assertEquals(10000000, (float) SerialUnit::byProduct($this->produk->id)->first()->cost_per_unit);
    }

    /**
     * grand_total memperhitungkan pajak pembelian default (di-set eksplisit 11% di test ini),
     * dan hutang supplier dibuat dengan sisa_hutang = grand_total EKSAK (DPP + PPN).
     * 1 unit @10jt → DPP 10jt, PPN 11% = 1,1jt → grand 11,1jt. Pajak TIDAK masuk HPP → HPP 10jt.
     *
     */
    #[Test]
    public function grand_total_and_hutang_include_purchase_tax_exactly()
    {
        SettingService::set('tax.tax_purchase_percent', 11, 'integer');
        SettingService::set('tax.tax_purchase_included_in_hpp', false, 'boolean');

        $ulid = $this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
        ], ['tempo_hari' => 30]);

        $intake = DocSerialIntake::where('ulid', $ulid)->first();
        $this->assertEquals(11, (float) $intake->pajak_persen);
        $this->assertEquals(1100000, (float) $intake->pajak_nominal);
        $this->assertEquals(11100000, (float) $intake->grand_total);

        $this->approveIntake($ulid)->assertStatus(200);

        // Pajak tidak masuk HPP → tetap 10jt
        $this->assertEquals(10000000, (float) $this->produk->fresh()->avg_cost);

        $hutang = SupplierHutang::where('serial_intake_id', $intake->id)->first();
        $this->assertEquals(11100000, (float) $hutang->sisa_hutang);
        $this->assertEquals(11100000, (float) $hutang->nominal_awal);
        $this->assertEquals(0, (float) $hutang->nominal_terbayar);
        $this->assertSame('unpaid', $hutang->status);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }

    /**
     * Approve menandai SETIAP unit pending → tersedia (tidak ada yg tertinggal pending),
     * dan menautkan approved_by/approved_at. Sebelum approve semua pending.
     *
     */
    #[Test]
    public function approve_transitions_all_pending_units_to_tersedia()
    {
        $ulid = $this->draftUlid([
            ['serial_number' => 'SN-A', 'harga_modal' => 1000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 1000000],
            ['serial_number' => 'SN-C', 'harga_modal' => 1000000],
        ]);

        $this->assertEquals(3, SerialUnit::byProduct($this->produk->id)->where('status', 'pending')->count());

        $this->approveIntake($ulid)->assertStatus(200);

        $this->assertEquals(0, SerialUnit::byProduct($this->produk->id)->where('status', 'pending')->count());
        $this->assertEquals(3, SerialUnit::byProduct($this->produk->id)->tersedia()->count());

        $intake = DocSerialIntake::where('ulid', $ulid)->first();
        $this->assertSame('approved', $intake->status);
        $this->assertNotNull($intake->approved_at);
        $this->assertSame($this->admin->id, $intake->approved_by);
    }
}
