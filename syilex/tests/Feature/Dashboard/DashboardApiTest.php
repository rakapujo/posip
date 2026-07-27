<?php

namespace Tests\Feature\Dashboard;

use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterWarehouse;
use App\Models\PosTerminalShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 1 — Dashboard widget permission gating (per-section visibility).
 * Wave B B1.1/B1.2 — pendapatan_line dual metric + payment_methods tunai net.
 */
class DashboardApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'laporan.view', 'stok.view_hpp', 'produk.view', 'stok.view',
            'hutang.view_nominal', 'po.view',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    /**
     * Bootstrap warehouse + terminal + shift + customer + tunai method, siap dipakai
     * bikin doc_sales/doc_sales_payments untuk test pendapatan_line & tunai net.
     *
     * @return array{terminal_id: int, shift_id: int, warehouse_id: int, customer_id: int, cash_method_id: int}
     */
    private function bootstrapPosFixtures(User $creator): array
    {
        $warehouse = MasterWarehouse::factory()->create(['created_by' => $creator->id]);

        $cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'jenis' => null,
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-DB',
            'nama_terminal' => 'Dashboard Test',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $creator->id,
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        $shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $terminal->id,
            'user_id' => $creator->id,
            'started_at' => now(),
        ]);

        $customerId = DB::table('master_customer')->insertGetId([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-DB',
            'nama' => 'Walk-in',
            'telepon' => '08000',
            'status' => 'active',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'terminal_id' => $terminal->id,
            'shift_id' => $shift->id,
            'warehouse_id' => $warehouse->id,
            'customer_id' => $customerId,
            'cash_method_id' => $cash->id,
        ];
    }

    private function makeSale(array $fx, User $creator, array $overrides = []): int
    {
        return DB::table('doc_sales')->insertGetId(array_merge([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-'.fake()->unique()->numerify('######'),
            'tanggal' => now()->toDateTimeString(),
            'terminal_id' => $fx['terminal_id'],
            'shift_id' => $fx['shift_id'],
            'warehouse_id' => $fx['warehouse_id'],
            'customer_id' => $fx['customer_id'],
            'subtotal' => 100_000,
            'total_setelah_diskon' => 100_000,
            'total_diskon' => 0,
            'grand_total' => 100_000,
            'total_bayar' => 100_000,
            'kembalian' => 0,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $creator->id,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));
    }

    public function test_requires_auth(): void
    {
        $this->getJson('/api/v1/dashboard')
            ->assertUnauthorized();
    }

    public function test_laporan_view_alone_exposes_sales_today_omzet(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.view');

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('sales_today', $data);
        $this->assertArrayHasKey('count', $data['sales_today']);
        $this->assertArrayHasKey('omzet', $data['sales_today']);
    }

    public function test_stok_view_hpp_alone_does_not_expose_sales_today(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('stok.view_hpp');

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('sales_today', $data);
    }

    public function test_without_laporan_view_no_sales_today_key(): void
    {
        $user = User::factory()->create();
        // No permission granted at all.

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('sales_today', $data);
    }

    public function test_produk_view_exposes_products_total_active(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('produk.view');

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('products', $data);
        $this->assertArrayHasKey('total_active', $data['products']);
    }

    public function test_without_produk_view_no_products_key(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('products', $data);
    }

    public function test_hutang_view_nominal_exposes_hutang_total(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('hutang.view_nominal');

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('hutang', $data);
        $this->assertArrayHasKey('total', $data['hutang']);
    }

    public function test_without_hutang_view_nominal_no_hutang_key(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('hutang', $data);
    }

    public function test_po_view_exposes_po_pending(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('po.view');

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('po_pending', $data);
    }

    public function test_without_po_view_no_po_pending_key(): void
    {
        $user = User::factory()->create();

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayNotHasKey('po_pending', $data);
    }

    public function test_stok_view_exposes_stock_low_stock_count(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('stok.view');

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertArrayHasKey('stock', $data);
        $this->assertArrayHasKey('low_stock_count', $data['stock']);
        $this->assertArrayHasKey('low_stock_items', $data);
    }

    // ─── B1.1 Dashboard dual metric (omzet vs pendapatan_line) ────────────

    public function test_pendapatan_line_dikurangi_diskon_nota_beda_dari_omzet_grand_total(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.view');
        $fx = $this->bootstrapPosFixtures($user);

        // Nota disc 10%: subtotal 100k -> grand_total 90k. Line jumlah (pre-disc) 100k.
        $salesId = $this->makeSale($fx, $user, [
            'subtotal' => 100_000,
            'total_setelah_diskon' => 90_000,
            'total_diskon' => 10_000,
            'grand_total' => 90_000,
            'total_bayar' => 90_000,
        ]);
        DB::table('doc_sales_detail')->insert([
            'sales_id' => $salesId,
            'product_id' => \App\Models\MasterProduk::factory()->create(['avg_cost' => 0])->id,
            'unit' => 'PCS', 'konversi' => 1,
            'qty' => 1, 'qty_base' => 1,
            'harga_satuan' => 100_000, 'diskon_total' => 0, 'jumlah' => 100_000,
            'hpp_at_time' => 0,
        ]);

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertEquals(90_000, $data['sales_today']['omzet']);
        // pendapatan_line = jumlah * (total_setelah_diskon / subtotal) = 100_000 * 0.9
        $this->assertEquals(90_000, $data['sales_today']['pendapatan_line']);
    }

    public function test_pendapatan_line_nol_saat_tidak_ada_penjualan(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.view');

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $this->assertEquals(0, $data['sales_today']['pendapatan_line']);
    }

    // ─── B1.2 Chart Tunai net (kurangi kembalian) ─────────────────────────

    public function test_payment_methods_chart_tunai_dikurangi_kembalian(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('laporan.view');
        $fx = $this->bootstrapPosFixtures($user);

        // Bayar tunai 115.000 utk belanja 100.000 -> kembalian 15.000. Tunai net = 100.000.
        $salesId = $this->makeSale($fx, $user, [
            'grand_total' => 100_000,
            'total_bayar' => 115_000,
            'kembalian' => 15_000,
        ]);
        DB::table('doc_sales_payments')->insert([
            'sales_id' => $salesId,
            'metode_pembayaran_id' => $fx['cash_method_id'],
            'nominal' => 115_000,
            'biaya_tambahan' => 0,
        ]);

        $data = $this->actingAs($user)
            ->getJson('/api/v1/dashboard')
            ->assertOk()
            ->json('data');

        $tunai = collect($data['payment_methods'])->firstWhere('label', 'Tunai');
        $this->assertNotNull($tunai);
        $this->assertEquals(100_000, $tunai['total']);
    }
}
