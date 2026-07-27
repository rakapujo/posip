<?php

namespace Tests\Feature\Import;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\Attributes\Test;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 1 — Import Master Entities (Brand & Supplier) via API.
 */
class ImportMasterEntitiesTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['import.master', 'brand.create', 'brand.update', 'brand.view', 'supplier.create', 'supplier.view'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo([
            'import.master', 'brand.create', 'brand.update', 'brand.view', 'supplier.create', 'supplier.view',
        ]);
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
            'import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    // ==================== Permission gating ====================

    public function test_import_forbidden_without_import_master_permission(): void
    {
        $limited = User::factory()->create();
        $limited->givePermissionTo('brand.create'); // no import.master

        $file = $this->makeXlsx([
            ['Kode Brand', 'Nama Brand', 'Status'],
            ['BRD001', 'Brand Alpha', 'Aktif'],
        ]);

        $this->actingAs($limited)
            ->post('/api/v1/import/brand', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertDatabaseMissing('master_brand', ['kode_brand' => 'BRD001']);
    }

    public function test_import_forbidden_without_entity_specific_create_permission(): void
    {
        $limited = User::factory()->create();
        $limited->givePermissionTo('import.master'); // no brand.create

        $file = $this->makeXlsx([
            ['Kode Brand', 'Nama Brand', 'Status'],
            ['BRD002', 'Brand Beta', 'Aktif'],
        ]);

        $this->actingAs($limited)
            ->post('/api/v1/import/brand', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertDatabaseMissing('master_brand', ['kode_brand' => 'BRD002']);
    }

    public function test_import_rejects_unknown_entity(): void
    {
        $file = $this->makeXlsx([
            ['Kode', 'Nama'],
            ['X001', 'Test'],
        ]);

        $this->actingAs($this->admin)
            ->post('/api/v1/import/not-a-real-entity', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(422);
    }

    // ==================== Brand import ====================

    #[Test]
    public function import_brand_creates_new_records(): void
    {
        $file = $this->makeXlsx([
            ['Kode Brand', 'Nama Brand', 'Status'],
            ['BRD001', 'Brand Alpha', 'Aktif'],
            ['BRD002', 'Brand Beta', 'Nonaktif'],
        ]);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/brand', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $res->assertJsonPath('data.created', 2);

        $this->assertDatabaseHas('master_brand', ['kode_brand' => 'BRD001', 'status' => 'active']);
        $this->assertDatabaseHas('master_brand', ['kode_brand' => 'BRD002', 'status' => 'inactive']);
    }

    #[Test]
    public function import_brand_create_mode_skips_existing_kode(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/v1/import/brand', [
                'file' => $this->makeXlsx([
                    ['Kode Brand', 'Nama Brand', 'Status'],
                    ['DUP001', 'Original', 'Aktif'],
                ]),
                'mode' => 'create',
            ], ['Accept' => 'application/json'])
            ->assertStatus(200)
            ->assertJsonPath('data.created', 1);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/brand', [
                'file' => $this->makeXlsx([
                    ['Kode Brand', 'Nama Brand', 'Status'],
                    ['DUP001', 'Replacement', 'Aktif'],
                ]),
                'mode' => 'create',
            ], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $res->assertJsonPath('data.created', 0);
        $res->assertJsonPath('data.skipped', 1);

        $this->assertSame(1, DB::table('master_brand')->where('kode_brand', 'DUP001')->count());
        $this->assertDatabaseHas('master_brand', ['kode_brand' => 'DUP001', 'nama_brand' => 'Original']);
    }

    #[Test]
    public function import_brand_upsert_mode_updates_existing_kode(): void
    {
        $this->actingAs($this->admin)
            ->post('/api/v1/import/brand', [
                'file' => $this->makeXlsx([
                    ['Kode Brand', 'Nama Brand', 'Status'],
                    ['UPS001', 'Sebelum', 'Aktif'],
                ]),
                'mode' => 'create',
            ], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/brand', [
                'file' => $this->makeXlsx([
                    ['Kode Brand', 'Nama Brand', 'Status'],
                    ['UPS001', 'Sesudah', 'Nonaktif'],
                ]),
                'mode' => 'upsert',
            ], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $res->assertJsonPath('data.updated', 1);

        $this->assertDatabaseHas('master_brand', ['kode_brand' => 'UPS001', 'nama_brand' => 'Sesudah', 'status' => 'inactive']);
    }

    #[Test]
    public function import_upsert_forbidden_without_update_permission(): void
    {
        Permission::firstOrCreate(['name' => 'brand.update', 'guard_name' => 'web']);
        $limited = User::factory()->create();
        $limited->givePermissionTo(['import.master', 'brand.create']); // no brand.update

        $this->actingAs($this->admin)
            ->post('/api/v1/import/brand', [
                'file' => $this->makeXlsx([
                    ['Kode Brand', 'Nama Brand', 'Status'],
                    ['NOUPD1', 'Ada', 'Aktif'],
                ]),
                'mode' => 'create',
            ], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $this->actingAs($limited)
            ->post('/api/v1/import/brand', [
                'file' => $this->makeXlsx([
                    ['Kode Brand', 'Nama Brand', 'Status'],
                    ['NOUPD1', 'Diubah', 'Aktif'],
                ]),
                'mode' => 'upsert',
            ], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertDatabaseHas('master_brand', ['kode_brand' => 'NOUPD1', 'nama_brand' => 'Ada']);
    }

    #[Test]
    public function import_rejects_mismatched_header(): void
    {
        $file = $this->makeXlsx([
            ['Wrong', 'Header', 'Here'],
            ['BRD999', 'X', 'Aktif'],
        ]);

        $this->actingAs($this->admin)
            ->post('/api/v1/import/brand', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(422);

        $this->assertDatabaseMissing('master_brand', ['kode_brand' => 'BRD999']);
    }

    #[Test]
    public function import_brand_missing_required_column_is_rejected(): void
    {
        $file = $this->makeXlsx([
            ['Kode Brand', 'Nama Brand', 'Status'],
            ['', 'Tanpa Kode', 'Aktif'],
        ]);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/brand', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $res->assertJsonPath('data.created', 0);
        $this->assertCount(1, $res->json('data.errors'));
        $this->assertDatabaseMissing('master_brand', ['nama_brand' => 'Tanpa Kode']);
    }

    // ==================== Supplier import ====================

    #[Test]
    public function import_supplier_creates_new_record_with_full_columns(): void
    {
        $file = $this->makeXlsx([
            ['Kode Supplier', 'Nama Supplier', 'PIC', 'Telepon', 'Email', 'Alamat', 'NPWP', 'Bank', 'No. Rekening', 'Atas Nama', 'Tempo (Hari)', 'Status'],
            ['SUP001', 'PT Sumber Jaya', 'Budi', '081234567890', 'budi@sumberjaya.com', 'Jl. Industri No. 10', '', 'BCA', '1234567890', 'PT Sumber Jaya', 30, 'Aktif'],
        ]);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/supplier', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $res->assertJsonPath('data.created', 1);

        $this->assertDatabaseHas('master_supplier', [
            'kode_supplier' => 'SUP001',
            'nama_supplier' => 'PT Sumber Jaya',
            'tempo_default' => 30,
            'status' => 'active',
        ]);
    }

    #[Test]
    public function import_supplier_forbidden_without_supplier_create_permission(): void
    {
        $limited = User::factory()->create();
        $limited->givePermissionTo('import.master');

        $file = $this->makeXlsx([
            ['Kode Supplier', 'Nama Supplier', 'PIC', 'Telepon', 'Email', 'Alamat', 'NPWP', 'Bank', 'No. Rekening', 'Atas Nama', 'Tempo (Hari)', 'Status'],
            ['SUP002', 'CV Maju Bersama', 'Siti', '081298765432', '', '', '', '', '', '', 14, 'Aktif'],
        ]);

        $this->actingAs($limited)
            ->post('/api/v1/import/supplier', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertForbidden();

        $this->assertDatabaseMissing('master_supplier', ['kode_supplier' => 'SUP002']);
    }

    #[Test]
    public function import_supplier_missing_required_name_is_rejected(): void
    {
        $file = $this->makeXlsx([
            ['Kode Supplier', 'Nama Supplier', 'PIC', 'Telepon', 'Email', 'Alamat', 'NPWP', 'Bank', 'No. Rekening', 'Atas Nama', 'Tempo (Hari)', 'Status'],
            ['SUP003', '', 'Andi', '087812345678', '', '', '', '', '', '', 0, 'Aktif'],
        ]);

        $res = $this->actingAs($this->admin)
            ->post('/api/v1/import/supplier', ['file' => $file, 'mode' => 'create'], ['Accept' => 'application/json'])
            ->assertStatus(200);

        $res->assertJsonPath('data.created', 0);
        $this->assertCount(1, $res->json('data.errors'));
        $this->assertDatabaseMissing('master_supplier', ['kode_supplier' => 'SUP003']);
    }
}
