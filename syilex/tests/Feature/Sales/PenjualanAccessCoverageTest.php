<?php

namespace Tests\Feature\Sales;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Full HTTP 403 matrix — setiap endpoint cluster Penjualan Backoffice.
 */
class PenjualanAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    private string $ulid;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ulid = (string) Str::ulid();

        foreach ([
            'sales.view', 'sales.create', 'sales.update',
            'sales.delete', 'sales.approve', 'sales.void',
            'retur-jual.view', 'retur-jual.create', 'retur-jual.update',
            'retur-jual.delete', 'retur-jual.lock', 'retur-jual.approve',
            'piutang.view', 'piutang.view_nominal',
            'pembayaran-piutang.view', 'pembayaran-piutang.create',
            'pembayaran-piutang.update', 'pembayaran-piutang.delete',
            'pembayaran-piutang.complete',
            'deposit-customer.view', 'deposit-customer.create',
            'deposit-customer.update', 'deposit-customer.delete',
            'laporan.export', 'stok.view_hpp',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }
    }

    private function asUser(array $perms = []): User
    {
        $user = User::factory()->create();
        if ($perms !== []) {
            $user->givePermissionTo($perms);
        }
        $this->actingAs($user);

        return $user;
    }

    // --- Sales ---

    public function test_sales_index_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/sales')->assertForbidden();
    }

    public function test_sales_show_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/sales/'.$this->ulid)->assertForbidden();
    }

    public function test_sales_store_forbidden_without_create(): void
    {
        $this->asUser(['sales.view']);
        $this->postJson('/api/v1/sales', [])->assertForbidden();
    }

    public function test_sales_update_forbidden_without_update(): void
    {
        $this->asUser(['sales.view']);
        $this->putJson('/api/v1/sales/'.$this->ulid, [])->assertForbidden();
    }

    public function test_sales_destroy_forbidden_without_delete(): void
    {
        $this->asUser(['sales.view']);
        $this->deleteJson('/api/v1/sales/'.$this->ulid)->assertForbidden();
    }

    public function test_sales_approve_forbidden_without_approve(): void
    {
        $this->asUser(['sales.view']);
        $this->postJson('/api/v1/sales/'.$this->ulid.'/approve')->assertForbidden();
    }

    public function test_sales_void_forbidden_without_void(): void
    {
        $this->asUser(['sales.view']);
        $this->postJson('/api/v1/sales/'.$this->ulid.'/void', ['reason' => 'x'])->assertForbidden();
    }

    public function test_sales_products_forbidden_without_create_or_update(): void
    {
        $this->asUser(['sales.view']);
        $this->getJson('/api/v1/sales/products')->assertForbidden();
    }

    public function test_sales_calculate_forbidden_without_create_or_update(): void
    {
        $this->asUser(['sales.view']);
        $this->postJson('/api/v1/sales/calculate', [])->assertForbidden();
    }

    public function test_sales_tax_settings_forbidden_without_create_or_update(): void
    {
        $this->asUser(['sales.view']);
        $this->getJson('/api/v1/sales/tax-settings')->assertForbidden();
    }

    // --- Retur jual ---

    public function test_retur_index_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/sales-returns')->assertForbidden();
    }

    public function test_retur_show_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/sales-returns/'.$this->ulid)->assertForbidden();
    }

    public function test_retur_store_forbidden_without_create(): void
    {
        $this->asUser(['retur-jual.view']);
        $this->postJson('/api/v1/sales-returns', [])->assertForbidden();
    }

    public function test_retur_update_forbidden_without_update(): void
    {
        $this->asUser(['retur-jual.view']);
        $this->putJson('/api/v1/sales-returns/'.$this->ulid, [])->assertForbidden();
    }

    public function test_retur_destroy_forbidden_without_delete(): void
    {
        $this->asUser(['retur-jual.view']);
        $this->deleteJson('/api/v1/sales-returns/'.$this->ulid)->assertForbidden();
    }

    public function test_retur_lock_forbidden_without_lock(): void
    {
        $this->asUser(['retur-jual.view']);
        $this->postJson('/api/v1/sales-returns/'.$this->ulid.'/lock')->assertForbidden();
    }

    public function test_retur_approve_forbidden_without_approve(): void
    {
        $this->asUser(['retur-jual.view']);
        $this->postJson('/api/v1/sales-returns/'.$this->ulid.'/approve', ['nilai_diakui' => 0])->assertForbidden();
    }

    public function test_retur_returnable_sales_forbidden_without_create_or_update(): void
    {
        $this->asUser(['retur-jual.view']);
        $this->getJson('/api/v1/sales-returns/returnable-sales')->assertForbidden();
    }

    // --- Piutang ---

    public function test_piutang_index_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/customer-piutangs')->assertForbidden();
    }

    public function test_piutang_show_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/customer-piutangs/'.$this->ulid)->assertForbidden();
    }

    public function test_piutang_summary_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/customer-piutangs/summary')->assertForbidden();
    }

    public function test_piutang_aging_forbidden_without_view_nominal(): void
    {
        $this->asUser(['piutang.view']);
        $this->getJson('/api/v1/customer-piutangs/aging-summary')->assertForbidden();
    }

    public function test_piutang_export_forbidden_without_view(): void
    {
        $this->asUser(['laporan.export']);
        $this->getJson('/api/v1/customer-piutangs/export')->assertForbidden();
    }

    // --- Pembayaran piutang ---

    public function test_pembayaran_index_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/pembayaran-piutangs')->assertForbidden();
    }

    public function test_pembayaran_show_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/pembayaran-piutangs/'.$this->ulid)->assertForbidden();
    }

    public function test_pembayaran_store_forbidden_without_create(): void
    {
        $this->asUser(['pembayaran-piutang.view']);
        $this->postJson('/api/v1/pembayaran-piutangs', [])->assertForbidden();
    }

    public function test_pembayaran_update_forbidden_without_update(): void
    {
        $this->asUser(['pembayaran-piutang.view']);
        $this->putJson('/api/v1/pembayaran-piutangs/'.$this->ulid, [])->assertForbidden();
    }

    public function test_pembayaran_destroy_forbidden_without_delete(): void
    {
        $this->asUser(['pembayaran-piutang.view']);
        $this->deleteJson('/api/v1/pembayaran-piutangs/'.$this->ulid)->assertForbidden();
    }

    public function test_pembayaran_complete_forbidden_without_complete(): void
    {
        $this->asUser(['pembayaran-piutang.view']);
        $this->postJson('/api/v1/pembayaran-piutangs/'.$this->ulid.'/complete')->assertForbidden();
    }

    public function test_pembayaran_outstanding_forbidden_without_create_or_update(): void
    {
        $this->asUser(['pembayaran-piutang.view']);
        $this->getJson('/api/v1/pembayaran-piutangs/outstanding-piutangs?customer_id=1')->assertForbidden();
    }

    public function test_pembayaran_available_deposits_forbidden_without_create_or_update(): void
    {
        $this->asUser(['pembayaran-piutang.view']);
        $this->getJson('/api/v1/pembayaran-piutangs/available-deposits?customer_id=1')->assertForbidden();
    }

    // --- Deposit ---

    public function test_deposit_index_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/customer-deposits')->assertForbidden();
    }

    public function test_deposit_show_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/customer-deposits/'.$this->ulid)->assertForbidden();
    }

    public function test_deposit_summary_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/customer-deposits/summary')->assertForbidden();
    }

    public function test_deposit_usage_forbidden_without_view(): void
    {
        $this->asUser();
        $this->getJson('/api/v1/customer-deposits/'.$this->ulid.'/usage')->assertForbidden();
    }

    public function test_deposit_store_forbidden_without_create(): void
    {
        $this->asUser(['deposit-customer.view']);
        $this->postJson('/api/v1/customer-deposits', [])->assertForbidden();
    }

    public function test_deposit_update_forbidden_without_update(): void
    {
        $this->asUser(['deposit-customer.view']);
        $this->putJson('/api/v1/customer-deposits/'.$this->ulid, [])->assertForbidden();
    }

    public function test_deposit_destroy_forbidden_without_delete(): void
    {
        $this->asUser(['deposit-customer.view']);
        $this->deleteJson('/api/v1/customer-deposits/'.$this->ulid)->assertForbidden();
    }

    public function test_deposit_export_forbidden_without_view_or_export_or_nominal(): void
    {
        $this->asUser(['deposit-customer.view', 'laporan.export']);
        $this->getJson('/api/v1/customer-deposits/export')->assertForbidden();
    }
}
