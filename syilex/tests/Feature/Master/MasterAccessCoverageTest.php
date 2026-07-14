<?php

namespace Tests\Feature\Master;

use App\Models\MasterCustomer;
use App\Models\MasterMetodePembayaran;
use App\Models\MasterSupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class MasterAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'supplier.view', 'supplier.create',
            'customer.view', 'customer.create',
            'produk.view', 'produk.create',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo(['supplier.view', 'customer.view', 'produk.view']);
    }

    public function test_supplier_export_forbidden_without_view_permission(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/v1/suppliers/export')
            ->assertForbidden();
    }

    public function test_supplier_export_ok_with_view_permission(): void
    {
        MasterSupplier::create([
            'kode_supplier' => 'SUP-EXP',
            'nama_supplier' => 'Export Supplier',
            'nama_pic' => 'PIC',
            'telepon' => '08123',
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $this->actingAs($this->viewer)
            ->get('/api/v1/suppliers/export')
            ->assertOk();
    }

    public function test_supplier_list_only_returns_active_records(): void
    {
        MasterSupplier::create([
            'kode_supplier' => 'SUP-ACT',
            'nama_supplier' => 'Active Supplier',
            'nama_pic' => 'PIC',
            'telepon' => '08111',
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        MasterSupplier::create([
            'kode_supplier' => 'SUP-INA',
            'nama_supplier' => 'Inactive Supplier',
            'nama_pic' => 'PIC',
            'telepon' => '08112',
            'status' => 'inactive',
            'created_by' => $this->viewer->id,
        ]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/suppliers/list')
            ->assertOk();

        $kodes = collect($response->json('data.suppliers'))->pluck('kode_supplier')->all();
        $this->assertContains('SUP-ACT', $kodes);
        $this->assertNotContains('SUP-INA', $kodes);
    }

    public function test_customer_create_forbidden_without_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/customers', [
                'kode_customer' => 'CUS-DENY',
                'nama' => 'Denied',
                'telepon' => '08123',
                'jenis' => 'spesifik',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_produk_index_forbidden_without_view_permission(): void
    {
        $creator = User::factory()->create();
        $creator->givePermissionTo('produk.create');

        $this->actingAs($creator)
            ->getJson('/api/v1/produks')
            ->assertForbidden();
    }

    public function test_customer_list_only_returns_active_customers(): void
    {
        Permission::firstOrCreate(['name' => 'customer.view', 'guard_name' => 'web']);

        MasterCustomer::create([
            'kode_customer' => 'CUS-ACT',
            'nama' => 'Active Customer',
            'telepon' => '08123',
            'jenis' => 'spesifik',
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        MasterCustomer::create([
            'kode_customer' => 'CUS-INA',
            'nama' => 'Inactive Customer',
            'telepon' => '08124',
            'jenis' => 'spesifik',
            'status' => 'inactive',
            'created_by' => $this->viewer->id,
        ]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/customers/list')
            ->assertOk();

        $kodes = collect($response->json('data.customers'))->pluck('kode_customer')->all();
        $this->assertContains('CUS-ACT', $kodes);
        $this->assertNotContains('CUS-INA', $kodes);
    }
}
