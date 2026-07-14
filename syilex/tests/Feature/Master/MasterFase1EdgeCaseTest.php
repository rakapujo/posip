<?php

namespace Tests\Feature\Master;

use App\Models\MasterMetodePembayaran;
use App\Models\MasterSupplier;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 1 edge cases — supplier, customer, metode pembayaran.
 */
class MasterFase1EdgeCaseTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'supplier.view', 'supplier.create', 'supplier.update', 'supplier.delete',
            'customer.view', 'customer.create',
            'metode-bayar.view', 'metode-bayar.create', 'metode-bayar.update', 'metode-bayar.delete',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->viewer = User::factory()->create();
        $this->viewer->givePermissionTo([
            'supplier.view', 'customer.view', 'metode-bayar.view',
        ]);
    }

    public function test_supplier_create_forbidden_without_create_permission(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/suppliers', [
                'kode_supplier' => 'SUP-NEW',
                'nama_supplier' => 'New Supplier',
                'nama_pic' => 'PIC',
                'telepon' => '08123',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_supplier_delete_forbidden_without_delete_permission(): void
    {
        $supplier = MasterSupplier::create([
            'kode_supplier' => 'SUP-DEL',
            'nama_supplier' => 'Delete Me',
            'nama_pic' => 'PIC',
            'telepon' => '08123',
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $this->actingAs($this->viewer)
            ->deleteJson('/api/v1/suppliers/'.$supplier->ulid)
            ->assertForbidden();
    }

    public function test_metode_pembayaran_export_forbidden_without_view(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->get('/api/v1/metode-pembayarans/export')
            ->assertForbidden();
    }

    public function test_metode_pembayaran_export_ok_with_view(): void
    {
        MasterMetodePembayaran::create([
            'kode_pembayaran' => 'TUNAI',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'jenis' => null,
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        $this->actingAs($this->viewer)
            ->get('/api/v1/metode-pembayarans/export')
            ->assertOk();
    }

    public function test_metode_pembayaran_create_forbidden_without_create(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/metode-pembayarans', [
                'kode_pembayaran' => 'QRIS',
                'nama_pembayaran' => 'QRIS',
                'metode' => 'non_tunai',
                'jenis' => 'qris',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_metode_pembayaran_list_only_returns_active(): void
    {
        MasterMetodePembayaran::create([
            'kode_pembayaran' => 'ACT',
            'nama_pembayaran' => 'Active',
            'metode' => 'tunai',
            'jenis' => null,
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->viewer->id,
        ]);

        MasterMetodePembayaran::create([
            'kode_pembayaran' => 'INA',
            'nama_pembayaran' => 'Inactive',
            'metode' => 'tunai',
            'jenis' => null,
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'inactive',
            'created_by' => $this->viewer->id,
        ]);

        $response = $this->actingAs($this->viewer)
            ->getJson('/api/v1/metode-pembayarans/list')
            ->assertOk();

        $kodes = collect($response->json('data.metode_pembayarans'))->pluck('kode_pembayaran')->all();
        $this->assertContains('ACT', $kodes);
        $this->assertNotContains('INA', $kodes);
    }
}
