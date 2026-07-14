<?php

namespace Tests\Feature\SerialIntake;

use App\Models\DocSerialIntake;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\User;
use App\Exports\SerialUnitExport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Export Excel Register Unit Serial — otorisasi (serial-intake.view) + hormati filter.
 */
class SerialUnitExportTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $produk;
    protected MasterWarehouse $wh;

    protected function setUp(): void
    {
        parent::setUp();
        Permission::firstOrCreate(['name' => 'serial-intake.view', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo('serial-intake.view');
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
    }

    private function seedUnits(): void
    {
        $intake = DocSerialIntake::create([
            'nomor_dokumen' => 'PBS-0001', 'tanggal' => now(),
            'product_id' => $this->produk->id, 'warehouse_id' => $this->wh->id,
            'total_unit' => 2, 'total_modal' => 30000000, 'status' => 'completed',
        ]);
        SerialUnit::create([
            'product_id' => $this->produk->id, 'warehouse_id' => $this->wh->id, 'intake_id' => $intake->id,
            'serial_number' => 'SN-AAA', 'harga_modal' => 15000000, 'cost_per_unit' => 15250000, 'harga_jual' => 18000000,
            'grade' => 'A', 'battery_condition' => 'Original', 'battery_health' => 90, 'account_status' => 'unlocked',
        ]);
        SerialUnit::create([
            'product_id' => $this->produk->id, 'warehouse_id' => $this->wh->id, 'intake_id' => $intake->id,
            'serial_number' => 'SN-BBB', 'harga_modal' => 15000000, 'cost_per_unit' => 15250000, 'harga_jual' => 18000000,
            'grade' => 'A', 'battery_condition' => 'Original', 'battery_health' => 88, 'account_status' => 'unlocked',
        ]);
    }
    #[Test]
    public function authorized_user_can_download_serial_unit_export()
    {
        Excel::fake();
        $this->seedUnits();

        $this->get('/api/v1/serial-units/export')->assertOk();

        Excel::assertDownloaded('register_unit_serial_' . date('Y-m-d_His') . '.xlsx', function ($export) {
            // Query export memuat kedua unit yang di-seed (hormati filter — di sini tanpa filter)
            return $export->query()->count() === 2;
        });
    }
    #[Test]
    public function export_respects_status_filter()
    {
        Excel::fake();
        $this->seedUnits();
        // Tandai satu unit terjual
        SerialUnit::where('serial_number', 'SN-AAA')->update(['status' => 'terjual']);

        $this->get('/api/v1/serial-units/export?status=tersedia')->assertOk();

        Excel::assertDownloaded('register_unit_serial_' . date('Y-m-d_His') . '.xlsx', function ($export) {
            return $export->query()->count() === 1;
        });
    }
    #[Test]
    public function user_without_permission_is_forbidden()
    {
        $other = User::factory()->create();
        $this->actingAs($other);

        $this->get('/api/v1/serial-units/export')->assertForbidden();
    }

    /** Produk serial kedua + 1 unit (untuk uji isolasi filter product_id). */
    private function seedSecondProductUnit(): MasterProduk
    {
        $produk2 = MasterProduk::create([
            'kode_produk' => 'LAP_M3', 'nama_produk' => 'MacBook Pro M3', 'status' => 'active',
            'is_serial' => true, 'minimum_stok' => 0, 'avg_cost' => 0, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
        SerialUnit::create([
            'product_id' => $produk2->id, 'warehouse_id' => $this->wh->id,
            'serial_number' => 'SN-PRO-1', 'harga_modal' => 25000000, 'cost_per_unit' => 25000000, 'harga_jual' => 30000000,
            'grade' => 'A', 'battery_condition' => 'Original', 'battery_health' => 95, 'account_status' => 'unlocked',
        ]);

        return $produk2;
    }

    /**
     * Filter product_id (ulid) mengisolasi unit milik produk itu saja.
     * Total 3 unit (2 produk-1 + 1 produk-2); filter produk-1 → tepat 2; filter produk-2 → tepat 1.
     *
     */
    #[Test]
    public function export_respects_product_filter()
    {
        Excel::fake();
        $this->seedUnits();                       // 2 unit produk-1
        $produk2 = $this->seedSecondProductUnit(); // 1 unit produk-2

        // Tanpa filter → 3
        $this->get('/api/v1/serial-units/export')->assertOk();
        Excel::assertDownloaded('register_unit_serial_' . date('Y-m-d_His') . '.xlsx', function ($export) {
            return $export->query()->count() === 3;
        });

        // Filter produk-1 → 2
        $this->get('/api/v1/serial-units/export?product_id=' . $this->produk->ulid)->assertOk();
        Excel::assertDownloaded('register_unit_serial_' . date('Y-m-d_His') . '.xlsx', function ($export) {
            return $export->query()->count() === 2;
        });

        // Filter produk-2 → 1
        $this->get('/api/v1/serial-units/export?product_id=' . $produk2->ulid)->assertOk();
        Excel::assertDownloaded('register_unit_serial_' . date('Y-m-d_His') . '.xlsx', function ($export) {
            return $export->query()->count() === 1;
        });
    }

    /**
     * Filter search (LIKE serial_number) hanya memuat baris yang cocok — eksak per SN.
     *
     */
    #[Test]
    public function export_respects_search_filter()
    {
        Excel::fake();
        $this->seedUnits(); // SN-AAA, SN-BBB

        $this->get('/api/v1/serial-units/export?search=BBB')->assertOk();
        Excel::assertDownloaded('register_unit_serial_' . date('Y-m-d_His') . '.xlsx', function ($export) {
            return $export->query()->count() === 1
                && $export->query()->first()->serial_number === 'SN-BBB';
        });
    }

    /**
     * Filter status yang tak punya baris → query 0 baris (boundary, bukan crash).
     *
     */
    #[Test]
    public function export_with_unmatched_status_yields_zero_rows()
    {
        Excel::fake();
        $this->seedUnits(); // semua status default 'tersedia'

        $this->get('/api/v1/serial-units/export?status=retur')->assertOk();
        Excel::assertDownloaded('register_unit_serial_' . date('Y-m-d_His') . '.xlsx', function ($export) {
            return $export->query()->count() === 0;
        });
    }

    /**
     * map() memetakan kolom dengan urutan & nilai EKSAK: No, SN, status UPPERCASE,
     * harga_modal, modal landed (cost_per_unit), harga_jual, grade, dst.
     *
     */
    #[Test]
    public function export_mapping_produces_exact_row_values()
    {
        $this->seedUnits();
        $unit = SerialUnit::where('serial_number', 'SN-AAA')
            ->with(['product:id,kode_produk,nama_produk', 'warehouse:id,nama_warehouse', 'intake:id,nomor_dokumen,tanggal'])
            ->first();

        // canViewHpp: true → kolom cost (modal + landed) ikut tampil
        $export = new SerialUnitExport(canViewHpp: true);
        $row = $export->map($unit);

        $this->assertSame(1, $row[0]);                                  // No (rowNumber pertama)
        $this->assertSame($unit->kode_internal, $row[1]);               // Kode Internal (auto KI-{id})
        $this->assertStringStartsWith('KI-', $row[1]);
        $this->assertSame('SN-AAA', $row[2]);                           // Nomor Seri
        $this->assertSame('[LAP_M2] MacBook Air M2', $row[3]);          // Produk
        $this->assertSame('TERSEDIA', $row[4]);                         // Status (UPPERCASE)
        $this->assertEquals(15000000, (float) $row[5]);                 // Harga Modal
        $this->assertEquals(15250000, (float) $row[6]);                 // Modal Landed (cost_per_unit)
        $this->assertEquals(18000000, (float) $row[7]);                 // Harga Jual
        $this->assertSame('A', $row[8]);                               // Grade
        $this->assertSame('Original', $row[9]);                         // Baterai
        $this->assertEquals(90, (float) $row[10]);                      // Health
        $this->assertSame('unlocked', $row[11]);                        // Akun
        $this->assertSame('Gudang Utama', $row[12]);                    // Gudang
        $this->assertSame('PBS-0001', $row[13]);                        // Asal Dokumen
        $this->assertSame('-', $row[15]);                               // Terjual (belum) → '-'
    }

    /**
     * Tanpa izin lihat HPP: kolom cost (Harga Modal + Modal Landed) DIHILANGKAN dari export,
     * indeks kolom bergeser; Harga Jual tetap muncul.
     *
     */
    #[Test]
    public function export_hides_cost_columns_without_view_hpp()
    {
        $this->seedUnits();
        $unit = SerialUnit::where('serial_number', 'SN-AAA')
            ->with(['product:id,kode_produk,nama_produk', 'warehouse:id,nama_warehouse', 'intake:id,nomor_dokumen,tanggal'])
            ->first();

        // Default canViewHpp = false → cost disembunyikan
        $export = new SerialUnitExport();

        $this->assertNotContains('Harga Modal', $export->headings());
        $this->assertNotContains('Modal Landed (HPP)', $export->headings());
        $this->assertContains('Harga Jual', $export->headings());

        $row = $export->map($unit);
        // Tanpa 2 kolom cost → setelah Status (row[4]) langsung Harga Jual
        $this->assertSame('TERSEDIA', $row[4]);
        $this->assertEquals(18000000, (float) $row[5]);                 // Harga Jual (bergeser dari index 7)
        $this->assertSame('A', $row[6]);                               // Grade
        // Tidak ada nilai modal/cost di mana pun
        $this->assertNotContains(15000000, $row);
        $this->assertNotContains(15250000, $row);
    }
}
