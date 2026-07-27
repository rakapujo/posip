<?php

namespace Tests\Feature\Pos;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Fase 3 — cluster POS: permission matrix HTTP.
 */
class PosAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['pos.access', 'pos.void', 'pos.retur', 'terminal.view'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->viewer = User::factory()->create();
    }

    public function test_active_terminal_forbidden_without_pos_access(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/pos/active-terminal')
            ->assertForbidden();
    }

    public function test_products_forbidden_without_pos_access(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/pos/products')
            ->assertForbidden();
    }

    public function test_checkout_forbidden_without_pos_access(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/pos/checkout', [], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])
            ->assertForbidden();
    }

    public function test_void_forbidden_without_pos_void_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('pos.access');

        $this->actingAs($user)
            ->postJson('/api/v1/pos/sales/'.Str::ulid().'/void', [])
            ->assertForbidden();
    }

    public function test_email_receipt_forbidden_without_pos_access(): void
    {
        $this->actingAs($this->viewer)
            ->postJson('/api/v1/pos/sales/'.Str::ulid().'/email-receipt')
            ->assertForbidden();
    }

    public function test_returns_index_forbidden_without_pos_retur(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('pos.access');

        $this->actingAs($user)
            ->getJson('/api/v1/pos/returns?shift_id=1')
            ->assertForbidden();
    }

    public function test_returns_store_forbidden_without_pos_retur(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('pos.access');

        $this->actingAs($user)
            ->postJson('/api/v1/pos/returns', [])
            ->assertForbidden();
    }

    public function test_cash_index_forbidden_without_pos_access(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/pos/cash?shift_id=1')
            ->assertForbidden();
    }

    public function test_shift_report_forbidden_without_terminal_view_on_closed_shift(): void
    {
        $shift = \App\Models\PosTerminalShift::create([
            'ulid' => (string) Str::ulid(),
            'terminal_id' => \App\Models\MasterPosTerminal::create([
                'ulid' => (string) Str::ulid(),
                'kode_terminal' => 'TRM-COV',
                'nama_terminal' => 'Coverage',
                'warehouse_id' => \App\Models\MasterWarehouse::factory()->create()->id,
                'status' => 'active',
            ])->id,
            'user_id' => $this->viewer->id,
            'started_at' => now()->subHour(),
            'ended_at' => now(),
        ]);

        $this->actingAs($this->viewer)
            ->getJson('/api/v1/pos/shift-report/'.$shift->ulid)
            ->assertForbidden();
    }

    public function test_shifts_index_forbidden_without_terminal_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/shifts')
            ->assertForbidden();
    }
}
