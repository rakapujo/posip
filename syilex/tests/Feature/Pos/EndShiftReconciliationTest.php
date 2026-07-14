<?php

namespace Tests\Feature\Pos;

use App\Models\DocSales;
use App\Models\DocSalesPayment;
use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterPosTerminal;
use App\Models\MasterWarehouse;
use App\Models\PosCashTransaction;
use App\Models\PosTerminalShift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class EndShiftReconciliationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;
    protected MasterPosTerminal $terminal;
    protected PosTerminalShift $shift;
    protected MasterMetodePembayaran $cash;
    protected MasterWarehouse $warehouse;
    protected MasterCustomer $customer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->actingAs($this->user);

        $this->warehouse = MasterWarehouse::factory()->create(['created_by' => $this->user->id]);

        $this->cash = MasterMetodePembayaran::create([
            'ulid' => (string) Str::ulid(),
            'kode_pembayaran' => 'CASH',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'jenis' => null,
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->customer = MasterCustomer::create([
            'ulid' => (string) Str::ulid(),
            'kode_customer' => 'CUST-WALK',
            'nama' => 'Walk In',
            'telepon' => '08123456789',
            'jenis' => 'walk_in',
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->terminal = MasterPosTerminal::create([
            'ulid' => (string) Str::ulid(),
            'kode_terminal' => 'TRM-X',
            'nama_terminal' => 'Kasir Test',
            'warehouse_id' => $this->warehouse->id,
            'default_metode_pembayaran_id' => $this->cash->id,
            'active_user_id' => $this->user->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $this->shift = PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => $this->terminal->id,
            'user_id' => $this->user->id,
            'started_at' => now(),
        ]);
    }

    private function makeCashTx(string $tipe, float $nominal, ?string $keterangan = null): void
    {
        PosCashTransaction::create([
            'ulid' => (string) Str::ulid(),
            'shift_id' => $this->shift->id,
            'terminal_id' => $this->terminal->id,
            'tipe' => $tipe,
            'nominal' => $nominal,
            'keterangan' => $keterangan,
            'created_by' => $this->user->id,
        ]);
    }

    /**
     * Buat penjualan tunai 'completed' pada shift ini dengan pembayaran tunai $nominalBayar
     * dan kembalian $kembalian. Net cash = $nominalBayar - $kembalian.
     */
    private function makeCashSale(float $grandTotal, float $nominalBayar, float $kembalian): void
    {
        $sale = DocSales::create([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-' . Str::random(6),
            'tanggal' => now(),
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'subtotal' => $grandTotal,
            'total_diskon' => 0,
            'total_setelah_diskon' => $grandTotal,
            'dpp' => $grandTotal,
            'pajak_nominal' => 0,
            'pembulatan' => 0,
            'grand_total' => $grandTotal,
            'total_bayar' => $nominalBayar,
            'kembalian' => $kembalian,
            'total_biaya_pembayaran' => 0,
            'status' => 'completed',
            'created_by' => $this->user->id,
        ]);

        DocSalesPayment::create([
            'sales_id' => $sale->id,
            'metode_pembayaran_id' => $this->cash->id,
            'nominal' => $nominalBayar,
            'biaya_tambahan' => 0,
        ]);
    }

    public function test_end_shift_without_reconciliation_still_works(): void
    {
        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift")
            ->assertOk();

        $this->shift->refresh();
        $this->assertNotNull($this->shift->ended_at);
        $this->assertEquals(0.0, (float) $this->shift->saldo_system);
        $this->assertNull($this->shift->saldo_fisik);
        $this->assertNull($this->shift->selisih);
    }

    public function test_end_shift_computes_saldo_system_from_cash_transactions(): void
    {
        $this->makeCashTx('setor_awal', 100_000);
        $this->makeCashTx('kas_masuk', 50_000);
        $this->makeCashTx('kas_keluar', 20_000, 'Beli galon');
        $this->makeCashTx('kas_keluar', 30_000, 'Refund retur INV-001');

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 100_000,
        ])->assertOk();

        $this->shift->refresh();

        // saldo_system = 100k + 0 (no cash sales) + 50k - 20k (manual) - 30k (refund) = 100k
        $this->assertEquals(100_000.0, (float) $this->shift->saldo_system);
        $this->assertEquals(100_000.0, (float) $this->shift->saldo_fisik);
        $this->assertEquals(0.0, (float) $this->shift->selisih);
    }

    public function test_selisih_positive_when_physical_more_than_system(): void
    {
        $this->makeCashTx('setor_awal', 200_000);

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 250_000,
            'closing_notes' => 'Lebih 50rb, mungkin uang receh tambahan',
        ])->assertOk();

        $this->shift->refresh();

        $this->assertEquals(200_000.0, (float) $this->shift->saldo_system);
        $this->assertEquals(250_000.0, (float) $this->shift->saldo_fisik);
        $this->assertEquals(50_000.0, (float) $this->shift->selisih);
        $this->assertEquals('Lebih 50rb, mungkin uang receh tambahan', $this->shift->closing_notes);
    }

    public function test_selisih_negative_when_physical_less_than_system(): void
    {
        $this->makeCashTx('setor_awal', 200_000);

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 180_000,
        ])->assertOk();

        $this->shift->refresh();

        $this->assertEquals(-20_000.0, (float) $this->shift->selisih);
    }

    public function test_only_active_user_can_end_shift(): void
    {
        $other = User::factory()->create();
        $this->actingAs($other);

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 100_000,
        ])->assertForbidden();
    }

    public function test_validates_saldo_fisik_must_be_non_negative(): void
    {
        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => -1000,
        ])->assertStatus(422);
    }

    public function test_force_release_snapshots_saldo_system_for_audit(): void
    {
        Permission::firstOrCreate(['name' => 'terminal.force-release', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->givePermissionTo('terminal.force-release');

        $this->makeCashTx('setor_awal', 300_000);
        $this->makeCashTx('kas_masuk', 50_000);

        $this->actingAs($admin)
            ->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/force-release")
            ->assertOk();

        $this->shift->refresh();

        $this->assertTrue((bool) $this->shift->ended_by_force);
        $this->assertEquals($admin->id, $this->shift->forced_by);
        $this->assertEquals(350_000.0, (float) $this->shift->saldo_system);
        $this->assertNull($this->shift->saldo_fisik, 'saldo_fisik tetap null karena admin tidak menghitung');
        $this->assertNull($this->shift->selisih);
    }

    public function test_force_release_accepts_optional_saldo_fisik_and_computes_selisih(): void
    {
        Permission::firstOrCreate(['name' => 'terminal.force-release', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->givePermissionTo('terminal.force-release');

        $this->makeCashTx('setor_awal', 500_000);

        $this->actingAs($admin)
            ->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/force-release", [
                'saldo_fisik' => 480_000,
                'closing_notes' => 'Kasir lapor via WA, kas fisik 480rb',
            ])
            ->assertOk();

        $this->shift->refresh();

        $this->assertEquals(500_000.0, (float) $this->shift->saldo_system);
        $this->assertEquals(480_000.0, (float) $this->shift->saldo_fisik);
        $this->assertEquals(-20_000.0, (float) $this->shift->selisih);
        $this->assertEquals('Kasir lapor via WA, kas fisik 480rb', $this->shift->closing_notes);
    }

    public function test_force_release_requires_permission(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/force-release")
            ->assertForbidden();
    }

    // ====================================================================
    // EDGE CASE tambahan — galak, eksak, anti-double-close
    // ====================================================================

    /**
     * saldo_system memasukkan penjualan tunai NET (bayar - kembalian).
     * setor 100k + jual tunai (bayar 150k, kembali 20k = net 130k) = 230k.
     */
    public function test_saldo_system_includes_net_cash_sales(): void
    {
        $this->makeCashTx('setor_awal', 100_000);
        $this->makeCashSale(grandTotal: 130_000, nominalBayar: 150_000, kembalian: 20_000);

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 230_000,
        ])->assertOk();

        $this->shift->refresh();
        $this->assertEquals(230_000.0, (float) $this->shift->saldo_system);
        $this->assertEquals(0.0, (float) $this->shift->selisih);
    }

    /**
     * Penjualan tunai 'voided' TIDAK dihitung ke saldo_system (hanya completed).
     */
    public function test_voided_sale_does_not_count_in_saldo_system(): void
    {
        $this->makeCashTx('setor_awal', 100_000);
        // buat sale lalu void
        $sale = DocSales::create([
            'ulid' => (string) Str::ulid(),
            'nomor_dokumen' => 'INV-VOID',
            'tanggal' => now(),
            'terminal_id' => $this->terminal->id,
            'shift_id' => $this->shift->id,
            'warehouse_id' => $this->warehouse->id,
            'customer_id' => $this->customer->id,
            'subtotal' => 99_000, 'total_diskon' => 0, 'total_setelah_diskon' => 99_000,
            'dpp' => 99_000, 'pajak_nominal' => 0, 'pembulatan' => 0,
            'grand_total' => 99_000, 'total_bayar' => 99_000, 'kembalian' => 0,
            'total_biaya_pembayaran' => 0, 'status' => 'voided',
            'created_by' => $this->user->id,
        ]);
        DocSalesPayment::create([
            'sales_id' => $sale->id,
            'metode_pembayaran_id' => $this->cash->id,
            'nominal' => 99_000, 'biaya_tambahan' => 0,
        ]);

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 100_000,
        ])->assertOk();

        $this->shift->refresh();
        // hanya setor_awal 100k yang dihitung; sale voided diabaikan.
        $this->assertEquals(100_000.0, (float) $this->shift->saldo_system);
        $this->assertEquals(0.0, (float) $this->shift->selisih);
    }

    /**
     * Tanpa saldo_fisik tapi ada transaksi: saldo_system tetap dihitung,
     * selisih TETAP null (tidak dipaksa 0).
     */
    public function test_selisih_null_when_saldo_fisik_omitted_but_system_computed(): void
    {
        $this->makeCashTx('setor_awal', 75_000);

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift")
            ->assertOk();

        $this->shift->refresh();
        $this->assertEquals(75_000.0, (float) $this->shift->saldo_system);
        $this->assertNull($this->shift->saldo_fisik);
        $this->assertNull($this->shift->selisih, 'selisih harus null bila kasir tidak input saldo fisik');
    }

    /** saldo_fisik = 0 (boundary min:0) diterima → selisih = -saldo_system. */
    public function test_saldo_fisik_zero_is_accepted_boundary(): void
    {
        $this->makeCashTx('setor_awal', 40_000);

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 0,
        ])->assertOk();

        $this->shift->refresh();
        $this->assertEquals(0.0, (float) $this->shift->saldo_fisik);
        $this->assertEquals(40_000.0, (float) $this->shift->saldo_system);
        $this->assertEquals(-40_000.0, (float) $this->shift->selisih);
    }

    /**
     * end-shift TIDAK BISA dipanggil 2x: panggilan kedua → 422 'tidak sedang digunakan'
     * dan ended_at + selisih shift pertama tidak berubah.
     */
    public function test_end_shift_cannot_be_called_twice(): void
    {
        $this->makeCashTx('setor_awal', 100_000);

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 90_000,
        ])->assertOk();

        $this->shift->refresh();
        $firstEndedAt = (string) $this->shift->ended_at;
        $this->assertEquals(-10_000.0, (float) $this->shift->selisih);

        // panggilan kedua harus ditolak.
        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 999_999,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Terminal tidak sedang digunakan');

        $this->shift->refresh();
        // selisih & ended_at shift pertama tetap (tidak ter-overwrite 999_999).
        $this->assertEquals(-10_000.0, (float) $this->shift->selisih);
        $this->assertEquals($firstEndedAt, (string) $this->shift->ended_at);
    }

    /** closing_notes melebihi 1000 char → 422 validasi. */
    public function test_closing_notes_too_long_is_rejected(): void
    {
        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 100_000,
            'closing_notes' => str_repeat('x', 1001),
        ])->assertStatus(422)
          ->assertJsonValidationErrors(['closing_notes']);
    }

    /** end-shift pada terminal yang tidak sedang dipakai (active_user null) → 422. */
    public function test_end_shift_on_idle_terminal_is_rejected(): void
    {
        // lepas terminal terlebih dahulu.
        $this->terminal->update(['active_user_id' => null]);

        $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 100_000,
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Terminal tidak sedang digunakan');
    }

    /** force-release tidak bisa 2x: panggilan kedua → 422 'tidak sedang digunakan'. */
    public function test_force_release_cannot_be_called_twice(): void
    {
        Permission::firstOrCreate(['name' => 'terminal.force-release', 'guard_name' => 'web']);
        $admin = User::factory()->create();
        $admin->givePermissionTo('terminal.force-release');

        $this->makeCashTx('setor_awal', 200_000);

        $this->actingAs($admin)
            ->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/force-release")
            ->assertOk();

        $this->actingAs($admin)
            ->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/force-release")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Terminal tidak sedang digunakan');
    }

    /** end-shift mengembalikan saldo_system/saldo_fisik/selisih eksak di body response. */
    public function test_end_shift_response_body_exposes_exact_reconciliation(): void
    {
        $this->makeCashTx('setor_awal', 100_000);
        $this->makeCashTx('kas_masuk', 25_000);

        $res = $this->postJson("/api/v1/pos-terminals/{$this->terminal->ulid}/end-shift", [
            'saldo_fisik' => 120_000,
        ])->assertOk();

        $this->assertEquals(125_000.0, (float) $res->json('data.saldo_system'));
        $this->assertEquals(120_000.0, (float) $res->json('data.saldo_fisik'));
        $this->assertEquals(-5_000.0, (float) $res->json('data.selisih'));
        $this->assertNotNull($res->json('data.shift_ulid'));
    }
}
