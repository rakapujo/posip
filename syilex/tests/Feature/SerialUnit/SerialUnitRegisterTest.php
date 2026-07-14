<?php

namespace Tests\Feature\SerialUnit;

use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Register Unit Serial (read-only) — list unit per produk + status + asal dokumen + ringkasan.
 */
class SerialUnitRegisterTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $produk;
    protected MasterWarehouse $wh;
    protected MasterSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['serial-intake.view', 'serial-intake.create', 'serial-intake.approve'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['serial-intake.view', 'serial-intake.create', 'serial-intake.approve']);
        $this->actingAs($this->admin);

        $this->produk = $this->serialProduct('LAP_M2', 'MacBook Air M2');

        $this->wh = MasterWarehouse::create([
            'kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang Utama',
            'is_saleable' => true, 'status' => 'active',
        ]);

        $this->supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP1', 'nama_supplier' => 'PT Test Supplier',
            'nama_pic' => 'Budi', 'telepon' => '08123456789', 'status' => 'active',
        ]);
    }

    private function serialProduct(string $kode, string $nama): MasterProduk
    {
        return MasterProduk::create([
            'kode_produk' => $kode, 'nama_produk' => $nama, 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
    }

    /** Buat intake + approve sehingga unit jadi 'tersedia' (relasi intake terisi). */
    private function approvedIntakeWithUnits(array $units): void
    {
        $filled = array_map(fn ($u) => array_merge([
            'harga_jual' => 1000, 'grade' => 'A', 'battery_condition' => 'Original',
            'battery_health' => 90, 'account_status' => 'unlocked',
        ], $u), $units);

        $ulid = $this->postJson('/api/v1/serial-intakes', [
            'product_id' => $this->produk->ulid,
            'warehouse_id' => $this->wh->ulid,
            'supplier_id' => $this->supplier->ulid,
            'units' => $filled,
        ])->json('data.serial_intake.ulid');

        $this->postJson("/api/v1/serial-intakes/{$ulid}/approve")->assertOk();
    }
    #[Test]
    public function register_lists_units_with_summary_and_source_doc()
    {
        $this->approvedIntakeWithUnits([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 20000000],
        ]);

        $res = $this->getJson('/api/v1/serial-units')->assertOk();
        $res->assertJsonPath('data.pagination.total', 2);
        $res->assertJsonPath('data.summary.total', 2);
        $res->assertJsonPath('data.summary.tersedia', 2);
        $res->assertJsonPath('data.summary.terjual', 0);

        // Tiap unit menyertakan nomor seri, produk, dan asal dokumen (intake)
        $this->assertNotNull($res->json('data.items.0.serial_number'));
        $this->assertNotNull($res->json('data.items.0.product.kode_produk'));
        $this->assertNotNull($res->json('data.items.0.intake.nomor_dokumen'));
    }
    #[Test]
    public function status_filter_narrows_list_but_summary_stays_global()
    {
        $this->approvedIntakeWithUnits([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 20000000],
        ]);
        SerialUnit::where('serial_number', 'SN-A')->first()->update(['status' => 'terjual']);

        $res = $this->getJson('/api/v1/serial-units?status=terjual')->assertOk();
        $res->assertJsonPath('data.pagination.total', 1);      // hanya SN-A
        $res->assertJsonPath('data.summary.total', 2);          // ringkasan tetap global
        $res->assertJsonPath('data.summary.tersedia', 1);
        $res->assertJsonPath('data.summary.terjual', 1);
    }
    #[Test]
    public function register_filters_by_product()
    {
        $this->approvedIntakeWithUnits([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
        ]);
        $other = $this->serialProduct('LAP_X', 'Other Serial');

        $this->getJson('/api/v1/serial-units?product_id=' . $this->produk->ulid)
            ->assertOk()->assertJsonPath('data.pagination.total', 1);
        $this->getJson('/api/v1/serial-units?product_id=' . $other->ulid)
            ->assertOk()->assertJsonPath('data.pagination.total', 0);
    }
    #[Test]
    public function register_requires_permission()
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/serial-units')->assertForbidden();
    }
    #[Test]
    public function search_matches_serial_number_substring_only()
    {
        $this->approvedIntakeWithUnits([
            ['serial_number' => 'IMEI-AAA-111', 'harga_modal' => 10000000],
            ['serial_number' => 'IMEI-BBB-222', 'harga_modal' => 20000000],
        ]);

        // Cocokkan potongan SN unik → tepat 1 unit
        $res = $this->getJson('/api/v1/serial-units?search=BBB')->assertOk();
        $res->assertJsonPath('data.pagination.total', 1);
        $this->assertSame('IMEI-BBB-222', $res->json('data.items.0.serial_number'));

        // Potongan yang dipakai dua-duanya → 2 unit, ringkasan ikut search (bukan global)
        $this->getJson('/api/v1/serial-units?search=IMEI')->assertOk()
            ->assertJsonPath('data.pagination.total', 2)
            ->assertJsonPath('data.summary.total', 2);

        // SN tak ada → 0; search adalah bagian filter base → ringkasan ikut nol (hanya STATUS yang global)
        $kosong = $this->getJson('/api/v1/serial-units?search=ZZZ')->assertOk();
        $kosong->assertJsonPath('data.pagination.total', 0);
        $kosong->assertJsonPath('data.summary.total', 0);
    }
    #[Test]
    public function warehouse_filter_narrows_units_but_summary_honors_it()
    {
        // 2 unit di gudang utama
        $this->approvedIntakeWithUnits([
            ['serial_number' => 'SN-W1-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-W1-B', 'harga_modal' => 11000000],
        ]);

        $wh2 = MasterWarehouse::create([
            'kode_warehouse' => 'WH2', 'nama_warehouse' => 'Gudang Cabang',
            'is_saleable' => true, 'status' => 'active',
        ]);
        // 1 unit di gudang cabang dipindah manual (status tetap tersedia)
        SerialUnit::where('serial_number', 'SN-W1-B')->first()->update(['warehouse_id' => $wh2->id]);

        // Filter gudang utama → hanya 1 unit, dan ringkasan ikut filter (total=1)
        $res = $this->getJson('/api/v1/serial-units?warehouse_id=' . $this->wh->id)->assertOk();
        $res->assertJsonPath('data.pagination.total', 1);
        $res->assertJsonPath('data.summary.total', 1);
        $res->assertJsonPath('data.summary.tersedia', 1);
        $this->assertSame('SN-W1-A', $res->json('data.items.0.serial_number'));

        // Filter gudang cabang → 1 unit lainnya
        $this->getJson('/api/v1/serial-units?warehouse_id=' . $wh2->id)->assertOk()
            ->assertJsonPath('data.pagination.total', 1)
            ->assertJsonPath('data.summary.total', 1);
    }
    #[Test]
    public function intake_filter_isolates_units_per_source_document()
    {
        $this->approvedIntakeWithUnits([['serial_number' => 'SN-INT1', 'harga_modal' => 10000000]]);
        $this->approvedIntakeWithUnits([
            ['serial_number' => 'SN-INT2-A', 'harga_modal' => 20000000],
            ['serial_number' => 'SN-INT2-B', 'harga_modal' => 21000000],
        ]);

        // Ambil intake_id (ulid) dari salah satu unit intake kedua
        $intakeUlid = SerialUnit::where('serial_number', 'SN-INT2-A')->first()->intake->ulid;

        $res = $this->getJson('/api/v1/serial-units?intake_id=' . $intakeUlid)->assertOk();
        $res->assertJsonPath('data.pagination.total', 2);   // hanya intake kedua
        $res->assertJsonPath('data.summary.total', 2);
        // semua item mengacu ke dokumen intake yang sama
        $this->assertSame($intakeUlid, $res->json('data.items.0.intake.ulid'));
        $this->assertSame($intakeUlid, $res->json('data.items.1.intake.ulid'));
    }
    #[Test]
    public function pagination_splits_pages_and_caps_per_page()
    {
        $units = [];
        for ($i = 1; $i <= 3; $i++) {
            $units[] = ['serial_number' => 'SN-PAGE-' . $i, 'harga_modal' => 1000000 * $i];
        }
        $this->approvedIntakeWithUnits($units);

        // per_page=2 → 2 halaman, total tetap 3
        $page1 = $this->getJson('/api/v1/serial-units?per_page=2&page=1')->assertOk();
        $page1->assertJsonPath('data.pagination.total', 3);
        $page1->assertJsonPath('data.pagination.last_page', 2);
        $page1->assertJsonPath('data.pagination.per_page', 2);
        $this->assertCount(2, $page1->json('data.items'));

        $page2 = $this->getJson('/api/v1/serial-units?per_page=2&page=2')->assertOk();
        $this->assertCount(1, $page2->json('data.items'));
        $page2->assertJsonPath('data.pagination.current_page', 2);
    }
    #[Test]
    public function sort_by_harga_modal_ascending_orders_units()
    {
        $this->approvedIntakeWithUnits([
            ['serial_number' => 'SN-MAHAL', 'harga_modal' => 30000000],
            ['serial_number' => 'SN-MURAH', 'harga_modal' => 5000000],
            ['serial_number' => 'SN-SEDANG', 'harga_modal' => 15000000],
        ]);

        $res = $this->getJson('/api/v1/serial-units?sort_field=harga_modal&sort_order=asc')->assertOk();
        $this->assertSame('SN-MURAH', $res->json('data.items.0.serial_number'));
        $this->assertSame('SN-SEDANG', $res->json('data.items.1.serial_number'));
        $this->assertSame('SN-MAHAL', $res->json('data.items.2.serial_number'));
    }
    #[Test]
    public function status_filter_with_unknown_value_returns_empty_list_but_global_summary()
    {
        $this->approvedIntakeWithUnits([
            ['serial_number' => 'SN-A', 'harga_modal' => 10000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 20000000],
        ]);

        // status tak dikenal → daftar kosong, tapi ringkasan global tetap utuh
        $res = $this->getJson('/api/v1/serial-units?status=ngawur')->assertOk();
        $res->assertJsonPath('data.pagination.total', 0);
        $res->assertJsonPath('data.summary.total', 2);
        $res->assertJsonPath('data.summary.tersedia', 2);
        $res->assertJsonPath('data.summary.terjual', 0);
    }
    #[Test]
    public function export_requires_view_permission()
    {
        $this->actingAs(User::factory()->create());
        $this->getJson('/api/v1/serial-units/export')->assertForbidden();
    }
    #[Test]
    public function export_returns_spreadsheet_download_for_authorized_user()
    {
        $this->approvedIntakeWithUnits([['serial_number' => 'SN-EXP', 'harga_modal' => 10000000]]);

        $res = $this->get('/api/v1/serial-units/export');
        $res->assertOk();
        $this->assertStringContainsString(
            'spreadsheetml.sheet',
            $res->headers->get('content-type') ?? ''
        );
    }
    #[Test]
    public function search_matches_kode_internal()
    {
        $this->approvedIntakeWithUnits([['serial_number' => 'SN-K', 'harga_modal' => 1000000]]);
        $kode = SerialUnit::where('serial_number', 'SN-K')->value('kode_internal');

        // Cari pakai kode_internal (identitas unik) → tepat unit itu
        $res = $this->getJson('/api/v1/serial-units?search=' . $kode)->assertOk();
        $res->assertJsonPath('data.pagination.total', 1);
        $this->assertSame($kode, $res->json('data.items.0.kode_internal'));
    }
    #[Test]
    public function status_filter_finds_rusak_units()
    {
        $this->approvedIntakeWithUnits([
            ['serial_number' => 'SN-A', 'harga_modal' => 1000000],
            ['serial_number' => 'SN-B', 'harga_modal' => 2000000],
        ]);
        SerialUnit::where('serial_number', 'SN-A')->first()->update(['status' => 'rusak']);

        // Unit rusak (mis. hasil adjustment-keluar) bisa difilter di Register
        $res = $this->getJson('/api/v1/serial-units?status=rusak')->assertOk();
        $res->assertJsonPath('data.pagination.total', 1);
        $this->assertSame('SN-A', $res->json('data.items.0.serial_number'));
    }

    // ─── Proteksi cost (modal/HPP) — hanya untuk yang berizin stok.view_hpp ───
    #[Test]
    public function index_hides_cost_fields_without_view_hpp()
    {
        // setUp admin punya serial-intake.view TAPI tidak punya stok.view_hpp
        $this->approvedIntakeWithUnits([['serial_number' => 'SN-COST', 'harga_modal' => 10000000]]);

        $item = $this->getJson('/api/v1/serial-units')->assertOk()->json('data.items.0');
        $this->assertArrayNotHasKey('harga_modal', $item);
        $this->assertArrayNotHasKey('cost_per_unit', $item);
        $this->assertArrayHasKey('harga_jual', $item); // harga jual bukan rahasia → tetap tampil
    }
    #[Test]
    public function index_shows_cost_fields_with_view_hpp()
    {
        Permission::firstOrCreate(['name' => 'stok.view_hpp', 'guard_name' => 'web']);
        $this->admin->givePermissionTo('stok.view_hpp');

        $this->approvedIntakeWithUnits([['serial_number' => 'SN-COST', 'harga_modal' => 10000000]]);

        $item = $this->getJson('/api/v1/serial-units')->assertOk()->json('data.items.0');
        $this->assertArrayHasKey('harga_modal', $item);
        $this->assertArrayHasKey('cost_per_unit', $item);
        $this->assertEquals(10000000, (float) $item['harga_modal']);
    }
    #[Test]
    public function available_hides_cost_fields_without_view_hpp()
    {
        // Picker Transfer/Adjustment/Retur/Opname tak boleh bocorkan modal/cost ke operator
        $this->approvedIntakeWithUnits([['serial_number' => 'SN-AVL', 'harga_modal' => 10000000]]);

        $item = $this->getJson('/api/v1/serial-units/available?product_id=' . $this->produk->ulid)
            ->assertOk()->json('data.items.0');
        $this->assertArrayNotHasKey('harga_modal', $item);
        $this->assertArrayNotHasKey('cost_per_unit', $item);
        $this->assertArrayHasKey('harga_jual', $item);
    }
    #[Test]
    public function available_shows_cost_fields_with_view_hpp()
    {
        Permission::firstOrCreate(['name' => 'stok.view_hpp', 'guard_name' => 'web']);
        $this->admin->givePermissionTo('stok.view_hpp');

        $this->approvedIntakeWithUnits([['serial_number' => 'SN-AVL', 'harga_modal' => 10000000]]);

        $item = $this->getJson('/api/v1/serial-units/available?product_id=' . $this->produk->ulid)
            ->assertOk()->json('data.items.0');
        $this->assertArrayHasKey('harga_modal', $item);
        $this->assertArrayHasKey('cost_per_unit', $item);
    }
}
