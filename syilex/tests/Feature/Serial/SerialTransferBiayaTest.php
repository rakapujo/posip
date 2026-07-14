<?php

namespace Tests\Feature\Serial;

use App\Models\DocTransferDetail;
use App\Models\InventoryStock;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\SerialUnit;
use App\Models\StockCard;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Biaya Kirim + Biaya Lain pada Transfer antar gudang (opsional masuk HPP).
 * - Alokasi by-value (qty × avg_cost), Σ alokasi == total biaya.
 * - masuk_hpp=true: non-serial → avg global naik (Opsi B); serial → cost_per_unit unit
 *   dipindah naik + avg via Metode A. masuk_hpp=false → HPP tak berubah (info saja).
 */
class SerialTransferBiayaTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected MasterWarehouse $wh1;
    protected MasterWarehouse $wh2;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['transfer.view', 'transfer.create', 'transfer.approve'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(Permission::all());
        $this->actingAs($this->admin);

        $this->wh1 = MasterWarehouse::create(['kode_warehouse' => 'WH1', 'nama_warehouse' => 'Gudang 1', 'is_saleable' => true, 'status' => 'active']);
        $this->wh2 = MasterWarehouse::create(['kode_warehouse' => 'WH2', 'nama_warehouse' => 'Gudang 2', 'is_saleable' => true, 'status' => 'active']);
    }

    private function makeProduct(string $kode, bool $serial, float $avgCost): MasterProduk
    {
        return MasterProduk::create([
            'kode_produk' => $kode, 'nama_produk' => "Produk {$kode}", 'status' => 'active',
            'is_serial' => $serial, 'minimum_stok' => 0, 'avg_cost' => $avgCost, 'barcode' => null,
            'unit_1' => 'UNIT', 'konversi_1' => 1, 'harga_1' => 0,
            'unit_2' => 'UNIT', 'konversi_2' => 1, 'harga_2' => 0,
            'unit_3' => 'UNIT', 'konversi_3' => 1, 'harga_3' => 0,
            'unit_4' => 'UNIT', 'konversi_4' => 1, 'harga_4' => 0,
        ]);
    }

    /** Seed stok non-serial konsisten (inventory_stock + stock_card PURCHASE) di satu gudang. */
    private function seedStock(MasterProduk $p, MasterWarehouse $wh, int $qty, float $avgCost): void
    {
        StockCard::$skipObserver = true;
        InventoryStock::updateOrCreate(
            ['product_id' => $p->id, 'warehouse_id' => $wh->id],
            ['qty' => $qty, 'avg_cost' => $avgCost]
        );
        StockCard::record([
            'product_id' => $p->id, 'warehouse_id' => $wh->id,
            'transaction_type' => 'PURCHASE', 'tanggal' => now(),
            'qty_in' => $qty, 'qty_out' => 0, 'cost_per_unit' => $avgCost,
        ]);
        StockCard::$skipObserver = false;
    }

    /** Seed unit serial konsisten di satu gudang. @return SerialUnit[] */
    private function seedUnits(MasterProduk $p, MasterWarehouse $wh, int $count, float $cost, string $prefix = 'SN'): array
    {
        $this->seedStock($p, $wh, $count, $cost);
        $units = [];
        for ($i = 1; $i <= $count; $i++) {
            $units[] = SerialUnit::create([
                'product_id' => $p->id, 'warehouse_id' => $wh->id,
                'serial_number' => "{$prefix}-{$i}", 'harga_modal' => $cost, 'cost_per_unit' => $cost, 'status' => 'tersedia',
            ]);
        }
        return $units;
    }

    private function transfer(array $details, float $biayaKirim, float $biayaLain, bool $masukHpp): string
    {
        $res = $this->postJson('/api/v1/transfers', [
            'warehouse_from_id' => $this->wh1->id,
            'warehouse_to_id' => $this->wh2->id,
            'tanggal' => now()->toDateString(),
            'biaya_kirim' => $biayaKirim,
            'biaya_lain' => $biayaLain,
            'masuk_hpp' => $masukHpp,
            'details' => $details,
        ])->assertCreated();

        return $res->json('data.transfer.ulid');
    }
    #[Test]
    public function non_serial_masuk_hpp_naikkan_avg_global_opsi_b()
    {
        // Stok global 100 (wh1:80, wh2:20), avg 50.000
        $p = $this->makeProduct('NS1', false, 50000);
        $this->seedStock($p, $this->wh1, 80, 50000);
        $this->seedStock($p, $this->wh2, 20, 50000);

        // Transfer 10 unit, biaya kirim 100.000, masuk HPP
        $ulid = $this->transfer(
            [['product_id' => $p->id, 'qty' => 10]],
            biayaKirim: 100000, biayaLain: 0, masukHpp: true
        );
        $this->postJson("/api/v1/transfers/{$ulid}/approve")->assertOk();

        // avg_baru = 50.000 + 100.000/100 = 51.000
        $this->assertEqualsWithDelta(51000, (float) $p->fresh()->avg_cost, 0.0001);

        // Biaya dialokasikan tercatat penuh ke baris (1 produk)
        $this->assertEqualsWithDelta(100000, (float) DocTransferDetail::where('product_id', $p->id)->value('biaya_dialokasikan'), 0.0001);

        // Tercatat di Pergerakan HPP (HPP_CORRECTION) dengan avg before/after
        $hpp = StockCard::where('product_id', $p->id)->where('transaction_type', 'HPP_CORRECTION')->first();
        $this->assertNotNull($hpp);
        $this->assertEqualsWithDelta(50000, (float) $hpp->avg_cost_before, 0.0001);
        $this->assertEqualsWithDelta(51000, (float) $hpp->avg_cost_after, 0.0001);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function non_serial_tanpa_masuk_hpp_tidak_ubah_avg()
    {
        $p = $this->makeProduct('NS2', false, 50000);
        $this->seedStock($p, $this->wh1, 80, 50000);
        $this->seedStock($p, $this->wh2, 20, 50000);

        $ulid = $this->transfer(
            [['product_id' => $p->id, 'qty' => 10]],
            biayaKirim: 100000, biayaLain: 0, masukHpp: false
        );
        $this->postJson("/api/v1/transfers/{$ulid}/approve")->assertOk();

        // avg tak berubah; tapi alokasi tetap dicatat sebagai info
        $this->assertEqualsWithDelta(50000, (float) $p->fresh()->avg_cost, 0.0001);
        $this->assertEqualsWithDelta(100000, (float) DocTransferDetail::where('product_id', $p->id)->value('biaya_dialokasikan'), 0.0001);
        $this->assertSame(0, StockCard::where('product_id', $p->id)->where('transaction_type', 'HPP_CORRECTION')->count());

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function serial_masuk_hpp_naikkan_cost_per_unit_dan_avg_metode_a()
    {
        // 3 unit di wh1, cost 1.000
        $p = $this->makeProduct('SER1', true, 1000);
        $units = $this->seedUnits($p, $this->wh1, 3, 1000);

        // Transfer 2 unit, biaya kirim 100.000, masuk HPP
        $ulid = $this->transfer(
            [['product_id' => $p->id, 'qty' => 2, 'serial_unit_ids' => [$units[0]->ulid, $units[1]->ulid]]],
            biayaKirim: 100000, biayaLain: 0, masukHpp: true
        );
        $this->postJson("/api/v1/transfers/{$ulid}/approve")->assertOk();

        // per unit = 100.000/2 = 50.000 → unit dipindah jadi 51.000
        $this->assertEqualsWithDelta(51000, (float) SerialUnit::where('ulid', $units[0]->ulid)->value('cost_per_unit'), 0.0001);
        $this->assertEqualsWithDelta(51000, (float) SerialUnit::where('ulid', $units[1]->ulid)->value('cost_per_unit'), 0.0001);
        // unit tak dipindah tetap 1.000
        $this->assertEqualsWithDelta(1000, (float) SerialUnit::where('ulid', $units[2]->ulid)->value('cost_per_unit'), 0.0001);

        // avg (Metode A) = (51.000+51.000+1.000)/3 = 34.333,3333
        $this->assertEqualsWithDelta(34333.3333, (float) $p->fresh()->avg_cost, 0.001);

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function biaya_dialokasikan_proporsional_by_value_dua_produk()
    {
        // P1: 10 × 50.000 = 500.000 ; P2: 5 × 20.000 = 100.000 ; total value 600.000
        $p1 = $this->makeProduct('NS3', false, 50000);
        $p2 = $this->makeProduct('NS4', false, 20000);
        $this->seedStock($p1, $this->wh1, 50, 50000);
        $this->seedStock($p2, $this->wh1, 50, 20000);

        // total biaya 60.000 → P1 50.000, P2 10.000
        $ulid = $this->transfer(
            [
                ['product_id' => $p1->id, 'qty' => 10],
                ['product_id' => $p2->id, 'qty' => 5],
            ],
            biayaKirim: 40000, biayaLain: 20000, masukHpp: true
        );
        $this->postJson("/api/v1/transfers/{$ulid}/approve")->assertOk();

        $a1 = (float) DocTransferDetail::where('product_id', $p1->id)->value('biaya_dialokasikan');
        $a2 = (float) DocTransferDetail::where('product_id', $p2->id)->value('biaya_dialokasikan');
        $this->assertEqualsWithDelta(50000, $a1, 0.0001);
        $this->assertEqualsWithDelta(10000, $a2, 0.0001);
        $this->assertEqualsWithDelta(60000, $a1 + $a2, 0.0001); // Σ == total biaya

        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function alokasi_rounding_remainder_konsisten_tiga_produk()
    {
        // 3 produk nilai sama, biaya 100 tak habis dibagi 3 → sisa pembulatan ke baris terakhir
        $p1 = $this->makeProduct('R1', false, 1000);
        $p2 = $this->makeProduct('R2', false, 1000);
        $p3 = $this->makeProduct('R3', false, 1000);
        $this->seedStock($p1, $this->wh1, 10, 1000);
        $this->seedStock($p2, $this->wh1, 10, 1000);
        $this->seedStock($p3, $this->wh1, 10, 1000);

        $ulid = $this->transfer(
            [
                ['product_id' => $p1->id, 'qty' => 1],
                ['product_id' => $p2->id, 'qty' => 1],
                ['product_id' => $p3->id, 'qty' => 1],
            ],
            biayaKirim: 100, biayaLain: 0, masukHpp: false
        );
        $this->postJson("/api/v1/transfers/{$ulid}/approve")->assertOk();

        $a1 = (float) DocTransferDetail::where('product_id', $p1->id)->value('biaya_dialokasikan');
        $a2 = (float) DocTransferDetail::where('product_id', $p2->id)->value('biaya_dialokasikan');
        $a3 = (float) DocTransferDetail::where('product_id', $p3->id)->value('biaya_dialokasikan');
        // Σ alokasi HARUS persis = total biaya (tak ada selisih hilang/dobel)
        $this->assertEqualsWithDelta(100, $a1 + $a2 + $a3, 0.0001);
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
    #[Test]
    public function masuk_hpp_dengan_biaya_nol_tidak_ubah_hpp()
    {
        $p = $this->makeProduct('Z1', false, 50000);
        $this->seedStock($p, $this->wh1, 80, 50000);

        $ulid = $this->transfer(
            [['product_id' => $p->id, 'qty' => 10]],
            biayaKirim: 0, biayaLain: 0, masukHpp: true
        );
        $this->postJson("/api/v1/transfers/{$ulid}/approve")->assertOk();

        $this->assertEqualsWithDelta(50000, (float) $p->fresh()->avg_cost, 0.01); // tak berubah
        $this->assertEqualsWithDelta(0, (float) DocTransferDetail::where('product_id', $p->id)->value('biaya_dialokasikan'), 0.0001);
        $this->assertSame(0, StockCard::where('product_id', $p->id)->where('transaction_type', 'HPP_CORRECTION')->count());
        $this->assertSame(0, Artisan::call('data:verify', ['--fail-on-mismatch' => true]));
    }
}
