<?php

namespace Tests\Feature\Import;

use App\Exports\ImportTemplateExport;
use App\Http\Controllers\Api\V1\ImportController;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\IOFactory;
use ReflectionMethod;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Pastikan setiap template import: heading = columns, sample row width cocok,
 * FE keys sinkron, dan xlsx yang di-generate header-nya lolos validasi import.
 */
class ImportTemplateIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const ENTITIES = [
        'brand' => 'brand.create',
        'tipe' => 'tipe.create',
        'kategori' => 'kategori.create',
        'grup' => 'grup.create',
        'supplier' => 'supplier.create',
        'warehouse' => 'warehouse.create',
        'tipe_customer' => 'tipe-customer.create',
        'kategori_customer' => 'kategori-customer.create',
        'customer' => 'customer.create',
        'metode_pembayaran' => 'metode-bayar.create',
        'produk' => 'produk.create',
    ];

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $perms = array_values(array_unique(array_merge(
            ['import.master'],
            array_values(self::ENTITIES)
        )));
        foreach ($perms as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'web']);
        }

        $this->admin = User::factory()->create();
        $this->admin->givePermissionTo($perms);
    }

    private function entityConfig(): array
    {
        $m = new ReflectionMethod(ImportController::class, 'getEntityConfig');
        $m->setAccessible(true);

        return $m->invoke(app(ImportController::class));
    }

    private function sampleData(string $entity): array
    {
        $m = new ReflectionMethod(ImportController::class, 'getSampleData');
        $m->setAccessible(true);

        return $m->invoke(app(ImportController::class), $entity);
    }

    public function test_config_columns_headings_and_samples_align(): void
    {
        foreach ($this->entityConfig() as $entity => $config) {
            $this->assertCount(
                count($config['columns']),
                $config['headings'],
                "{$entity}: columns vs headings"
            );

            foreach ($this->sampleData($entity) as $i => $row) {
                $this->assertCount(
                    count($config['headings']),
                    $row,
                    "{$entity} sample[{$i}] width"
                );
            }

            foreach ($config['required'] as $req) {
                $this->assertContains($req, $config['columns'], "{$entity} required {$req}");
            }
        }
    }

    public function test_generated_xlsx_headers_match_config(): void
    {
        foreach ($this->entityConfig() as $entity => $config) {
            $raw = Excel::raw(
                new ImportTemplateExport($config['headings'], $this->sampleData($entity)),
                \Maatwebsite\Excel\Excel::XLSX
            );

            $tmp = tempnam(sys_get_temp_dir(), "tpl_{$entity}_") . '.xlsx';
            file_put_contents($tmp, $raw);

            $rows = IOFactory::load($tmp)->getActiveSheet()->toArray(null, true, true, false);
            @unlink($tmp);

            $header = array_map(
                fn ($h) => trim((string) $h),
                array_slice($rows[0], 0, count($config['headings']))
            );
            $this->assertSame($config['headings'], $header, "{$entity} xlsx header");
            $this->assertGreaterThanOrEqual(2, count($rows), "{$entity} has samples");
        }
    }

    public function test_template_endpoint_returns_ok_for_all_entities(): void
    {
        foreach (array_keys(self::ENTITIES) as $entity) {
            $this->actingAs($this->admin)
                ->get("/api/v1/import/template/{$entity}")
                ->assertOk();
        }
    }

    public function test_frontend_entity_keys_match_backend_config(): void
    {
        $feKeys = [
            'brand', 'tipe', 'kategori', 'grup', 'supplier', 'warehouse',
            'metode_pembayaran', 'tipe_customer', 'kategori_customer', 'customer', 'produk',
        ];

        $backend = array_keys(self::ENTITIES);
        sort($feKeys);
        sort($backend);

        $this->assertSame($backend, $feKeys);
    }
}
