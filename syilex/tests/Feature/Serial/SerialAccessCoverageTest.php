<?php

namespace Tests\Feature\Serial;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Permission matrix untuk cluster serial (elektronik).
 */
class SerialAccessCoverageTest extends TestCase
{
    use RefreshDatabase;

    protected User $viewer;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'serial-intake.view', 'serial-intake.view_harga', 'serial-intake.create', 'serial-intake.update',
            'serial-change.view', 'serial-change.create',
            'serial-hpp.view', 'serial-hpp.create',
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->viewer = User::factory()->create();
    }

    public function test_serial_intake_index_forbidden_without_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/serial-intakes')
            ->assertForbidden();
    }

    public function test_serial_intake_index_ok_with_view(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('serial-intake.view');

        $this->actingAs($user)
            ->getJson('/api/v1/serial-intakes')
            ->assertOk();
    }

    public function test_serial_intake_create_forbidden_without_create(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('serial-intake.view');

        $this->actingAs($user)
            ->postJson('/api/v1/serial-intakes', [])
            ->assertForbidden();
    }

    public function test_serial_change_index_forbidden_without_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/serial-changes')
            ->assertForbidden();
    }

    public function test_serial_units_index_forbidden_without_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/serial-units')
            ->assertForbidden();
    }

    public function test_serial_units_index_ok_with_serial_intake_view(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('serial-intake.view');

        $this->actingAs($user)
            ->getJson('/api/v1/serial-units')
            ->assertOk();
    }

    public function test_serial_hpp_corrections_index_forbidden_without_view(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/serial-hpp-corrections')
            ->assertForbidden();
    }

    public function test_serial_hpp_correction_create_forbidden_without_create(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('serial-hpp.view');

        $this->actingAs($user)
            ->postJson('/api/v1/serial-hpp-corrections', [])
            ->assertForbidden();
    }

    public function test_serial_change_show_returns_not_found_for_unknown_ulid(): void
    {
        $this->actingAs($this->viewer)
            ->getJson('/api/v1/serial-changes/'.Str::ulid())
            ->assertNotFound();
    }
}
