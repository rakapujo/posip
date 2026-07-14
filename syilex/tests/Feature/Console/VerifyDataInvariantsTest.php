<?php

namespace Tests\Feature\Console;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

class VerifyDataInvariantsTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_returns_ok_on_clean_database(): void
    {
        $exit = $this->artisan('data:verify')->run();
        $this->assertEquals(0, $exit);
    }

    public function test_json_output_structure(): void
    {
        \Artisan::call('data:verify', ['--json' => true]);
        $output = \Artisan::output();
        $json = json_decode($output, true);

        $this->assertIsArray($json);
        $this->assertArrayHasKey('status', $json);
        $this->assertArrayHasKey('report', $json);
        $this->assertArrayHasKey('stock_consistency', $json['report']);
        $this->assertArrayHasKey('sales_payment_totals', $json['report']);
        $this->assertArrayHasKey('hutang_ledger', $json['report']);
    }

    public function test_stock_consistency_detects_drift(): void
    {
        $user = User::factory()->create();

        $warehouseId = DB::table('master_warehouse')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_warehouse' => 'WH1',
            'nama_warehouse' => 'WH 1',
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('master_produk')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_produk' => 'P001',
            'nama_produk' => 'Produk 1',
            'unit_1' => 'PCS',
            'unit_2' => 'PCS',
            'unit_3' => 'PCS',
            'unit_4' => 'PCS',
            'konversi_1' => 1,
            'konversi_2' => 1,
            'konversi_3' => 1,
            'konversi_4' => 1,
            'harga_1' => 1000, 'harga_2' => 1000, 'harga_3' => 1000, 'harga_4' => 1000,
            'avg_cost' => 0,
            'minimum_stok' => 0,
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // stock_card says 10, inventory_stock says 15 — drift!
        DB::table('stock_card')->insert([
            'ulid' => (string) Str::ulid(),
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'transaction_type' => 'PURCHASE',
            'transaction_no' => 'PO-1',
            'tanggal' => now(),
            'qty_in' => 10,
            'qty_out' => 0,
            'qty_balance' => 10,
            'cost_per_unit' => 500,
            'total_cost' => 5000,
            'avg_cost_before' => 0,
            'avg_cost_after' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_stock')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'qty' => 15, // drift: should be 10
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Artisan::call('data:verify', ['--json' => true]);
        $json = json_decode(\Artisan::output(), true);
        $this->assertEquals('mismatch', $json['status']);
        $this->assertEquals(1, $json['report']['stock_consistency']['mismatches']);

        // With --fail-on-mismatch, returns 1
        $exit = $this->artisan('data:verify', ['--fail-on-mismatch' => true])->run();
        $this->assertEquals(1, $exit);
    }

    // ───────────────────────── EDGE CASE TAMBAHAN (galak) ─────────────────────────

    private function decodeJson(): array
    {
        \Artisan::call('data:verify', ['--json' => true]);
        return json_decode(\Artisan::output(), true);
    }
    #[Test]
    public function database_bersih_status_ok_dan_semua_check_nol_mismatch(): void
    {
        $json = $this->decodeJson();

        $this->assertSame('ok', $json['status']);
        foreach (['stock_consistency', 'sales_payment_totals', 'hutang_ledger',
                  'serial_stock_consistency', 'serial_sold_integrity'] as $check) {
            $this->assertArrayHasKey($check, $json['report'], "Check '{$check}' harus ada");
            $this->assertSame(0, $json['report'][$check]['mismatches'], "Check '{$check}' harus 0 mismatch saat bersih");
        }
    }
    #[Test]
    public function tanpa_flag_fail_on_mismatch_exit_tetap_nol_meski_ada_drift(): void
    {
        // By design: mismatch hanya bikin exit 1 KALAU --fail-on-mismatch dipasang.
        $this->seedStockDrift(stored: 7, computed: 3);

        $exit = $this->artisan('data:verify')->run();
        $this->assertSame(0, $exit, 'Tanpa --fail-on-mismatch exit harus 0 meski drift');

        $json = $this->decodeJson();
        $this->assertSame('mismatch', $json['status']);
    }
    #[Test]
    public function stock_consistency_sample_diff_eksak(): void
    {
        // stored 12 vs computed 5 → diff +7, satu sample.
        $ids = $this->seedStockDrift(stored: 12, computed: 5);

        $json = $this->decodeJson();
        $sc = $json['report']['stock_consistency'];

        $this->assertSame(1, $sc['mismatches']);
        $this->assertCount(1, $sc['samples']);
        $this->assertSame($ids['product_id'], $sc['samples'][0]['product_id']);
        $this->assertSame($ids['warehouse_id'], $sc['samples'][0]['warehouse_id']);
        $this->assertSame(12, $sc['samples'][0]['stored']);
        $this->assertSame(5, $sc['samples'][0]['computed']);
        $this->assertSame(7, $sc['samples'][0]['diff']);
    }
    #[Test]
    public function sales_payment_mendeteksi_under_payment(): void
    {
        // grand_total + biaya = 100k+5k = 105k; paid 90k → shortfall 15k.
        $ctx = $this->seedSalesContext();
        $salesId = DB::table('doc_sales')->insertGetId($this->salesRow($ctx, [
            'grand_total' => 100000,
            'total_biaya_pembayaran' => 5000,
            'status' => 'completed',
        ]));
        DB::table('doc_sales_payments')->insert([
            'sales_id' => $salesId,
            'metode_pembayaran_id' => $ctx['metode_id'],
            'nominal' => 90000,
            'biaya_tambahan' => 0,
        ]);

        $json = $this->decodeJson();
        $sp = $json['report']['sales_payment_totals'];

        $this->assertSame('mismatch', $json['status']);
        $this->assertSame(1, $sp['mismatches']);
        // JSON membuang ".0" pada float bulat → bandingkan nilai eksak via cast float.
        $this->assertSame(15000.0, (float) $sp['samples'][0]['shortfall']);
        $this->assertSame(90000.0, (float) $sp['samples'][0]['paid_total']);

        $exit = $this->artisan('data:verify', ['--fail-on-mismatch' => true])->run();
        $this->assertSame(1, $exit);
    }
    #[Test]
    public function sales_payment_over_payment_dianggap_konsisten(): void
    {
        // Over-payment (kembalian) BUKAN mismatch: paid >= grand_total+biaya.
        $ctx = $this->seedSalesContext();
        $salesId = DB::table('doc_sales')->insertGetId($this->salesRow($ctx, [
            'grand_total' => 100000,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
        ]));
        DB::table('doc_sales_payments')->insert([
            'sales_id' => $salesId,
            'metode_pembayaran_id' => $ctx['metode_id'],
            'nominal' => 150000, // bayar lebih → kembalian
            'biaya_tambahan' => 0,
        ]);

        $json = $this->decodeJson();
        $this->assertSame('ok', $json['status']);
        $this->assertSame(0, $json['report']['sales_payment_totals']['mismatches']);
    }
    #[Test]
    public function sales_payment_void_diabaikan_dari_pengecekan(): void
    {
        // Sale voided tanpa pembayaran TIDAK boleh dihitung mismatch (filter status=completed).
        $ctx = $this->seedSalesContext();
        DB::table('doc_sales')->insert($this->salesRow($ctx, [
            'grand_total' => 100000,
            'total_biaya_pembayaran' => 0,
            'status' => 'voided',
        ]));

        $json = $this->decodeJson();
        $this->assertSame(0, $json['report']['sales_payment_totals']['checked'], 'Voided tidak ikut diperiksa');
        $this->assertSame(0, $json['report']['sales_payment_totals']['mismatches']);
    }
    #[Test]
    public function hutang_ledger_mendeteksi_sisa_yang_salah(): void
    {
        // nominal_awal 100k, tanpa pembayaran → expected sisa 100k; stored 40k → diff -60k.
        $supplierId = $this->seedSupplier();
        DB::table('supplier_hutang')->insert([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $supplierId,
            'po_id' => null,
            'serial_intake_id' => null,
            'tanggal' => now()->toDateString(),
            'nominal_awal' => 100000,
            'nominal_terbayar' => 0,
            'sisa_hutang' => 40000, // SALAH: harusnya 100000
            'status' => 'partial',
            'created_at' => now(),
        ]);

        $json = $this->decodeJson();
        $hl = $json['report']['hutang_ledger'];

        $this->assertSame('mismatch', $json['status']);
        $this->assertSame(1, $hl['mismatches']);
        $this->assertSame(100000.0, (float) $hl['samples'][0]['expected_sisa']);
        $this->assertSame(40000.0, (float) $hl['samples'][0]['stored_sisa']);
        $this->assertSame(-60000.0, (float) $hl['samples'][0]['diff']);
    }
    #[Test]
    public function hutang_ledger_sisa_benar_dianggap_konsisten(): void
    {
        $supplierId = $this->seedSupplier();
        DB::table('supplier_hutang')->insert([
            'ulid' => (string) Str::ulid(),
            'supplier_id' => $supplierId,
            'po_id' => null,
            'serial_intake_id' => null,
            'tanggal' => now()->toDateString(),
            'nominal_awal' => 100000,
            'nominal_terbayar' => 0,
            'sisa_hutang' => 100000, // benar
            'status' => 'unpaid',
            'created_at' => now(),
        ]);

        $json = $this->decodeJson();
        $this->assertSame('ok', $json['status']);
        $this->assertSame(0, $json['report']['hutang_ledger']['mismatches']);
    }
    #[Test]
    public function serial_stock_consistency_mendeteksi_selisih_unit_vs_inventory(): void
    {
        // Produk serial: inventory.qty=3 tapi unit 'tersedia' cuma 2 → diff +1.
        $ids = $this->seedSerialProduct();

        DB::table('inventory_stock')->insert([
            'product_id' => $ids['product_id'],
            'warehouse_id' => $ids['warehouse_id'],
            'qty' => 3,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        // 2 unit tersedia + 1 terjual (tidak dihitung)
        $this->insertSerialUnit($ids, 'SN-1', 'tersedia', null);
        $this->insertSerialUnit($ids, 'SN-2', 'tersedia', null);
        $this->insertSerialUnit($ids, 'SN-3', 'terjual', 999);

        $json = $this->decodeJson();
        $ssc = $json['report']['serial_stock_consistency'];

        $this->assertSame('mismatch', $json['status']);
        $this->assertSame(1, $ssc['mismatches']);
        $this->assertSame(3, $ssc['samples'][0]['inventory_qty']);
        $this->assertSame(2, $ssc['samples'][0]['available_units']);
        $this->assertSame(1, $ssc['samples'][0]['diff']);
    }
    #[Test]
    public function serial_sold_integrity_terjual_tanpa_sale_id_terdeteksi(): void
    {
        // Unit status 'terjual' tapi sale_id NULL → pelanggaran integritas.
        $ids = $this->seedSerialProduct();
        $this->insertSerialUnit($ids, 'SN-BAD', 'terjual', null); // BAD
        $this->insertSerialUnit($ids, 'SN-OK', 'tersedia', null); // OK

        $json = $this->decodeJson();
        $ssi = $json['report']['serial_sold_integrity'];

        $this->assertSame('mismatch', $json['status']);
        $this->assertSame(2, $ssi['checked'], 'total unit non-trashed');
        $this->assertSame(1, $ssi['mismatches']);
        $this->assertSame('SN-BAD', $ssi['samples'][0]['serial_number']);
        $this->assertSame('terjual', $ssi['samples'][0]['status']);
    }
    #[Test]
    public function serial_sold_integrity_tersedia_dengan_sale_id_terdeteksi(): void
    {
        // Arah sebaliknya: unit 'tersedia' tapi punya sale_id → juga pelanggaran.
        $ids = $this->seedSerialProduct();
        $this->insertSerialUnit($ids, 'SN-GHOST', 'tersedia', 555); // BAD

        $json = $this->decodeJson();
        $ssi = $json['report']['serial_sold_integrity'];

        $this->assertSame(1, $ssi['mismatches']);
        $this->assertSame('SN-GHOST', $ssi['samples'][0]['serial_number']);
        $this->assertSame('tersedia', $ssi['samples'][0]['status']);
    }

    // ───────────────────────── helper seeding ─────────────────────────

    /**
     * Buat produk + gudang + drift stok (stock_card vs inventory_stock).
     * @return array{product_id:int,warehouse_id:int}
     */
    private function seedStockDrift(int $stored, int $computed): array
    {
        $user = User::factory()->create();

        $warehouseId = DB::table('master_warehouse')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_warehouse' => 'WHX',
            'nama_warehouse' => 'WH X',
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $productId = DB::table('master_produk')->insertGetId($this->produkRow('PX', $user->id));

        DB::table('stock_card')->insert([
            'ulid' => (string) Str::ulid(),
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'transaction_type' => 'PURCHASE',
            'transaction_no' => 'PO-X',
            'tanggal' => now(),
            'qty_in' => $computed,
            'qty_out' => 0,
            'qty_balance' => $computed,
            'cost_per_unit' => 500,
            'total_cost' => 500 * $computed,
            'avg_cost_before' => 0,
            'avg_cost_after' => 500,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inventory_stock')->insert([
            'product_id' => $productId,
            'warehouse_id' => $warehouseId,
            'qty' => $stored,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['product_id' => $productId, 'warehouse_id' => $warehouseId];
    }

    /**
     * Produk is_serial + gudang (tanpa stok dulu).
     * @return array{product_id:int,warehouse_id:int}
     */
    private function seedSerialProduct(): array
    {
        $user = User::factory()->create();
        $warehouseId = DB::table('master_warehouse')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_warehouse' => 'WHS',
            'nama_warehouse' => 'WH Serial',
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $productId = DB::table('master_produk')->insertGetId(
            array_merge($this->produkRow('PS', $user->id), ['is_serial' => true])
        );

        return ['product_id' => $productId, 'warehouse_id' => $warehouseId];
    }

    private function insertSerialUnit(array $ids, string $sn, string $status, ?int $saleId): void
    {
        DB::table('serial_units')->insert([
            'ulid' => (string) Str::ulid(),
            'product_id' => $ids['product_id'],
            'warehouse_id' => $ids['warehouse_id'],
            'serial_number' => $sn,
            'harga_modal' => 1000,
            'status' => $status,
            'sale_id' => $saleId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function produkRow(string $kode, int $userId): array
    {
        return [
            'ulid' => (string) Str::ulid(),
            'kode_produk' => $kode,
            'nama_produk' => 'Produk ' . $kode,
            'unit_1' => 'PCS', 'unit_2' => 'PCS', 'unit_3' => 'PCS', 'unit_4' => 'PCS',
            'konversi_1' => 1, 'konversi_2' => 1, 'konversi_3' => 1, 'konversi_4' => 1,
            'harga_1' => 1000, 'harga_2' => 1000, 'harga_3' => 1000, 'harga_4' => 1000,
            'avg_cost' => 0,
            'minimum_stok' => 0,
            'status' => 'active',
            'created_by' => $userId,
            'updated_by' => $userId,
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function salesRow(array $ctx, array $overrides): array
    {
        return array_merge([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . Str::random(8),
            'tanggal' => now(),
            'terminal_id' => $ctx['terminal_id'],
            'shift_id' => $ctx['shift_id'],
            'warehouse_id' => $ctx['warehouse_id'],
            'customer_id' => $ctx['customer_id'],
            'grand_total' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides);
    }

    /**
     * Buat seluruh rantai FK yang dibutuhkan doc_sales (warehouse→terminal→shift,
     * customer, metode_pembayaran). FK SQLite aktif & tidak bisa dimatikan di dalam
     * transaksi RefreshDatabase, jadi target FK harus benar-benar ada.
     *
     * @return array{warehouse_id:int,terminal_id:int,shift_id:int,customer_id:int,metode_id:int}
     */
    private function seedSalesContext(): array
    {
        $user = User::factory()->create();

        $warehouseId = DB::table('master_warehouse')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_warehouse' => 'WHSAL',
            'nama_warehouse' => 'WH Sales',
            'status' => 'active',
            'created_by' => $user->id,
            'updated_by' => $user->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST1',
            'nama' => 'Walk In',
            'telepon' => '08120000000',
            'jenis' => 'walk_in',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $metodeId = DB::table('master_metode_pembayaran')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'TUNAI',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $terminalId = DB::table('master_pos_terminal')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM1',
            'nama_terminal' => 'Terminal 1',
            'warehouse_id' => $warehouseId,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $shiftId = DB::table('pos_terminal_shifts')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminalId,
            'user_id' => $user->id,
            'started_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'warehouse_id' => $warehouseId,
            'terminal_id' => $terminalId,
            'shift_id' => $shiftId,
            'customer_id' => $customerId,
            'metode_id' => $metodeId,
        ];
    }

    private function seedSupplier(): int
    {
        return DB::table('master_supplier')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUPV',
            'nama_supplier' => 'PT Verify',
            'nama_pic' => 'PIC',
            'telepon' => '0812',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
