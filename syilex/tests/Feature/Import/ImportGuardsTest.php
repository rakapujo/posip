<?php

namespace Tests\Feature\Import;

use App\Models\InventoryStock;
use App\Models\MasterPosTerminal;
use App\Models\MasterProduk;
use App\Models\MasterWarehouse;
use App\Models\User;
use App\Services\SettingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ImportGuardsTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::clearCache();

        foreach ([
            'import.master',
            'produk.create', 'produk.update',
            'warehouse.create', 'warehouse.update',
            'customer.create', 'customer.update',
            'supplier.create', 'supplier.update',
        ] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo([
            'import.master',
            'produk.create', 'produk.update',
            'warehouse.create', 'warehouse.update',
            'customer.create', 'customer.update',
            'supplier.create', 'supplier.update',
        ]);
    }

    private function makeXlsx(array $rows): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray($rows, null, 'A1');
        $path = tempnam(sys_get_temp_dir(), 'imp') . '.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile(
            $path,
            'import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    private function produkHeader(): array
    {
        return [
            'Kode Produk', 'Barcode', 'Nama Produk', 'Kode Brand', 'Kode Tipe',
            'Kode Kategori', 'Kode Grup', 'Unit 1', 'Konversi 1', 'Harga 1',
            'Unit 2', 'Konversi 2', 'Harga 2', 'Unit 3', 'Konversi 3', 'Harga 3',
            'Unit 4', 'Harga 4', 'Minimum Stok', 'Status', 'Serial',
        ];
    }

    private function retailRow(string $kode, string $nama, string $barcode, string $status = 'Aktif'): array
    {
        return [$kode, $barcode, $nama, '', '', '', '', 'PCS', 1, 5000, 'PCS', 1, 5000, 'PCS', 1, 5000, 'PCS', 5000, 0, $status, 'Tidak'];
    }

    #[Test]
    public function reactivate_produk_seeds_inventory_stock(): void
    {
        MasterWarehouse::factory()->create(['status' => 'active', 'is_saleable' => true]);
        $produk = MasterProduk::factory()->create([
            'kode_produk' => 'REA001',
            'status' => 'inactive',
            'is_serial' => false,
            'unit_1' => 'PCS', 'konversi_1' => 1, 'harga_1' => 5000,
            'unit_2' => 'PCS', 'konversi_2' => 1, 'harga_2' => 5000,
            'unit_3' => 'PCS', 'konversi_3' => 1, 'harga_3' => 5000,
            'unit_4' => 'PCS', 'konversi_4' => 1, 'harga_4' => 5000,
        ]);
        InventoryStock::where('product_id', $produk->id)->delete();
        $this->assertEquals(0, InventoryStock::where('product_id', $produk->id)->count());

        $file = $this->makeXlsx([$this->produkHeader(), $this->retailRow('REA001', 'Reactivate', '', 'Aktif')]);

        $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $file, 'mode' => 'upsert'], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertGreaterThan(0, InventoryStock::where('product_id', $produk->id)->count());
    }

    #[Test]
    public function barcode_duplicate_is_row_error(): void
    {
        MasterProduk::factory()->create([
            'kode_produk' => 'EXI001',
            'barcode' => 'DUPBAR',
            'is_serial' => false,
            'status' => 'active',
        ]);

        $file = $this->makeXlsx([$this->produkHeader(), $this->retailRow('NEW001', 'New', 'DUPBAR')]);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/produk', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(0, $res->json('data.created'));
        $this->assertNotEmpty($res->json('data.errors'));
        $this->assertStringContainsString('Barcode', $res->json('data.errors.0'));
        $this->assertDatabaseMissing('master_produk', ['kode_produk' => 'NEW001']);
    }

    #[Test]
    public function create_customer_walk_in_is_rejected(): void
    {
        $file = $this->makeXlsx([
            ['Kode Customer', 'Nama', 'Telepon', 'Email', 'Alamat', 'NIK', 'NPWP', 'Jenis', 'Kode Tipe', 'Kode Kategori', 'Tempo Default', 'Status'],
            ['CWALK', 'Walk Import', '0811', '', '', '', '', 'walk_in', '', '', 0, 'Aktif'],
        ]);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/customer', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(0, $res->json('data.created'));
        $this->assertStringContainsString('walk-in', strtolower($res->json('data.errors.0')));
        $this->assertDatabaseMissing('master_customer', ['kode_customer' => 'CWALK']);
    }

    #[Test]
    public function supplier_without_pic_is_rejected(): void
    {
        $file = $this->makeXlsx([
            ['Kode Supplier', 'Nama Supplier', 'PIC', 'Telepon', 'Email', 'Alamat', 'NPWP', 'Bank', 'No. Rekening', 'Atas Nama', 'Tempo (Hari)', 'Status'],
            ['SUPX', 'Tanpa PIC', '', '081234', '', '', '', '', '', '', 0, 'Aktif'],
        ]);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/supplier', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(0, $res->json('data.created'));
        $this->assertNotEmpty($res->json('data.errors'));
        $this->assertDatabaseMissing('master_supplier', ['kode_supplier' => 'SUPX']);
    }

    #[Test]
    public function deactivate_warehouse_with_terminal_is_rejected(): void
    {
        $wh = MasterWarehouse::factory()->create([
            'kode_warehouse' => 'WHTERM',
            'status' => 'active',
            'is_saleable' => true,
        ]);
        MasterPosTerminal::query()->create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'kode_terminal' => 'TWH1',
            'nama_terminal' => 'Terminal WH',
            'warehouse_id' => $wh->id,
            'status' => 'active',
        ]);

        $file = $this->makeXlsx([
            ['Kode Warehouse', 'Nama Warehouse', 'Alamat', 'PIC', 'Telepon PIC', 'Dapat Dijual (POS)', 'Status'],
            ['WHTERM', $wh->nama_warehouse, '', '', '', 'Ya', 'Nonaktif'],
        ]);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/warehouse', ['file' => $file, 'mode' => 'upsert'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertSame(0, $res->json('data.updated'));
        $this->assertNotEmpty($res->json('data.errors'));
        $this->assertSame('active', $wh->fresh()->status);
    }

    #[Test]
    public function import_creates_activity_log(): void
    {
        $file = $this->makeXlsx([
            ['Kode Brand', 'Nama Brand', 'Status'],
            ['BRDLOG', 'Brand Log', 'Aktif'],
        ]);

        // brand perms
        Permission::firstOrCreate(['name' => 'brand.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'brand.update', 'guard_name' => 'web']);
        $this->admin->givePermissionTo(['brand.create', 'brand.update']);

        $this->actingAs($this->admin)
            ->post('/api/v1/import/brand', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertOk();

        $this->assertTrue(
            DB::table('activity_log')->where('log_name', 'Import')->where('description', 'like', '%brand%')->exists()
        );
    }
}
