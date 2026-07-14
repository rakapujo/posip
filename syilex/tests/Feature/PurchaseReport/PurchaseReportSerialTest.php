<?php

namespace Tests\Feature\PurchaseReport;

use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Pembelian Serial wajib ikut di laporan Pembelian (PurchaseReportSource UNION PO + serial).
 * Filter "source" (all/po/serial) memisahkan sumber.
 */
class PurchaseReportSerialTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterProduk $produk;
    protected MasterWarehouse $wh;
    protected MasterSupplier $supplier;
    protected string $from = '2026-01-01';
    protected string $to = '2026-12-31';

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['laporan.pembelian', 'laporan.export', 'po.view_harga', 'serial-intake.view', 'serial-intake.create', 'serial-intake.approve'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['laporan.pembelian', 'laporan.export', 'po.view_harga', 'serial-intake.view', 'serial-intake.create', 'serial-intake.approve']);
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
            'kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang Utama', 'is_saleable' => true, 'status' => 'active',
        ]);
        $this->supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP1', 'nama_supplier' => 'PT Test Supplier',
            'nama_pic' => 'Budi', 'telepon' => '08123456789', 'status' => 'active',
        ]);
    }

    /** Buat + approve intake serial (diskon header 10%). Return nomor_dokumen. */
    private function approvedIntake(): string
    {
        $payload = [
            'product_id' => $this->produk->ulid,
            'warehouse_id' => $this->wh->ulid,
            'supplier_id' => $this->supplier->ulid,
            'diskon_1_tipe' => 'percent', 'diskon_1_nilai' => 10,
            'units' => [
                ['serial_number' => 'SN-A', 'harga_modal' => 10000000, 'harga_jual' => 12000000, 'grade' => 'A', 'battery_condition' => 'Original', 'battery_health' => 90, 'account_status' => 'unlocked'],
                ['serial_number' => 'SN-B', 'harga_modal' => 20000000, 'harga_jual' => 23000000, 'grade' => 'B', 'battery_condition' => 'Original', 'battery_health' => 85, 'account_status' => 'unlocked'],
            ],
        ];
        $res = $this->postJson('/api/v1/serial-intakes', $payload);
        $ulid = $res->json('data.serial_intake.ulid');
        $nomor = $res->json('data.serial_intake.nomor_dokumen');
        $this->postJson("/api/v1/serial-intakes/{$ulid}/approve")->assertOk();

        return $nomor;
    }

    private function q(string $path, array $extra = []): string
    {
        $params = array_merge(['date_from' => $this->from, 'date_to' => $this->to], $extra);

        return $path . '?' . http_build_query($params);
    }
    #[Test]
    public function per_dokumen_includes_serial_and_source_filter_works()
    {
        $nomor = $this->approvedIntake();

        // all → serial muncul, sumber 'serial'
        $res = $this->getJson($this->q('/api/v1/purchase-report/per-dokumen'))->assertOk();
        $nomors = collect($res->json('data.items'))->pluck('nomor_dokumen');
        $this->assertTrue($nomors->contains($nomor), 'Dokumen serial harus muncul di per-dokumen');
        $this->assertEquals('serial', collect($res->json('data.items'))->firstWhere('nomor_dokumen', $nomor)['sumber']);
        $this->assertEquals(1, $res->json('data.summary.jumlah_po'));

        // source=po → serial tak muncul (tak ada PO → kosong)
        $res = $this->getJson($this->q('/api/v1/purchase-report/per-dokumen', ['source' => 'po']))->assertOk();
        $this->assertEquals(0, $res->json('data.summary.jumlah_po'));

        // source=serial → hanya serial
        $res = $this->getJson($this->q('/api/v1/purchase-report/per-dokumen', ['source' => 'serial']))->assertOk();
        $this->assertEquals(1, $res->json('data.summary.jumlah_po'));
    }
    #[Test]
    public function per_supplier_grand_total_includes_serial()
    {
        $this->approvedIntake();

        $res = $this->getJson($this->q('/api/v1/purchase-report/per-supplier'))->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('kode_supplier', 'SUP1');
        $this->assertNotNull($row, 'Supplier harus muncul');
        // subtotal 30jt − diskon 10% (3jt) = DPP 27jt + PPN 11% = grand 29.97jt
        $this->assertEquals(29970000, (float) $row['total_grand_total']);
        $this->assertEquals(29970000, (float) $res->json('data.summary.total_grand_total'));
    }
    #[Test]
    public function per_barang_aggregates_serial_units()
    {
        $this->approvedIntake();

        $res = $this->getJson($this->q('/api/v1/purchase-report/per-barang'))->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('kode_produk', 'LAP_M2');
        $this->assertNotNull($row, 'Produk serial harus muncul');
        $this->assertEquals(2, (float) $row['total_qty']);          // 2 unit
        $this->assertEquals(30000000, (float) $row['total_subtotal']); // 10jt + 20jt
        $this->assertEquals(1, (float) $row['jumlah_po']);          // 1 dokumen
    }
    #[Test]
    public function diskon_includes_serial_header_discount()
    {
        $nomor = $this->approvedIntake();

        $res = $this->getJson($this->q('/api/v1/purchase-report/diskon'))->assertOk();
        $nomors = collect($res->json('data.items'))->pluck('nomor_dokumen');
        $this->assertTrue($nomors->contains($nomor), 'Dokumen serial berdiskon harus muncul');
        $this->assertEquals(3000000, (float) $res->json('data.summary.total_diskon')); // 10% dari 30jt
    }
    #[Test]
    public function harga_terakhir_uses_serial_latest_modal()
    {
        $this->approvedIntake();

        $res = $this->getJson($this->q('/api/v1/purchase-report/harga-terakhir'))->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('kode_produk', 'LAP_M2');
        $this->assertNotNull($row, 'Produk serial harus punya harga terakhir');
        $this->assertEquals('serial', $row['sumber']);
        $this->assertEquals(1, $res->json('data.summary.total_produk'));
    }
    #[Test]
    public function all_exports_run_without_error_with_serial_data()
    {
        $this->approvedIntake();

        foreach (['per-dokumen', 'per-supplier', 'per-barang', 'diskon', 'harga-terakhir'] as $type) {
            $this->get($this->q("/api/v1/purchase-report/{$type}/export"))->assertOk();
        }
    }
    #[Test]
    public function dropdowns_include_serial_only_supplier_and_warehouse()
    {
        // Supplier SUP1 + gudang WH1 hanya punya pembelian serial (tanpa PO biasa)
        $this->approvedIntake();

        $res = $this->getJson('/api/v1/purchase-report/dropdowns')->assertOk();
        $this->assertTrue(
            collect($res->json('data.suppliers'))->pluck('kode_supplier')->contains('SUP1'),
            'Supplier dengan pembelian serial harus muncul di dropdown filter'
        );
        $this->assertTrue(
            collect($res->json('data.warehouses'))->pluck('kode_warehouse')->contains('WH1'),
            'Gudang dengan pembelian serial harus muncul di dropdown filter'
        );
    }

    // ====================================================================
    // EDGE CASE tambahan — galak, eksak, lintas-invariant
    // ====================================================================

    /**
     * Approve intake serial WAJIB konsisten dengan kartu stok:
     *  - inventory_stock.qty = 2, stock_card PURCHASE qty_in = 2
     *  - data:verify (--fail-on-mismatch) = exit 0
     */
    public function test_approved_intake_keeps_stock_invariants_intact()
    {
        $this->approvedIntake();

        $purchaseCard = StockCard::where('transaction_type', 'PURCHASE')
            ->where('product_id', $this->produk->id)
            ->first();
        $this->assertNotNull($purchaseCard, 'Harus ada stock_card PURCHASE padanan');
        $this->assertSame(2, (int) $purchaseCard->qty_in);
        $this->assertSame(0, (int) $purchaseCard->qty_out);

        $exit = Artisan::call('data:verify', ['--fail-on-mismatch' => true]);
        $this->assertSame(0, $exit, 'data:verify harus 0 (tidak ada mismatch) setelah approve intake serial');
    }

    /** per-dokumen: dokumen serial punya details_count = jumlah unit (2). */
    public function test_per_dokumen_details_count_equals_unit_count()
    {
        $nomor = $this->approvedIntake();

        $res = $this->getJson($this->q('/api/v1/purchase-report/per-dokumen'))->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('nomor_dokumen', $nomor);
        $this->assertNotNull($row);
        $this->assertEquals(2, (int) $row['details_count']);
        // subtotal 30jt, diskon header 3jt, grand 29.97jt — eksak di level dokumen.
        $this->assertEquals(30000000, (float) $row['subtotal']);
        $this->assertEquals(3000000, (float) $row['total_diskon_header']);
        $this->assertEquals(29970000, (float) $row['grand_total']);
    }

    /** harga-terakhir memakai modal unit TERBARU (SN-B = 20jt, line_seq lebih besar). */
    public function test_harga_terakhir_uses_latest_unit_modal_exact_value()
    {
        $this->approvedIntake();

        $res = $this->getJson($this->q('/api/v1/purchase-report/harga-terakhir'))->assertOk();
        $row = collect($res->json('data.items'))->firstWhere('kode_produk', 'LAP_M2');
        $this->assertNotNull($row);
        // harga_per_unit = harga_modal unit terbaru (SN-B) = 20.000.000
        $this->assertEquals(20000000, (float) $row['harga_per_unit']);
        $this->assertEquals('UNIT', $row['unit_used']);
        $this->assertEquals(1, (float) $row['qty_in_unit']);
    }

    /**
     * Tanpa po.view_harga: laporan tetap menghitung jumlah_po, tapi field finansial
     * (total_grand_total) TIDAK muncul & can_view_harga=false.
     */
    public function test_per_supplier_hides_financials_without_view_harga()
    {
        $this->approvedIntake();

        Permission::firstOrCreate(['name' => 'laporan.pembelian', 'guard_name' => 'web']);
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('laporan.pembelian'); // tanpa po.view_harga

        $res = $this->actingAs($viewer)
            ->getJson($this->q('/api/v1/purchase-report/per-supplier'))
            ->assertOk();

        $this->assertFalse($res->json('data.can_view_harga'));
        $this->assertArrayNotHasKey('total_grand_total', $res->json('data.summary'));
        // jumlah dokumen tetap dihitung walau tanpa harga.
        $this->assertEquals(1, (int) $res->json('data.summary.total_po'));
    }

    /**
     * Laporan Diskon Pembelian 100% nilai uang → wajib po.view_harga.
     * Punya laporan.pembelian + laporan.export tapi tanpa po.view_harga → view & export 403.
     */
    public function test_diskon_requires_view_harga()
    {
        $this->approvedIntake();

        foreach (['laporan.pembelian', 'laporan.export'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(['laporan.pembelian', 'laporan.export']); // tanpa po.view_harga

        $this->actingAs($viewer)
            ->getJson($this->q('/api/v1/purchase-report/diskon'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get($this->q('/api/v1/purchase-report/diskon/export'))
            ->assertForbidden();
    }

    /** Tanpa laporan.pembelian → 403 (tiap endpoint laporan). */
    public function test_endpoints_forbidden_without_laporan_view()
    {
        $this->approvedIntake();

        $outsider = User::factory()->create(); // tanpa permission apa pun

        foreach (['per-dokumen', 'per-supplier', 'per-barang', 'diskon', 'harga-terakhir'] as $type) {
            $this->actingAs($outsider)
                ->getJson($this->q("/api/v1/purchase-report/{$type}"))
                ->assertForbidden();
        }
    }

    /** Rentang tanggal di LUAR periode intake → laporan kosong (boundary). */
    public function test_per_dokumen_empty_when_date_range_excludes_intake()
    {
        $this->approvedIntake();

        $url = '/api/v1/purchase-report/per-dokumen?' . http_build_query([
            'date_from' => '2099-01-01',
            'date_to' => '2099-12-31',
        ]);

        $res = $this->getJson($url)->assertOk();
        $this->assertSame([], $res->json('data.items'));
        $this->assertEquals(0, (int) $res->json('data.summary.jumlah_po'));
        $this->assertEquals(0, (float) $res->json('data.summary.total_grand_total'));
    }

    /** source=serial pada per-supplier menghasilkan grand total serial yang sama persis. */
    public function test_per_supplier_source_serial_isolates_serial_total()
    {
        $this->approvedIntake();

        $res = $this->getJson($this->q('/api/v1/purchase-report/per-supplier', ['source' => 'serial']))->assertOk();
        $this->assertEquals(29970000, (float) $res->json('data.summary.total_grand_total'));

        // source=po → tidak ada PO biasa → total 0
        $res = $this->getJson($this->q('/api/v1/purchase-report/per-supplier', ['source' => 'po']))->assertOk();
        $this->assertEquals(0, (float) $res->json('data.summary.total_grand_total'));
        $this->assertEquals(0, (int) $res->json('data.summary.total_po'));
    }
}
