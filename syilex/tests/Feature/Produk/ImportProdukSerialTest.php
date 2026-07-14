<?php

namespace Tests\Feature\Produk;

use App\Models\MasterProduk;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * Import produk Lapis A — kolom "Serial".
 * Baris serial: cuma kode+nama wajib, auto-scaffold (UNIT/1/0, barcode null, min_stok 0).
 * Baris retail: tetap butuh unit/harga lengkap, perilaku tak berubah.
 */
class ImportProdukSerialTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['import.master', 'produk.create', 'produk.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }
        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo(['import.master', 'produk.create', 'produk.view']);
    }

    /** Bangun file xlsx sementara dari array (baris pertama = header). */
    private function makeXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'import_produk.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function header(): array
    {
        return [
            'Kode Produk', 'Barcode', 'Nama Produk', 'Kode Brand', 'Kode Tipe',
            'Kode Kategori', 'Kode Grup', 'Unit 1', 'Konversi 1', 'Harga 1',
            'Unit 2', 'Konversi 2', 'Harga 2', 'Unit 3', 'Konversi 3', 'Harga 3',
            'Unit 4', 'Harga 4', 'Minimum Stok', 'Status', 'Serial',
        ];
    }
    #[Test]
    public function import_serial_row_auto_scaffolds()
    {
        // Baris serial: unit/harga/barcode/min-stok kosong, Serial = Ya
        $serialRow = ['LAP999', '', 'MacBook Test', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Aktif', 'Ya'];

        $file = $this->makeXlsx([$this->header(), $serialRow]);

        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.created', 1);

        $p = MasterProduk::where('kode_produk', 'LAP999')->first();
        $this->assertNotNull($p, 'Produk serial harus terbuat walau unit/harga kosong');
        $this->assertTrue((bool) $p->is_serial);
        $this->assertSame('UNIT', $p->unit_1);
        $this->assertSame('UNIT', $p->unit_4);
        $this->assertEquals(1, $p->konversi_1);
        $this->assertEquals(0, (float) $p->harga_1);
        $this->assertEquals(0, $p->minimum_stok);
        $this->assertNull($p->barcode);
    }
    #[Test]
    public function import_retail_row_unchanged()
    {
        $retailRow = ['RTL999', '111', 'Charger Test', '', '', '', '', 'PCS', 1, 5000, 'PCS', 1, 5000, 'PCS', 1, 5000, 'PCS', 5000, 3, 'Aktif', 'Tidak'];

        $file = $this->makeXlsx([$this->header(), $retailRow]);

        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.created', 1);

        $p = MasterProduk::where('kode_produk', 'RTL999')->first();
        $this->assertFalse((bool) $p->is_serial);
        $this->assertEquals(5000, (float) $p->harga_1);
        $this->assertEquals(3, $p->minimum_stok);
        $this->assertSame('111', $p->barcode);
    }
    #[Test]
    public function import_upsert_cannot_flip_is_serial_on_existing_product()
    {
        // Produk normal existing
        $retailRow = ['FLIP1', '111', 'Produk Normal', '', '', '', '', 'PCS', 1, 5000, 'PCS', 1, 5000, 'PCS', 1, 5000, 'PCS', 5000, 3, 'Aktif', 'Tidak'];
        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $retailRow]), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);
        $this->assertFalse((bool) MasterProduk::where('kode_produk', 'FLIP1')->value('is_serial'));

        // Upsert dengan Serial = Ya → ditolak (skipped + error), is_serial & harga tetap
        $flipRow = ['FLIP1', '111', 'Produk Normal', '', '', '', '', 'PCS', 1, 9000, 'PCS', 1, 9000, 'PCS', 1, 9000, 'PCS', 9000, 3, 'Aktif', 'Ya'];
        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $flipRow]), 'mode' => 'upsert'], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.skipped', 1);

        $p = MasterProduk::where('kode_produk', 'FLIP1')->first();
        $this->assertFalse((bool) $p->is_serial, 'is_serial tidak boleh flip via import');
        $this->assertEquals(5000, (float) $p->harga_1, 'Baris ditolak → harga tidak berubah');
    }
    #[Test]
    public function import_retail_row_missing_price_is_rejected()
    {
        // Retail (Serial=Tidak) tanpa unit/harga → harus error, tidak terbuat
        $badRow = ['BAD999', '', 'Tanpa Harga', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Aktif', 'Tidak'];

        $file = $this->makeXlsx([$this->header(), $badRow]);

        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.created', 0);

        $this->assertNull(MasterProduk::where('kode_produk', 'BAD999')->first());
    }

    /** Bangun baris serial valid singkat (hanya kode + nama + Serial=Ya). */
    private function serialRow(string $kode, string $nama, string $barcode = ''): array
    {
        return [$kode, $barcode, $nama, '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Aktif', 'Ya'];
    }

    /** Bangun baris retail valid singkat. */
    private function retailRow(string $kode, string $nama, string $barcode = ''): array
    {
        return [$kode, $barcode, $nama, '', '', '', '', 'PCS', 1, 5000, 'PCS', 1, 5000, 'PCS', 1, 5000, 'PCS', 5000, 3, 'Aktif', 'Tidak'];
    }
    #[Test]
    public function import_mixed_valid_and_invalid_rows_partial_success()
    {
        // 2 valid (1 serial, 1 retail) + 2 invalid (retail tanpa harga, serial tanpa nama)
        $rows = [
            $this->header(),
            $this->serialRow('MIX_SER', 'Laptop Mix'),                                  // valid serial
            $this->retailRow('MIX_RTL', 'Charger Mix'),                                  // valid retail
            ['MIX_BAD1', '', 'Retail Tanpa Harga', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Aktif', 'Tidak'], // invalid: retail tanpa unit/harga
            ['MIX_BAD2', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', '', 'Aktif', 'Ya'],                       // invalid: serial tanpa nama
        ];

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx($rows), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $res->assertJsonPath('data.created', 2);
        $this->assertCount(2, $res->json('data.errors'));

        $this->assertTrue((bool) MasterProduk::where('kode_produk', 'MIX_SER')->value('is_serial'));
        $this->assertFalse((bool) MasterProduk::where('kode_produk', 'MIX_RTL')->value('is_serial'));
        $this->assertNull(MasterProduk::where('kode_produk', 'MIX_BAD1')->first());
        $this->assertNull(MasterProduk::where('kode_produk', 'MIX_BAD2')->first());
    }
    #[Test]
    public function import_serial_row_with_filled_columns_still_scaffolds()
    {
        // Baris serial dgn barcode + unit + harga TERISI → tetap di-scaffold (UNIT/1/0, barcode null)
        $row = ['SER_FULL', '777888', 'Serial Penuh', '', '', '', '', 'KARTON', 24, 9000000, 'BOX', 12, 5000000, 'PAK', 6, 2000000, 'PCS', 500000, 9, 'Aktif', 'Ya'];

        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $row]), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.created', 1);

        $p = MasterProduk::where('kode_produk', 'SER_FULL')->first();
        $this->assertTrue((bool) $p->is_serial);
        $this->assertSame('UNIT', $p->unit_1);
        $this->assertSame('UNIT', $p->unit_4);
        $this->assertEquals(0, (float) $p->harga_1);
        $this->assertEquals(0, (float) $p->harga_4);
        $this->assertEquals(0, $p->minimum_stok);
        $this->assertNull($p->barcode, 'Barcode serial dipaksa null walau diisi di sheet');
    }

    /**
     * Header berawalan kolom "No" (format hasil export) → kolom "No" dibuang dari tiap baris,
     * SEMUA baris data tetap masuk (regresi bug: dulu array_shift ekstra membuang baris pertama).
     */
    #[Test]
    public function import_header_with_no_prefix_keeps_all_data_rows()
    {
        $header = array_merge(['No'], $this->header());
        $row1 = array_merge([1], $this->serialRow('NOPFX_1', 'Laptop Satu'));
        $row2 = array_merge([2], $this->serialRow('NOPFX_2', 'Laptop Dua'));

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$header, $row1, $row2]), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);

        // Kedua baris terbuat (baris pertama TIDAK hilang lagi)
        $res->assertJsonPath('data.created', 2);
        $this->assertNotNull(MasterProduk::where('kode_produk', 'NOPFX_1')->first(), 'Baris pertama harus ikut masuk');
        $this->assertNotNull(MasterProduk::where('kode_produk', 'NOPFX_2')->first(), 'Baris kedua masuk');
    }
    #[Test]
    public function import_create_mode_skips_existing_kode()
    {
        // Buat serial existing
        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $this->serialRow('DUP_C', 'Asli')]), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200)->assertJsonPath('data.created', 1);

        // Import ulang kode sama (create) → dilewati, bukan dibuat ganda
        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $this->serialRow('DUP_C', 'Pengganti')]), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);
        $res->assertJsonPath('data.created', 0);
        $res->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, MasterProduk::where('kode_produk', 'DUP_C')->count());
        // create mode tak menimpa data lama (nama trim, case dipertahankan)
        $this->assertSame('Asli', MasterProduk::where('kode_produk', 'DUP_C')->value('nama_produk'));
    }
    #[Test]
    public function import_upsert_updates_retail_price_for_existing_product()
    {
        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $this->retailRow('UPS_R', 'Awal')]), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);

        // Upsert: harga 5000 → 8000, tetap retail
        $updRow = ['UPS_R', '', 'Diupdate', '', '', '', '', 'PCS', 1, 8000, 'PCS', 1, 8000, 'PCS', 1, 8000, 'PCS', 8000, 7, 'Aktif', 'Tidak'];
        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $updRow]), 'mode' => 'upsert'], ['Accept' => 'application/json'])
            ->assertStatus(200);
        $res->assertJsonPath('data.updated', 1);

        $p = MasterProduk::where('kode_produk', 'UPS_R')->first();
        $this->assertEquals(8000, (float) $p->harga_1);
        $this->assertEquals(7, $p->minimum_stok);
        $this->assertFalse((bool) $p->is_serial);
    }
    #[Test]
    public function import_two_serial_rows_both_scaffold()
    {
        $rows = [
            $this->header(),
            $this->serialRow('SER_A', 'MacBook A'),
            $this->serialRow('SER_B', 'iPhone B'),
        ];

        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx($rows), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.created', 2);

        $this->assertEquals(2, MasterProduk::whereIn('kode_produk', ['SER_A', 'SER_B'])
            ->where('is_serial', true)->whereNull('barcode')->count());
    }
    #[Test]
    public function import_upsert_serial_to_serial_is_allowed_and_stays_scaffolded()
    {
        // Serial existing
        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $this->serialRow('UPS_S', 'Serial Awal')]), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);

        // Upsert serial→serial (Serial=Ya, tak flip) → update nama, bukan skip
        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $this->serialRow('UPS_S', 'Serial Baru')]), 'mode' => 'upsert'], ['Accept' => 'application/json'])
            ->assertStatus(200);
        $res->assertJsonPath('data.updated', 1);
        $res->assertJsonPath('data.skipped', 0);

        $p = MasterProduk::where('kode_produk', 'UPS_S')->first();
        $this->assertTrue((bool) $p->is_serial);
        $this->assertSame('Serial Baru', $p->nama_produk);
        $this->assertNull($p->barcode);
        $this->assertEquals(0, (float) $p->harga_1);
    }
    #[Test]
    public function import_requires_create_permission()
    {
        // User punya import.master tapi TIDAK punya produk.create
        $u = User::factory()->create();
        $u->givePermissionTo(['import.master', 'produk.view']);

        $this->actingAs($u)
            ->post('/api/v1/import/produk', ['file' => $this->makeXlsx([$this->header(), $this->serialRow('NOPERM_S', 'X')]), 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(403);

        $this->assertNull(MasterProduk::where('kode_produk', 'NOPERM_S')->first());
    }
}
