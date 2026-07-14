<?php

namespace Tests\Feature\PurchaseOrder;

use App\Actions\PurchaseOrder\CreatePurchaseOrderAction;
use App\Models\DocPurchaseOrder;
use App\Models\MasterProduk;
use App\Models\MasterSupplier;
use App\Models\MasterWarehouse;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Regression test untuk filter date-range pada list transaksi.
 *
 * BUG (historis): scopeByDateRange membandingkan kolom DATETIME (tanggal_po)
 * dengan date_to yang date-only. MySQL/SQLite meng-coerce '2026-04-12' menjadi
 * '2026-04-12 00:00:00', sehingga PO yang dibuat hari ini jam 14:30 (> 00:00:00)
 * tersaring KELUAR dan baru muncul kalau filter ditambah 1 hari.
 *
 * Fix: batas-atas wajib '... 23:59:59' (lihat DocPurchaseOrder::scopeByDateRange).
 * Scope DocPurchaseReturn / SupplierHutang / DocPembayaranHutang memakai pola
 * yang sama dan sudah ikut diperbaiki; PO mewakili keempatnya di sini.
 */
class PurchaseOrderDateFilterTest extends TestCase
{
    use RefreshDatabase;

    protected CreatePurchaseOrderAction $action;
    protected MasterSupplier $supplier;
    protected MasterWarehouse $warehouse;
    protected MasterProduk $product;

    protected function setUp(): void
    {
        parent::setUp();

        // Pajak & pembulatan dimatikan agar create PO sederhana
        SettingService::set('tax.tax_purchase_percent', 0, 'integer');
        SettingService::set('rounding.purchase_method', 'none', 'string');

        $user = User::factory()->create();
        $this->actingAs($user);

        $this->warehouse = MasterWarehouse::factory()->create(['status' => 'active']);

        $this->supplier = MasterSupplier::create([
            'ulid' => (string) Str::ulid(),
            'kode_supplier' => 'SUP-001',
            'nama_supplier' => 'Test Supplier',
            'nama_pic' => 'John Doe',
            'telepon' => '08123456789',
            'tempo_default' => 0,
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->product = MasterProduk::factory()->create([
            'avg_cost' => 5000,
            'unit_4' => 'PCS',
            'konversi_4' => 1,
            'status' => 'active',
        ]);

        $this->action = new CreatePurchaseOrderAction();
    }

    private function makePo(string $tanggalPo): DocPurchaseOrder
    {
        return $this->action->execute([
            'tanggal_po' => $tanggalPo,
            'supplier_id' => $this->supplier->id,
            'warehouse_id' => $this->warehouse->id,
            'details' => [[
                'product_id' => $this->product->id,
                'unit_used' => 'PCS',
                'unit_konversi' => 1,
                'qty_in_unit' => 1,
                'harga_per_unit' => 5000,
            ]],
        ]);
    }
    #[Test]
    public function date_range_includes_same_day_record_created_with_time()
    {
        // PO hari ini jam 14:30 — skenario persis bug-nya
        $po = $this->makePo('2026-04-12 14:30:00');

        $found = DocPurchaseOrder::byDateRange('2026-04-12', '2026-04-12')
            ->whereKey($po->id)
            ->exists();

        $this->assertTrue(
            $found,
            'PO tanggal hari ini jam 14:30 harus muncul saat filter date_to = hari ini ' .
            '(regresi: bare "<=" menyaringnya keluar).'
        );
    }
    #[Test]
    public function date_range_includes_record_at_end_of_day_boundary()
    {
        $po = $this->makePo('2026-04-12 23:59:59');

        $found = DocPurchaseOrder::byDateRange('2026-04-12', '2026-04-12')
            ->whereKey($po->id)
            ->exists();

        $this->assertTrue($found, 'PO jam 23:59:59 harus tetap masuk dalam rentang hari yang sama.');
    }
    #[Test]
    public function date_range_excludes_record_outside_range()
    {
        // PO besok tidak boleh ikut muncul untuk filter hari ini
        $po = $this->makePo('2026-04-13 09:00:00');

        $found = DocPurchaseOrder::byDateRange('2026-04-12', '2026-04-12')
            ->whereKey($po->id)
            ->exists();

        $this->assertFalse($found, 'PO di luar rentang (besok) tidak boleh muncul.');
    }

    // ===================================================================
    // EDGE CASE TAMBAHAN — batas bawah & hitung jumlah dalam rentang
    // ===================================================================
    #[Test]
    public function date_range_excludes_record_one_second_before_lower_bound()
    {
        // PO kemarin jam 23:59:59 (tepat 1 detik sebelum batas bawah 00:00:00 hari ini).
        $po = $this->makePo('2026-04-11 23:59:59');

        $found = DocPurchaseOrder::byDateRange('2026-04-12', '2026-04-12')
            ->whereKey($po->id)
            ->exists();

        $this->assertFalse($found, 'PO sebelum batas bawah (kemarin 23:59:59) harus tersaring keluar.');
    }
    #[Test]
    public function date_range_includes_record_at_start_of_day_boundary()
    {
        // PO tepat jam 00:00:00 harus masuk (batas bawah inklusif).
        $po = $this->makePo('2026-04-12 00:00:00');

        $found = DocPurchaseOrder::byDateRange('2026-04-12', '2026-04-12')
            ->whereKey($po->id)
            ->exists();

        $this->assertTrue($found, 'PO tepat 00:00:00 harus masuk batas bawah inklusif.');
    }
    #[Test]
    public function date_range_multi_hari_menghitung_jumlah_eksak()
    {
        // 3 PO di tanggal berbeda; rentang 12-13 April harus mengembalikan tepat 2.
        $this->makePo('2026-04-11 10:00:00'); // luar
        $this->makePo('2026-04-12 14:30:00'); // dalam
        $this->makePo('2026-04-13 23:59:59'); // dalam (batas atas inklusif)
        $this->makePo('2026-04-14 00:00:01'); // luar

        $count = DocPurchaseOrder::byDateRange('2026-04-12', '2026-04-13')->count();

        $this->assertSame(2, $count, 'Tepat 2 PO berada dalam rentang 12-13 April inklusif.');
    }
}
