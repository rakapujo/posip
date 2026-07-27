<?php

namespace Tests\Feature\Pengaturan;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Fase 1 — User Management CRUD via API.
 */
class UserCrudTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['user.view', 'user.create', 'user.update', 'user.delete', 'pos.access'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::findOrCreate('kasir', 'web');

        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['user.view', 'user.create', 'user.update', 'user.delete']);
    }

    public function test_index_forbidden_without_user_view(): void
    {
        $other = User::factory()->create();

        $this->actingAs($other)
            ->getJson('/api/v1/users')
            ->assertForbidden();
    }

    public function test_create_forbidden_without_user_create(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('user.view');

        $this->actingAs($viewer)
            ->postJson('/api/v1/users', [
                'name' => 'Denied User',
                'email' => 'denied@test.com',
                'password' => 'password1',
                'pin' => '123456',
                'phone' => '08123456789',
                'role' => 'kasir',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_user_crud_lifecycle_via_api(): void
    {
        $this->actingAs($this->user)
            ->postJson('/api/v1/users', [
                'name' => 'Kasir Baru',
                'email' => 'kasirbaru@test.com',
                'password' => 'password1',
                'pin' => '123456',
                'phone' => '08123456789',
                'role' => 'kasir',
                'status' => 'active',
            ])
            ->assertCreated()
            ->assertJsonPath('data.user.email', 'kasirbaru@test.com')
            ->assertJsonPath('data.user.roles.0.name', 'kasir');

        $target = User::where('email', 'kasirbaru@test.com')->first();
        $ulid = $target->ulid;

        $this->actingAs($this->user)
            ->getJson('/api/v1/users')
            ->assertOk()
            ->assertJsonPath('data.pagination.total', 2); // includes $this->user

        $this->actingAs($this->user)
            ->getJson("/api/v1/users/{$ulid}")
            ->assertOk()
            ->assertJsonPath('data.user.email', 'kasirbaru@test.com');

        $this->actingAs($this->user)
            ->putJson("/api/v1/users/{$ulid}", [
                'name' => 'Kasir Updated',
                'email' => 'kasirbaru@test.com',
                'phone' => '08123456789',
                'role' => 'kasir',
                'status' => 'active',
            ])
            ->assertOk()
            ->assertJsonPath('data.user.name', 'Kasir Updated');

        $this->actingAs($this->user)
            ->patchJson("/api/v1/users/{$ulid}/toggle-status")
            ->assertOk()
            ->assertJsonPath('data.user.status', 'inactive');

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/users/{$ulid}")
            ->assertOk();

        $this->assertSoftDeleted('users', ['ulid' => $ulid]);
    }

    public function test_cannot_delete_self(): void
    {
        $this->actingAs($this->user)
            ->deleteJson("/api/v1/users/{$this->user->ulid}")
            ->assertStatus(400);
    }

    public function test_cannot_deactivate_self(): void
    {
        $this->actingAs($this->user)
            ->patchJson("/api/v1/users/{$this->user->ulid}/toggle-status")
            ->assertStatus(400);
    }

    public function test_update_forbidden_without_user_update(): void
    {
        $target = User::factory()->create();
        $target->assignRole('kasir');

        $viewer = User::factory()->create();
        $viewer->givePermissionTo('user.view');

        $this->actingAs($viewer)
            ->putJson("/api/v1/users/{$target->ulid}", [
                'name' => 'Should Not Update',
                'email' => $target->email,
                'phone' => '08123456789',
                'role' => 'kasir',
                'status' => 'active',
            ])
            ->assertForbidden();
    }

    public function test_delete_forbidden_without_user_delete(): void
    {
        $target = User::factory()->create();
        $target->assignRole('kasir');

        $editor = User::factory()->create();
        $editor->givePermissionTo(['user.view', 'user.update']);

        $this->actingAs($editor)
            ->deleteJson("/api/v1/users/{$target->ulid}")
            ->assertForbidden();
    }

    public function test_delete_blocked_when_user_has_transaction_records(): void
    {
        $target = User::factory()->create();
        $target->assignRole('kasir');

        $warehouse = \App\Models\MasterWarehouse::factory()->create(['created_by' => $target->id]);
        $supplierId = \Illuminate\Support\Facades\DB::table('master_supplier')->insertGetId([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'kode_supplier' => 'SUP-USR-01',
            'nama_supplier' => 'Supplier User Test',
            'nama_pic' => 'PIC',
            'telepon' => '08000',
            'tempo_default' => 30,
            'status' => 'active',
            'created_by' => $target->id,
            'updated_by' => $target->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \Illuminate\Support\Facades\DB::table('doc_purchase_order')->insert([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'nomor_dokumen' => 'PO-USER-01',
            'tanggal_po' => now()->toDateString(),
            'supplier_id' => $supplierId,
            'warehouse_id' => $warehouse->id,
            'status' => 'draft',
            'created_by' => $target->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/users/{$target->ulid}")
            ->assertStatus(422);
    }

    public function test_cannot_change_own_role(): void
    {
        Role::findOrCreate('admin', 'web');
        $this->user->assignRole('kasir');

        $this->actingAs($this->user)
            ->putJson("/api/v1/users/{$this->user->ulid}", [
                'name' => $this->user->name,
                'email' => $this->user->email,
                'phone' => '08123456789',
                'role' => 'admin',
                'status' => 'active',
            ])
            ->assertStatus(400)
            ->assertJsonFragment(['message' => 'Tidak dapat mengubah role akun sendiri']);
    }

    public function test_cannot_demote_last_of_two_super_admins_then_block_final(): void
    {
        Role::findOrCreate('super-admin', 'web');

        $sa1 = User::factory()->create(['status' => 'active', 'name' => 'SA One']);
        $sa1->assignRole('super-admin');
        $sa1->givePermissionTo(Permission::all());

        $sa2 = User::factory()->create(['status' => 'active', 'name' => 'SA Two']);
        $sa2->assignRole('super-admin');
        $sa2->givePermissionTo(Permission::all());

        // Demote sa2 while sa1 remains → OK
        $this->actingAs($sa1)
            ->putJson("/api/v1/users/{$sa2->ulid}", [
                'name' => $sa2->name,
                'email' => $sa2->email,
                'phone' => '08111111111',
                'role' => 'kasir',
                'status' => 'active',
            ])
            ->assertOk();

        // Deactivate last active SA via update (self) → blocked by self-deactivate
        $this->actingAs($sa1)
            ->putJson("/api/v1/users/{$sa1->ulid}", [
                'name' => $sa1->name,
                'email' => $sa1->email,
                'phone' => '08222222222',
                'role' => 'super-admin',
                'status' => 'inactive',
            ])
            ->assertStatus(400);

        // Demote last active SA via update (self role) → blocked
        $this->actingAs($sa1)
            ->putJson("/api/v1/users/{$sa1->ulid}", [
                'name' => $sa1->name,
                'email' => $sa1->email,
                'phone' => '08222222222',
                'role' => 'kasir',
                'status' => 'active',
            ])
            ->assertStatus(400);
    }

    public function test_non_sa_cannot_demote_super_admin(): void
    {
        Role::findOrCreate('super-admin', 'web');
        $sa = User::factory()->create(['status' => 'active']);
        $sa->assignRole('super-admin');

        $admin = User::factory()->create(['status' => 'active']);
        $admin->givePermissionTo(['user.view', 'user.update']);
        $admin->assignRole('kasir');

        $this->actingAs($admin)
            ->putJson("/api/v1/users/{$sa->ulid}", [
                'name' => $sa->name,
                'email' => $sa->email,
                'phone' => '08123456789',
                'role' => 'kasir',
                'status' => 'active',
            ])
            ->assertStatus(403);
    }

    public function test_inactive_user_is_rejected_by_middleware(): void
    {
        $inactive = User::factory()->create(['status' => 'inactive']);
        $inactive->givePermissionTo('user.view');

        $this->actingAs($inactive)
            ->getJson('/api/v1/users')
            ->assertForbidden()
            ->assertJsonFragment(['message' => 'Akun Anda tidak aktif. Silakan hubungi administrator.']);
    }

    public function test_destroy_releases_pos_occupancy(): void
    {
        $target = User::factory()->create(['status' => 'active']);
        $target->assignRole('kasir');
        Role::findByName('kasir', 'web')->givePermissionTo('pos.access');

        $warehouse = \App\Models\MasterWarehouse::factory()->create(['created_by' => $this->user->id]);
        $cash = \App\Models\MasterMetodePembayaran::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'kode_pembayaran' => 'CASH-U',
            'nama_pembayaran' => 'Tunai',
            'metode' => 'tunai',
            'jenis' => null,
            'biaya_tambahan_tipe' => 'none',
            'biaya_tambahan_nilai' => 0,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        $terminal = \App\Models\MasterPosTerminal::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'kode_terminal' => 'TRM-U',
            'nama_terminal' => 'Kasir User',
            'warehouse_id' => $warehouse->id,
            'default_metode_pembayaran_id' => $cash->id,
            'active_user_id' => $target->id,
            'status' => 'active',
            'created_by' => $this->user->id,
        ]);

        \Illuminate\Support\Facades\DB::table('pos_terminal_users')->insert([
            'terminal_id' => $terminal->id,
            'user_id' => $target->id,
            'created_at' => now(),
        ]);

        $shift = \App\Models\PosTerminalShift::create([
            'ulid' => (string) \Illuminate\Support\Str::ulid(),
            'terminal_id' => $terminal->id,
            'user_id' => $target->id,
            'started_at' => now(),
        ]);

        $this->actingAs($this->user)
            ->deleteJson("/api/v1/users/{$target->ulid}")
            ->assertOk();

        $this->assertNull($terminal->fresh()->active_user_id);
        $this->assertNotNull($shift->fresh()->ended_at);
        $this->assertDatabaseMissing('pos_terminal_users', [
            'terminal_id' => $terminal->id,
            'user_id' => $target->id,
        ]);
    }

    public function test_users_list_rejects_non_whitelisted_permission_filter(): void
    {
        Permission::firstOrCreate(['name' => 'settings.reset', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'terminal.edit', 'guard_name' => 'web']);

        $actor = User::factory()->create(['status' => 'active']);
        $actor->givePermissionTo(['terminal.edit', 'settings.reset']);

        $this->actingAs($actor)
            ->getJson('/api/v1/users/list?permission=settings.reset')
            ->assertStatus(422);
    }
}
